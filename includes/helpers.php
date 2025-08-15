<?php
namespace RSWI;

if ( ! defined('ABSPATH') ) exit;

class Helpers {

    public static function logger(){
        return (function_exists('wc_get_logger')) ? wc_get_logger() : null;
    }

    public static function log($msg, $level='info', $context=['source'=>'rswi']){
        $l = self::logger();
        if ($l) {
            $l->log($level, is_scalar($msg) ? (string)$msg : wp_json_encode($msg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), $context);
        } else {
            error_log('[RSWI]['.$level.'] '. (is_scalar($msg) ? (string)$msg : print_r($msg,true)));
        }
    }

    /**
     * Convert Woo price -> money amount integer according to currency decimals
     */
    public static function money_to_cents($price){
        $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
        return (int) round( floatval($price) * pow(10, $decimals) );
    }

    public static function cents_to_money($cents){
        $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
        $val = floatval($cents) / pow(10, $decimals);
        return number_format($val, $decimals, '.', '');
    }

    /**
     * Get primary product_cat term id (first by default)
     */
    public static function get_primary_category_id($product){
        $terms = wp_get_post_terms($product->get_id(), 'product_cat');
        if (is_wp_error($terms) || empty($terms)) return 0;
        // If Yoast/Woo primary category exists, you can resolve it here later; for now take first
        return (int) $terms[0]->term_id;
    }
}
