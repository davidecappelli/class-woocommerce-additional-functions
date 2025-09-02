<?php namespace DavideCappelli\WP_Classic_Parent_Theme\Integrations;

class WoocommerceAdditionalFunctions {
    public static $class_shortname;

    
    
    public static function getAttributesIdsByProducts($product_ids = [], $sep = ' ') : NULL|array {
        global $wpdb;
        if(isset($product_ids) && !empty($product_ids)){  // Selected Products (Published / Not Published | In Stock / Out Of Stock)
            if(filter_var($product_ids,FILTER_VALIDATE_INT)){
                $product_ids                            = (int) $product_ids;
            }
            if(is_int($product_ids) && function_exists('wc_get_product') && function_exists('wc_attribute_taxonomy_id_by_name')){ // Single Product (ID provided)
                self::getAttributesIdsByProducts([(int) $product_ids]); // Recursion
            }elseif(is_string($product_ids)){   // Multiple Products (String of @$sep separated IDs provided)
                return str_contains($product_ids,$sep) ? self::getAttributesIdsByProducts(explode($sep,$product_ids)) : NULL; // Recursion
            }elseif(is_array($product_ids)){    // Multiple Products (Array of IDs provided)
                $wc_product_ids = !empty($product_ids) ? array_filter(array_map('intval', array_map('trim', $product_ids))) : [];
                if(empty($wc_product_ids)) return NULL;
                $statement                              = $wpdb->prepare('SELECT DISTINCT '.$wpdb->prefix.'postmeta.meta_value FROM '.$wpdb->prefix.'posts LEFT JOIN '.$wpdb->prefix.'postmeta ON '.$wpdb->prefix.'posts.ID = '.$wpdb->prefix.'postmeta.post_id WHERE '.$wpdb->prefix.'posts.post_type = "product" AND '.$wpdb->prefix.'posts.ID IN(%s) AND '.$wpdb->prefix.'postmeta.meta_key = "_product_attributes"', implode(',', $wc_product_ids));
            }else{
                return NULL;
            }
        }else{  // All Products (Published / Not Published | In Stock / Out Of Stock)
            $statement                                  = $wpdb->prepare('SELECT DISTINCT '.$wpdb->prefix.'postmeta.meta_value FROM '.$wpdb->prefix.'posts LEFT JOIN '.$wpdb->prefix.'postmeta ON '.$wpdb->prefix.'posts.ID = '.$wpdb->prefix.'postmeta.post_id WHERE '.$wpdb->prefix.'posts.post_type = "%s" AND '.$wpdb->prefix.'postmeta.meta_key = "_product_attributes"', 'product');
        }
        $wc_products_postmeta_attributes                = $wpdb->get_col($statement);
        if(empty($wc_products_postmeta_attributes)) return NULL; // Results are empty
        if(!function_exists('wc_attribute_taxonomy_id_by_name')) return NULL; // Unable to retrieve ID by Attribute Name
        $wc_attributes_taxonomies                       = [];
        foreach($wc_products_postmeta_attributes as $wc_products_postmeta_attributes_row){
            $product_postmeta_attributes                = unserialize($wc_products_postmeta_attributes_row);
            if(is_array($product_postmeta_attributes) && !empty($product_postmeta_attributes)){ // Product has attributes
                foreach($product_postmeta_attributes as $product_postmeta_attribute){
                    $product_postmeta_attribute_name    = isset($product_postmeta_attribute['name']) && !empty(trim($product_postmeta_attribute['name'])) ? trim($product_postmeta_attribute['name']) : NULL;
                    if(isset($product_postmeta_attribute_name) && !in_array($product_postmeta_attribute_name,$wc_attributes_taxonomies)){ // First occurence only
                        $wc_attribute_taxonomy_id       = wc_attribute_taxonomy_id_by_name($product_postmeta_attribute_name); // Getting Attribute Taxonomy ID
                        if(isset($wc_attribute_taxonomy_id) && $wc_attribute_taxonomy_id > 0){ // Attribute Taxonomy exists
                            $wc_attributes_taxonomies[$wc_attribute_taxonomy_id]    = $product_postmeta_attribute_name;
                        }
                    }
                }
            }
        }
        return !empty($wc_attributes_taxonomies) ? array_keys($wc_attributes_taxonomies) : NULL;
    }


    public static function isAttributeTypeName($taxonomy = NULL, $type_name = NULL) : bool {
        if(!isset($taxonomy,$type_name)) return FALSE;
        global $wpdb;
        $taxonomy       = substr(trim($taxonomy), 3 ); // remove the "pa_" prefix
        $type_name      = trim($type_name);
        $attribute_type = $wpdb->get_var($wpdb->prepare('SELECT attribute_type FROM ' . $wpdb->prefix . 'woocommerce_attribute_taxonomies WHERE attribute_name = "%s"',$taxonomy));
        return $attribute_type === $type_name;
    }

    public static function getAttributesTaxonomies($columns = 'all') : NULL|array {
        if(!function_exists('wc_get_attribute_taxonomies')) return NULL;
        $attribute_taxonomies   = [];
        foreach(wc_get_attribute_taxonomies() as $attribute){
            if(isset($attribute->attribute_name)){
                $attribute->taxonomy_name   = 'pa_'.$attribute->attribute_name;
            }
            $attribute_taxonomies[]         = (array) $attribute; // Object to Array Conversion
        }
        if($columns != 'all'){
            $attribute_taxonomies           = array_column($attribute_taxonomies,$columns);
        }
        return !empty($attribute_taxonomies) ? $attribute_taxonomies : NULL;
    }

    public static function getAttributesTaxonomiesNames() : NULL|array {
        $taxonomies_names   = self::getAttributesTaxonomies('taxonomy_name');
        return isset($taxonomies_names) && !empty($taxonomies_names) ? $taxonomies_names : NULL;
    }

    public static function getAttributesForVariations() : NULL|array {
        if(!function_exists('is_product') || !is_product()) return NULL;
        global $product;
        if(!isset($product) || !$product->is_type('variable')) return NULL;

        $product_attributes     = [];
        $woo_product_attributes = $product->get_attributes();
        foreach($woo_product_attributes as $taxonomy_name => $attribute_object){
            $prefix             = chr(0).'*'.chr(0);
            $attribute_object   = (array) $attribute_object;
            if(isset($attribute_object[$prefix.'data']['variation']) && TRUE === $attribute_object[$prefix.'data']['variation']){
                $product_attributes[$taxonomy_name] = [
                    'id'    => $attribute_object[$prefix.'data']['id'],
                    'label' => wc_attribute_label($taxonomy_name),
                    ];
            }

        }
        return !empty($product_attributes) ? $product_attributes : NULL;
    }

    /*** VARIATIONS / STOCK ***/

    public static function getProductVariationsStock($product) : NULL|array {
        if(!isset($product) || $product->is_type('simple')) return NULL;
        $product_attributes = array_keys($product->get_attributes());
        $product_variations = $product->get_available_variations();
        $variations_x_attribute = [];
        /* Available Default Attributes Cycle */
        foreach($product_attributes as $product_attribute){
            /* Cycle All Variations for Each Attribute */
            foreach($product_variations as $variation) {
                if(isset($variation['attributes']['attribute_'.$product_attribute])){ // Exclude Unset Attributes
                    $attribute_is_in_stock  = [];
                    /* Re-cycle All Variations for Each Attribute */
                    foreach($product_variations as $variation_b) {
                        foreach($product_attributes as $product_attributeB){
                            if($product_attributeB != $product_attribute && isset($variation_b['attributes']['attribute_'.$product_attributeB]) && $variation_b['attributes']['attribute_'.$product_attribute] == $variation['attributes']['attribute_'.$product_attribute]){
                                $attribute_is_in_stock[$product_attributeB][$variation_b['attributes']['attribute_'.$product_attributeB]] = $variation_b['is_in_stock'];
                            }
                        }
                    }
                    $variations_x_attribute[$product_attribute][$variation['attributes']['attribute_'.$product_attribute]]= $attribute_is_in_stock;
                }
            }
        }
        ksort($variations_x_attribute);
        return $variations_x_attribute;
    }



    public function __construct(){
        self::$class_shortname  = strtolower((new \ReflectionClass($this))->getShortName());
    }


}

include_once ABSPATH . 'wp-admin/includes/plugin.php';
if(is_plugin_active('woocommerce/woocommerce.php')){    // Auto-Instantiation
    new \DavideCappelli\WP_Classic_Parent_Theme\Integrations\WoocommerceAdditionalFunctions;
}
