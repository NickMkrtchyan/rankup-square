<?php
/**
 * Plugin Name: RankUP — Square ↔ Woo Integrator (MVP)
 * Description: Two-way sync MVP: Woo → Square (Products), Square → Woo (Products), Woo → Square (Orders). Minimal fields: Title, Price, Category, Tags, SKU, GTIN(UPC/EAN/ISBN).
 * Author: RankUP
 * Version: 0.1.1
 * Requires PHP: 7.4
 * Text Domain: rankup-square-woo
 */

if ( ! defined('ABSPATH') ) exit;

// -----------------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------------
define('RSWI_VERSION', '0.1.1');
define('RSWI_PATH', plugin_dir_path(__FILE__));
define('RSWI_URL',  plugin_dir_url(__FILE__));

// -----------------------------------------------------------------------------
// Autoload (very light)
// -----------------------------------------------------------------------------
spl_autoload_register(function($class){
    if (strpos($class, 'RSWI\\') !== 0) return;
    $rel = strtolower(str_replace(['RSWI\\', '_'], ['', '-'], $class));
    $rel = str_replace('\\', DIRECTORY_SEPARATOR, $rel);
    $file = RSWI_PATH . 'includes/' . $rel . '.php';
    if (file_exists($file)) require_once $file;
});

// Woo required
add_action('plugins_loaded', function(){
    if ( ! class_exists('WooCommerce') ) {
        add_action('admin_notices', function(){
            echo '<div class="notice notice-error"><p><b>RankUP — Square ↔ Woo Integrator:</b> WooCommerce must be active.</p></div>';
        });
        return;
    }

    // Bootstrap
    RSWI\Admin\Settings::init();
    RSWI\Sync\WC_To_Square::init();
    RSWI\Sync\Square_To_WC::init();
    RSWI\Sync\Orders::init();
    RSWI\Admin\Debug::init(); // <-- ДОБАВЛЕНА ЭТА СТРОКА
});

// Activation / Deactivation (cron)
register_activation_hook(__FILE__, function(){
    if ( ! wp_next_scheduled('rswi_cron_w2s') ) wp_schedule_event(time()+60, 'hourly', 'rswi_cron_w2s');
    if ( ! wp_next_scheduled('rswi_cron_s2w') ) wp_schedule_event(time()+120, 'hourly', 'rswi_cron_s2w');
});
register_deactivation_hook(__FILE__, function(){
    wp_clear_scheduled_hook('rswi_cron_w2s');
    wp_clear_scheduled_hook('rswi_cron_s2w');
});
