<?php
namespace RSWI\Sync;

use RSWI\API\Client;
use RSWI\Helpers;
use RSWI\Admin\Settings;

if ( ! defined('ABSPATH') ) exit;

/**
 * WooCommerce → Square sync (Products)
 * - Safe-by-default: update-only mode avoids creating duplicates
 * - Always anchor by SKU (Square is source of truth)
 * - When updating existing VARIATION: DO NOT send item_id (immutable)
 * - Avoids touching ITEM unless creation is explicitly allowed
 * - Handles stale IDs and re-anchors by SKU once per run
 */
class WC_To_Square {
    const META_ITEM_ID = '_rswi_square_item_id'; // post meta
    const META_VAR_ID  = '_rswi_square_var_id';  // post meta
    const META_CAT_ID  = '_rswi_square_cat_id';  // term meta

    private static function update_only(): bool {
        return (bool) get_option('rswi_w2s_update_only', 1);
    }

    public static function init(){
        add_action('admin_post_rswi_sync_w2s', [__CLASS__,'handle_manual']);
        add_action('rswi_cron_w2s',           [__CLASS__,'cron']);
        add_action('admin_post_rswi_detect_sku_dupes', [__CLASS__,'detect_sku_dupes']);
    }

    public static function enabled(){
        return (bool) get_option(Settings::OPT_ENABLE_W2S,false);
    }

    public static function handle_manual(){
        check_admin_referer('rswi_sync_w2s');
        $res = self::sync_all();
        self::redirect($res);
    }

    public static function cron(){
        if (!self::enabled()) return;
        self::sync_all();
    }

    private static function redirect(array $res){
        $args = [
            'page' => Settings::PAGE,
            'rswi_w2s_synced' => 1,
            'updated' => intval($res['updated'] ?? 0),
            'skipped' => intval($res['skipped'] ?? 0),
            'errors'  => intval($res['errors'] ?? 0),
        ];
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    // ---------- Square helpers ----------

    private static function get_item_version(Client $api, string $item_id): array {
        list($code,$resp,$err) = $api->retrieve_object($item_id, false);
        if ($code === 200 && isset($resp['object']['version'])) {
            return ['version' => intval($resp['object']['version'])];
        }
        if ($code === 404) return ['not_found'=>true];
        Helpers::log(['retrieve_item_failed'=>['id'=>$item_id,'code'=>$code,'body'=>$resp,'err'=>$err]], 'warning');
        return [];
    }

    /**
     * Returns ['version'=>int,'parent_item_id'=>string] or ['not_found'=>true] or []
     */
    private static function get_variation_info(Client $api, string $var_id): array {
        list($code,$resp,$err) = $api->retrieve_object($var_id, false);
        if ($code === 200 && !empty($resp['object'])) {
            $obj = $resp['object'];
            $ver = isset($obj['version']) ? intval($obj['version']) : null;
            $parent = $obj['item_variation_data']['item_id'] ?? null;
            return ['version'=>$ver, 'parent_item_id'=>$parent];
        }
        if ($code === 404) return ['not_found'=>true];
        Helpers::log(['retrieve_variation_failed'=>['id'=>$var_id,'code'=>$code,'body'=>$resp,'err'=>$err]], 'warning');
        return [];
    }

    /**
     * Strong anchor by SKU — returns the "best" match:
     * - Prefer variation whose parent equals $prefer_item_id
     * - Otherwise the one with highest version
     */
    private static function find_square_ids_by_sku(Client $api, string $sku, string $prefer_item_id = ''): array {
        if ($sku === '') return [null, null];
        $body = [
            'object_types' => ['ITEM_VARIATION'],
            'include_related_objects' => true,
            'query' => [
                'exact_query' => [
                    'attribute_name' => 'sku',
                    'attribute_value' => $sku,
                ],
            ],
        ];
        list($code,$resp,$err) = $api->search_catalog($body);
        if ($code !== 200 || empty($resp['objects'])) return [null, null];

        $best = null; $bestVer = -1;
        foreach ($resp['objects'] as $var) {
            $vid = $var['id'] ?? null;
            $iid = $var['item_variation_data']['item_id'] ?? null;
            $ver = isset($var['version']) ? intval($var['version']) : 0;
            if ($prefer_item_id && $iid === $prefer_item_id) {
                return [$iid, $vid];
            }
            if ($ver > $bestVer) { $bestVer = $ver; $best = [$iid,$vid]; }
        }
        return $best ?: [null,null];
    }

    private static function sanitize_gtin($gtin){
        $g = preg_replace('~\D+~','', (string)$gtin);
        if ($g === '') return null;
        $len = strlen($g);
        if (in_array($len, [8,12,13,14], true)) return $g;
        return null;
    }

    // ---------- Main flows ----------

    public static function sync_all(): array {
        $updated = $skipped = $errors = 0;
        $api = new Client();

        $ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ]);

        if (empty($ids)) return compact('updated','skipped','errors');

        foreach ($ids as $pid) {
            try {
                $ok = self::sync_product($api, $pid);
                if ($ok === true) $updated++; else $skipped++;
            } catch (\Throwable $e) {
                $errors++;
                Helpers::log(['product'=>$pid, 'exception'=>$e->getMessage(), 'trace'=>$e->getTraceAsString()], 'error');
            }
        }

        return compact('updated','skipped','errors');
    }

    public static function detect_sku_dupes(){
        check_admin_referer('rswi_detect_sku_dupes');
        $api = new Client();

        $ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ]);
        $seen = [];
        foreach ($ids as $pid) {
            $sku = (string) get_post_meta($pid, '_sku', true);
            if (!$sku) continue;
            $pref_item = (string) get_post_meta($pid, self::META_ITEM_ID, true);
            list($item,$var) = self::find_square_ids_by_sku($api, $sku, $pref_item);
            $seen[$sku][] = ['pid'=>$pid, 'item'=>$item, 'var'=>$var];
        }
        Helpers::log(['dupe_scan'=>$seen],'info');
        wp_safe_redirect(add_query_arg(['page'=>Settings::PAGE, 'rswi_dupe_scan'=>1], admin_url('admin.php')));
        exit;
    }

    private static function sync_product(Client $api, int $product_id): bool {
        $product = wc_get_product($product_id);
        if (!$product) return false;

        $title = wp_strip_all_tags($product->get_name());
        $desc  = wp_strip_all_tags($product->get_short_description() ?: $product->get_description());
        $sku   = (string) $product->get_sku();
        $price = (string) $product->get_price();

        if ($sku === '') {
            Helpers::log(['pid'=>$product_id,'msg'=>'Skipped: empty SKU'], 'warning');
            return false;
        }
        if ($price === '' || floatval($price) < 0) {
            Helpers::log(['pid'=>$product_id,'msg'=>'Skipped: invalid price'], 'warning');
            return false;
        }

        $currency = get_woocommerce_currency();
        $amount   = Helpers::money_to_cents($price);

        $gtin_meta = get_option(Settings::OPT_GTIN_META, '_global_unique_id');
        $gtin      = self::sanitize_gtin(get_post_meta($product_id, $gtin_meta, true));

        // --- Category (используем ТОЛЬКО при создании) ---
        $cat_term_id    = \RSWI\Helpers::get_primary_category_id($product);
        $cat_square_id  = '';
        $cat_temp_id    = null;
        $cat_name       = '';

        if ($cat_term_id) {
            $term = get_term($cat_term_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $cat_name = $term->name;
                $cat_square_id = (string) get_term_meta($cat_term_id, self::META_CAT_ID, true);
            }
        }

        // --- Existing mappings from meta ---
        $meta_item_id = (string) get_post_meta($product_id, self::META_ITEM_ID, true);
        $meta_var_id  = (string) get_post_meta($product_id, self::META_VAR_ID, true);

        // --- Anchor by SKU first (prevents duplicates) ---
        list($found_item, $found_var) = self::find_square_ids_by_sku($api, $sku, $meta_item_id);

        $item_id = $found_item ?: $meta_item_id;
        $var_id  = $found_var  ?: $meta_var_id;

        // save corrected mappings, if any
        if ($found_item && $found_item !== $meta_item_id) {
            update_post_meta($product_id, self::META_ITEM_ID, $found_item);
        }
        if ($found_var && $found_var !== $meta_var_id) {
            update_post_meta($product_id, self::META_VAR_ID, $found_var);
        }

        $is_new_item = empty($item_id);
        $is_new_var  = empty($var_id);

        $item_version = null;
        $var_version  = null;

        // If VAR exists — realign parent item strictly by Square
        if (!$is_new_var) {
            $v = self::get_variation_info($api, $var_id);
            if (!empty($v['not_found'])) {
                delete_post_meta($product_id, self::META_VAR_ID);
                $var_id = '';
                $is_new_var = true;
            } else {
                if (!empty($v['version'])) $var_version = $v['version'];
                $parent_item = $v['parent_item_id'] ?? null;
                if ($parent_item) {
                    if ($parent_item !== $item_id) {
                        $item_id = $parent_item;
                        update_post_meta($product_id, self::META_ITEM_ID, $item_id);
                        $is_new_item = false;
                    }
                }
            }
        }

        // If ITEM exists — get version
        if (!$is_new_item && $item_id) {
            $i = self::get_item_version($api, $item_id);
            if (!empty($i['not_found'])) {
                if ($is_new_var) {
                    delete_post_meta($product_id, self::META_ITEM_ID);
                    $item_id = '';
                    $is_new_item = true;
                } // else: parent already set from VAR
            } elseif (!empty($i['version'])) {
                $item_version = $i['version'];
            }
        }

        // If new ITEM — force new VAR too (cannot attach old VAR to new ITEM)
        if ($is_new_item && !$is_new_var) {
            delete_post_meta($product_id, self::META_VAR_ID);
            $var_id = '';
            $var_version = null;
            $is_new_var = true;
        }

        // UPDATE-ONLY short-circuit: if not found in Square — skip (no creates).
        if (self::update_only() && ($is_new_item || $is_new_var)) {
            Helpers::log(['pid'=>$product_id,'msg'=>'Skip (update_only=true & not found by SKU)','sku'=>$sku], 'info');
            return false;
        }

        // ---------- Build objects ----------
        $objects = [];
        $creating_anything = false;

        // CATEGORY create only if creating ITEM
        if (!$is_new_item && self::update_only()) {
            // do not touch categories on updates in update-only mode
            $cat_name = ''; $cat_square_id = ''; $cat_temp_id = null;
        }

        if ($cat_name && !$cat_square_id && !$is_new_item) {
            // We won't try to set a category that Square likely doesn't know.
            $cat_name = ''; $cat_temp_id = null;
        }

        if ($cat_name && !$cat_square_id && $is_new_item) {
            $cat_temp_id = '#CAT-' . $cat_term_id;
            $objects[] = [
                'type' => 'CATEGORY',
                'id'   => $cat_temp_id,
                'category_data' => ['name' => $cat_name],
            ];
            $creating_anything = true;
        }

        // ITEM (create or update)
        $item_obj_id = $is_new_item ? ('#ITEM-' . $product_id) : $item_id;

        // В update-only режиме мы вообще не трогаем существующий ITEM,
        // чтобы обойти ошибки с кастом-атрибутами.
        if (!$is_new_item && self::update_only()) {
            // noop
        } else {
            $item_data = [
                'name'        => $title,
                'description' => $desc ?: '',
            ];
            if ($cat_name) {
                $item_data['category_id'] = $cat_square_id ?: $cat_temp_id;
            }

            $item_obj = [
                'type'      => 'ITEM',
                'id'        => $item_obj_id,
                'item_data' => $item_data,
            ];
            if ($is_new_item) {
                $item_obj['present_at_all_locations'] = true;
                $creating_anything = true;
            } elseif ($item_version !== null) {
                $item_obj['version'] = $item_version;
            }
            $objects[] = $item_obj;
        }

        // VARIATION (create or update)
        $variation_id = $is_new_var ? ('#VAR-' . $product_id) : $var_id;

        $variation_data = [
            'name'         => 'Default',
            'sku'          => $sku,
            'pricing_type' => 'FIXED_PRICING',
            'price_money'  => [
                'amount'   => $amount,
                'currency' => $currency,
            ],
        ];
        if ($gtin) {
            $variation_data['upc'] = $gtin;
        }

        // ВАЖНО: item_id добавляем ТОЛЬКО если создаём новую вариацию
        if ($is_new_var) {
            $variation_data['item_id'] = $item_obj_id; // can be temp or real
        }

        $variation_obj = [
            'type'                => 'ITEM_VARIATION',
            'id'                  => $variation_id,
            'item_variation_data' => $variation_data,
        ];
        if ($is_new_var) {
            $variation_obj['present_at_all_locations'] = true;
            $creating_anything = true;
        } elseif ($var_version !== null) {
            $variation_obj['version'] = $var_version;
        }

        // Если update-only и вариация существует — шлём ТОЛЬКО вариацию,
        // чтобы не задевать ITEM и его кастом-атрибуты.
        if (!$is_new_var && self::update_only()) {
            $objects = [ $variation_obj ];
        } else {
            $objects[] = $variation_obj;
        }

        // ---------- Upsert ----------
        list($ucode, $body, $uerr) = $api->upsert_catalog($objects);

        // Если словили INVALID_OBJECT, пробуем один раз переякориться по SKU и повторить (без ITEM)
        $retry_once = false;
        if ($ucode !== 200 && !empty($body['errors'])) {
            foreach ($body['errors'] as $e) {
                $detail = $e['detail'] ?? '';
                if (($e['code'] ?? '') === 'INVALID_VALUE' && stripos($detail, 'Invalid Object with Id') !== false) {
                    // сбрасываем локальные мета и переякориваемся
                    delete_post_meta($product_id, self::META_ITEM_ID);
                    delete_post_meta($product_id, self::META_VAR_ID);
                    Helpers::log(['pid'=>$product_id,'action'=>'cleared_stale_meta_ids_after_invalid_object'], 'warning');

                    list($re_item,$re_var) = self::find_square_ids_by_sku($api, $sku);
                    if ($re_var) {
                        // соберём чистый апдейт ТОЛЬКО вариации
                        $v = self::get_variation_info($api, $re_var);
                        $re_ver = !empty($v['version']) ? $v['version'] : null;

                        $vdata = [
                            'name'         => 'Default',
                            'sku'          => $sku,
                            'pricing_type' => 'FIXED_PRICING',
                            'price_money'  => ['amount'=>$amount,'currency'=>$currency],
                        ];
                        if ($gtin) $vdata['upc'] = $gtin;

                        $vobj = [
                            'type'                => 'ITEM_VARIATION',
                            'id'                  => $re_var,
                            'item_variation_data' => $vdata,
                        ];
                        if ($re_ver !== null) $vobj['version'] = $re_ver;

                        $retry_once = true;
                        list($ucode, $body, $uerr) = $api->upsert_catalog([$vobj]);
                    }
                    break;
                }
            }
        }

        if ($ucode !== 200) {
            Helpers::log([
                'pid'     => $product_id,
                'code'    => $ucode,
                'body'    => $body,
                'err'     => $uerr,
                'objects' => $objects,
                'retry'   => $retry_once,
            ], 'error');
            return false;
        }

        if (!empty($body['errors'])) {
            Helpers::log(['pid'=>$product_id,'upsert_errors'=>$body['errors']], 'error');
        }

        // Map temp ids -> real ids (если что-то создавали)
        if (!empty($body['id_mappings'])) {
            foreach ($body['id_mappings'] as $map) {
                $client_id = $map['client_object_id'] ?? '';
                $real_id   = $map['object_id'] ?? '';
                if (!$client_id || !$real_id) continue;

                if (strpos($client_id, '#ITEM-') === 0) {
                    $pid = intval(substr($client_id, 6));
                    update_post_meta($pid, self::META_ITEM_ID, $real_id);
                } elseif (strpos($client_id, '#VAR-') === 0) {
                    $pid = intval(substr($client_id, 5));
                    update_post_meta($pid, self::META_VAR_ID, $real_id);
                } elseif (strpos($client_id, '#CAT-') === 0) {
                    $term_id = intval(substr($client_id, 5));
                    update_term_meta($term_id, self::META_CAT_ID, $real_id);
                }
            }
        }

        return true;
    }
}
