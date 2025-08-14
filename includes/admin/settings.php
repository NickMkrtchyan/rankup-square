<?php
namespace RSWI\Admin; use RSWI\API\Client; if ( ! defined('ABSPATH') ) exit;

class Settings {
    const PAGE = 'rswi_settings';

    // Feature toggles
    const OPT_ENABLE_W2S = 'rswi_enable_w2s';
    const OPT_ENABLE_S2W = 'rswi_enable_s2w';
    const OPT_ENABLE_ORD = 'rswi_enable_orders';

    // Mapping / meta
    const OPT_GTIN_META  = 'rswi_gtin_meta'; // default _global_unique_id

    public static function init(){
        add_action('admin_menu', [__CLASS__,'menu']);
        add_action('admin_init', [__CLASS__,'register']);
    }
    public static function menu(){
        add_submenu_page('woocommerce','Square Integrator','Square Integrator','manage_woocommerce', self::PAGE, [__CLASS__,'render']);
    }
    public static function register(){
        // Square creds
        register_setting('rswi', Client::OPT_TOKEN);
        register_setting('rswi', Client::OPT_ENV);
        register_setting('rswi', Client::OPT_VERSION);
        register_setting('rswi', Client::OPT_LOC);
        // Toggles
        register_setting('rswi', self::OPT_ENABLE_W2S);
        register_setting('rswi', self::OPT_ENABLE_S2W);
        register_setting('rswi', self::OPT_ENABLE_ORD);
        // Mapping
        register_setting('rswi', self::OPT_GTIN_META);
    }
    public static function render(){
        if ( ! current_user_can('manage_woocommerce') ) return;
        $token   = get_option(Client::OPT_TOKEN,'');
        $env     = get_option(Client::OPT_ENV,'production');
        $version = get_option(Client::OPT_VERSION,'2025-06-20');
        $loc     = get_option(Client::OPT_LOC,'');
        $gtin    = get_option(self::OPT_GTIN_META,'_global_unique_id');
        $w2s     = (bool) get_option(self::OPT_ENABLE_W2S,false);
        $s2w     = (bool) get_option(self::OPT_ENABLE_S2W,false);
        $ord     = (bool) get_option(self::OPT_ENABLE_ORD,true);

        $sync_w2s = wp_nonce_url(admin_url('admin-post.php?action=rswi_sync_w2s'), 'rswi_sync_w2s');
        $sync_s2w = wp_nonce_url(admin_url('admin-post.php?action=rswi_sync_s2w'), 'rswi_sync_s2w');

        $updated = isset($_GET['updated'])?intval($_GET['updated']):null;
        $skipped = isset($_GET['skipped'])?intval($_GET['skipped']):null;
        $errors  = isset($_GET['errors'])?intval($_GET['errors']):null;
        ?>
        <div class="wrap">
            <h1>RankUP — Square ↔ Woo Integrator (MVP)</h1>
            <?php if($updated!==null): ?>
                <div class="notice notice-success is-dismissible"><p>
                    Sync: Updated <b><?php echo esc_html($updated);?></b>, Skipped <b><?php echo esc_html($skipped);?></b>, Errors <b><?php echo esc_html($errors);?></b>
                </p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('rswi'); ?>
                <h2>Square API</h2>
                <table class="form-table">
                    <tr>
                        <th>Access Token</th>
                        <td>
                            <input type="password" name="<?php echo Client::OPT_TOKEN; ?>" value="<?php echo esc_attr($token);?>" class="regular-text" autocomplete="off"/>
                            <p class="description">Вставьте ваш токен доступа. Получить его можно в <a href="https://developer.squareup.com/apps" target="_blank">Square Developer Dashboard</a>, открыв ваше приложение и перейдя на вкладку "Credentials".</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Environment</th>
                        <td>
                            <select name="<?php echo Client::OPT_ENV; ?>">
                                <option value="production" <?php selected($env,'production');?>>Production</option>
                                <option value="sandbox" <?php selected($env,'sandbox');?>>Sandbox</option>
                            </select>
                            <p class="description">Выберите "Production" для реального магазина или "Sandbox" для тестирования.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Square-Version</th>
                        <td>
                            <input type="text" name="<?php echo Client::OPT_VERSION; ?>" value="<?php echo esc_attr($version);?>" class="regular-text"/>
                            <p class="description">Версия API Square. Рекомендуется не изменять, если вы не уверены.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Location ID (для заказов)</th>
                        <td>
                            <input type="text" name="<?php echo Client::OPT_LOC; ?>" value="<?php echo esc_attr($loc);?>" class="regular-text"/>
                            <p class="description">ID вашей торговой точки (Location). Необходим для отправки заказов в Square. Найти его можно в <a href="https://developer.squareup.com/apps" target="_blank">Square Developer Dashboard</a> (раздел Locations).</p>
                        </td>
                    </tr>
                </table>

                <h2>Features</h2>
                <table class="form-table">
                    <tr>
                        <th>Woo → Square (Products)</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo self::OPT_ENABLE_W2S; ?>" value="1" <?php checked($w2s,true);?>/> Enable</label>
                            <p class="description">Включает автоматическую и ручную синхронизацию товаров из WooCommerce в Square.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Square → Woo (Products)</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo self::OPT_ENABLE_S2W; ?>" value="1" <?php checked($s2w,true);?>/> Enable</label>
                            <p class="description">Включает автоматическую и ручную синхронизацию товаров из Square в WooCommerce.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Woo → Square (Orders)</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo self::OPT_ENABLE_ORD; ?>" value="1" <?php checked($ord,true);?>/> Enable</label>
                            <p class="description">При включении, новые заказы со статусом "Обработка" или "Выполнен" будут отправляться в Square.</p>
                        </td>
                    </tr>
                </table>

                <h2>Field Mapping</h2>
                <table class="form-table">
                    <tr>
                        <th>GTIN meta key (Woo)</th>
                        <td>
                            <input type="text" name="<?php echo self::OPT_GTIN_META; ?>" value="<?php echo esc_attr($gtin);?>" class="regular-text"/>
                            <p class="description">Мета-ключ, в котором хранится GTIN (UPC/EAN/ISBN) товара в WooCommerce. По умолчанию <code>_global_unique_id</code>.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr/>
            <h2>Manual Sync</h2>
            <p><a href="<?php echo esc_url($sync_w2s);?>" class="button button-primary">Sync Woo → Square (Products)</a>
                <a href="<?php echo esc_url($sync_s2w);?>" class="button">Sync Square → Woo (Products)</a></p>
        </div>
        <?php
    }
}
