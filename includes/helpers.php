<?php
namespace RSWI; if ( ! defined('ABSPATH') ) exit;

class Helpers {
    public static function logger(){
        return function_exists('wc_get_logger') ? wc_get_logger() : null;
    }
    public static function log($msg, $level='info', $context=['source'=>'rswi']){
        $l = self::logger(); if ($l) $l->log($level, is_scalar($msg)?$msg:wp_json_encode($msg), $context); else error_log('[RSWI] '.(is_scalar($msg)?$msg:print_r($msg,true)));
    }
    public static function money_to_cents($price){ return (int) round(floatval($price)*100); }
    public static function cents_to_money($cents){ return number_format( ($cents/100), wc_get_price_decimals(), '.', ''); }
    public static function get_primary_category_id($product){
        $terms = wp_get_post_terms($product->get_id(), 'product_cat');
        return (!is_wp_error($terms) && !empty($terms)) ? $terms[0]->term_id : 0;
    }
}