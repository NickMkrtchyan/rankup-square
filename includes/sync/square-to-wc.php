<?php
namespace RSWI\Sync; use RSWI\API\Client; use RSWI\Helpers; use RSWI\Admin\Settings; if ( ! defined('ABSPATH') ) exit;

class Square_To_WC {
    public static function init(){
        add_action('admin_post_rswi_sync_s2w', [__CLASS__,'handle_manual']);
        add_action('rswi_cron_s2w', [__CLASS__,'cron']);
    }
    public static function enabled(){ return (bool) get_option(Settings::OPT_ENABLE_S2W,false); }
    public static function handle_manual(){ check_admin_referer('rswi_sync_s2w'); $r=self::sync_all(); self::redirect($r); }
    public static function cron(){ if(self::enabled()) self::sync_all(); }
    protected static function redirect($r){ wp_safe_redirect(add_query_arg($r, admin_url('admin.php?page='.Settings::PAGE))); exit; }

    public static function sync_all(){
        $api = new Client(); if(!$api->ready()) return ['updated'=>0,'skipped'=>0,'errors'=>1];
        $updated=$skipped=$errors=0; $cursor=null;
        $gtin_key = get_option(Settings::OPT_GTIN_META,'_global_unique_id');

        do{
            list($code,$body,) = $api->list_variations($cursor);
            if ($code!==200){ $errors++; break; }
            $objects = $body['objects']??[]; $cursor = $body['cursor']??null;
            foreach($objects as $obj){
                if (($obj['type']??'')!=='ITEM_VARIATION') { $skipped++; continue; }
                $v = $obj['item_variation_data']??[];
                $sku = trim((string)($v['sku']??''));
                if ($sku===''){ $skipped++; continue; }
                $upc = trim((string)($v['upc']??''));

                // Get full item (to read name, category, description)
                $item_id = $v['item_id'] ?? null; // many APIs return this; fallback via retrieve with related
                $name = 'Square Product'; $desc=''; $category_name=''; $price='';

                if ($item_id){
                    list($c2,$b2,) = $api->retrieve_object($item_id,false);
                    if ($c2===200){
                        $idata = $b2['object']['item_data']??[];
                        $name = $idata['name']??$name; $desc = $idata['description']??'';
                        if (!empty($idata['category_id'])){ // category name requires one more fetch (optional)
                            list($cc,$bb,) = $api->retrieve_object($idata['category_id'],false);
                            if ($cc===200) $category_name = $bb['object']['category_data']['name']??'';
                        }
                    }
                }
                // Price
                if (!empty($v['price_money']['amount'])) $price = \RSWI\Helpers::cents_to_money((int)$v['price_money']['amount']);

                // Upsert product in Woo by SKU
                $post_id = wc_get_product_id_by_sku($sku);
                if (!$post_id){
                    $p = new \WC_Product_Simple();
                    $p->set_name($name);
                    if ($price!=='') $p->set_regular_price($price);
                    $p->set_sku($sku);
                    $p->set_status('publish');
                    $post_id = $p->save();
                } else {
                    $p = wc_get_product($post_id);
                    $p->set_name($name);
                    if ($price!=='') $p->set_regular_price($price);
                    $p->save();
                }
                // Category (create if not exists)
                if ($category_name){
                    $term = term_exists($category_name,'product_cat'); if (!$term) $term = wp_insert_term($category_name,'product_cat');
                    if (!is_wp_error($term) && !empty($term['term_id'])) wp_set_post_terms($post_id, [$term['term_id']], 'product_cat', false);
                }
                // Tags from description suffix "Tags:"
                if ($desc && strpos($desc,'Tags:')!==false){
                    if (preg_match('/Tags:\s*(.+)$/i', $desc, $m)){ $tags = array_map('trim', explode(',', $m[1])); if ($tags) wp_set_post_terms($post_id,$tags,'product_tag',false); }
                }
                // GTIN
                if ($upc!=='') update_post_meta($post_id,$gtin_key,$upc);
                $updated++;
            }
        } while($cursor);
        return compact('updated','skipped','errors');
    }
}
