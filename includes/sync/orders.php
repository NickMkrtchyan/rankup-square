<?php
namespace RSWI\Sync; use RSWI\API\Client; use RSWI\Admin\Settings; use RSWI\Helpers; if ( ! defined('ABSPATH') ) exit;

class Orders {
    const META_SQUARE_ORDER_ID = '_rswi_square_order_id';

    public static function init(){
        add_action('woocommerce_order_status_processing', [__CLASS__,'push'], 10, 1);
        add_action('woocommerce_order_status_completed',  [__CLASS__,'push'], 10, 1);
    }

    protected static function enabled(){ return (bool) get_option(Settings::OPT_ENABLE_ORD,true); }

    public static function push($order_id){
        if (!self::enabled()) return;
        if (get_post_meta($order_id,self::META_SQUARE_ORDER_ID,true)) return; // already pushed
        $api = new Client(); if(!$api->ready()) return;
        $loc = $api->get_location(); if(!$loc) return;

        $order = wc_get_order($order_id); if(!$order) return;
        $line_items = [];
        foreach($order->get_items() as $item){
            $product = $item->get_product(); if(!$product) continue;
            $name  = $item->get_name();
            $qty   = (int) $item->get_quantity();
            $price = (float) $order->get_item_total($item, false, false); // unit price, excl tax
            $line_items[] = [
                'name' => $name,
                'quantity' => (string) $qty,
                'base_price_money' => [ 'amount'=>Helpers::money_to_cents($price), 'currency'=>get_woocommerce_currency() ],
            ];
        }
        $body = [
            'location_id' => $loc,
            'reference_id'=> (string) $order->get_order_number(),
            'customer_id' => null,
            'line_items'  => $line_items,
            // Taxes/discounts/shipping can be added later (MVP keeps it simple)
        ];
        list($code,$resp,) = $api->create_order($body);
        if ($code===200 && !empty($resp['order']['id'])){
            update_post_meta($order_id,self::META_SQUARE_ORDER_ID,$resp['order']['id']);
            Helpers::log('Order #'.$order_id.' pushed → '.$resp['order']['id']);
        } else {
            Helpers::log(['order_push_failed'=>$resp,'code'=>$code],'error');
        }
    }
}
