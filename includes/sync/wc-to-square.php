<?php
namespace RSWI\Sync; use RSWI\API\Client; use RSWI\Helpers; use RSWI\Admin\Settings; if ( ! defined('ABSPATH') ) exit;

class WC_To_Square {
    const META_ITEM_ID = '_rswi_square_item_id';
    const META_VAR_ID  = '_rswi_square_var_id';
    const META_CAT_ID  = '_rswi_square_cat_id';

    public static function init(){
        add_action('admin_post_rswi_sync_w2s', [__CLASS__,'handle_manual']);
        add_action('rswi_cron_w2s', [__CLASS__,'cron']);
    }

    public static function enabled(){ return (bool) get_option(Settings::OPT_ENABLE_W2S,false); }

    public static function handle_manual(){ check_admin_referer('rswi_sync_w2s'); $res=self::sync_all(); self::redirect($res); }
    public static function cron(){ if(self::enabled()) self::sync_all(); }
    protected static function redirect($r){ wp_safe_redirect(add_query_arg($r, admin_url('admin.php?page='.Settings::PAGE))); exit; }

    public static function sync_all(){
        $api = new Client(); if(!$api->ready()) return ['updated'=>0,'skipped'=>0,'errors'=>1];
        
        $args = ['status'=>'publish','type'=>'simple','limit'=>-1,'return'=>'objects']; // MVP: simple products
        $products = wc_get_products($args);
        $updated=$skipped=$errors=0; $batch=[];
        $processed_categories = []; // Array to track processed category IDs

        foreach($products as $product){
            $title = $product->get_name(); if($title===''){ $skipped++; continue; }
            $price = $product->get_price(); if($price===''){ $skipped++; continue; }
            $sku   = $product->get_sku(); if($sku===''){ $skipped++; continue; }
            $gtin  = get_post_meta($product->get_id(), get_option(Settings::OPT_GTIN_META,'_global_unique_id'), true );

            $cat_term_id = Helpers::get_primary_category_id($product);
            $cat_name = $cat_term_id? get_term($cat_term_id)->name : '';
            $cat_id = get_post_meta($product->get_id(), self::META_CAT_ID, true);
            
            // Check if category is valid and not already processed in this batch
            if ($cat_name && !$cat_id && !in_array($cat_term_id, $processed_categories)){
                $cat_temp_id = '#CAT-'.$cat_term_id;
                $category_object = [
                    'type'=>'CATEGORY','id'=>$cat_temp_id,
                    'category_data'=>['name'=>$cat_name]
                ];
                $batch[] = $category_object;
                $processed_categories[] = $cat_term_id; // Mark as processed
            }

            $item_id = get_post_meta($product->get_id(), self::META_ITEM_ID, true);
            $var_id  = get_post_meta($product->get_id(), self::META_VAR_ID, true);
            $desc = wp_strip_all_tags($product->get_short_description() ?: $product->get_description());
            $tags = wp_get_post_terms($product->get_id(), 'product_tag', ['fields'=>'names']);
            if (!empty($tags)) $desc .= "\n\nTags: ".implode(', ', $tags);

            if (!$item_id || !$var_id){
                // Create new
                $tmp_item = '#ITEM-'.$product->get_id();
                $tmp_var  = '#VAR-'.$product->get_id();
                $item = [
                    'type'=>'ITEM','id'=>$tmp_item,
                    'present_at_all_locations' => true,
                    'item_data'=>['name'=>$title, 'description'=>$desc]
                ];
                if ($cat_name) $item['item_data']['category_id'] = $cat_id ?: '#CAT-'.$cat_term_id;

                $variation_data = [
                    'item_id'=>$tmp_item, 'name'=>'Default', 'sku'=>$sku,
                    'pricing_type'=>'FIXED_PRICING',
                    'price_money'=>['amount'=>\RSWI\Helpers::money_to_cents($price),'currency'=>get_woocommerce_currency()],
                ];
                if ($gtin) $variation_data['upc'] = $gtin;

                $variation = ['type'=>'ITEM_VARIATION','id'=>$tmp_var, 'present_at_all_locations' => true, 'item_variation_data'=>$variation_data];
                
                $batch[]=$item; $batch[]=$variation; $updated++;
            } else {
                // Update existing
                list($code,$obj,) = $api->retrieve_object($item_id,true);
                if ($code!==200){ $errors++; continue; }
                $version = isset($obj['object']['version'])?$obj['object']['version']:null;
                $item=[ 'type'=>'ITEM','id'=>$item_id,'version'=>$version,
                    'present_at_all_locations' => true,
                    'item_data'=>['name'=>$title,'description'=>$desc]
                ];
                if ($cat_name) $item['item_data']['category_id']=$cat_id?:null;

                list($code2,$obj2,) = $api->retrieve_object($var_id,false);
                $ver2 = ($code2===200 && isset($obj2['object']['version']))?$obj2['object']['version']:null;
                
                $variation_data=[
                    'item_id'=>$item_id, 'name'=>'Default','sku'=>$sku,
                    'pricing_type'=>'FIXED_PRICING',
                    'price_money'=>['amount'=>\RSWI\Helpers::money_to_cents($price),'currency'=>get_woocommerce_currency()],
                ];
                if ($gtin) $variation_data['upc'] = $gtin;

                $variation=['type'=>'ITEM_VARIATION','id'=>$var_id,'version'=>$ver2, 'present_at_all_locations' => true, 'item_variation_data'=>$variation_data];
                
                $batch[]=$item; $batch[]=$variation; $updated++;
            }
        }
        if (!empty($batch)){
            list($code,$body,$err) = $api->upsert_catalog($batch);
            if ($code===200 && isset($body['id_mappings'])){
                foreach($body['id_mappings'] as $map){
                    $client_id = $map['client_object_id']; $real_id = $map['object_id'];
                    if (strpos($client_id,'#ITEM-')===0){ $pid = intval(substr($client_id,6)); update_post_meta($pid,self::META_ITEM_ID,$real_id); }
                    if (strpos($client_id,'#VAR-')===0){  $pid = intval(substr($client_id,5)); update_post_meta($pid,self::META_VAR_ID,$real_id); }
                    if (strpos($client_id,'#CAT-')===0){  $tid = intval(substr($client_id,5)); }
                }
            } else {
                Helpers::log(['upsert_error'=>$body,'code'=>$code,'err'=>$err],'error');
            }
        }
        return compact('updated','skipped','errors');
    }
}
