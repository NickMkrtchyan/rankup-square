<?php
namespace RSWI\Admin; if ( ! defined('ABSPATH') ) exit;

class Debug {
    public static function init(){
        if ( is_admin() && current_user_can('manage_options') ) {
            add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        }
    }

    public static function add_meta_box(){
        add_meta_box(
            'rswi_product_meta_debug',
            'All Product Meta (RSWI Debug)',
            [__CLASS__, 'render_meta_box'],
            'product',
            'normal',
            'low'
        );
    }

    public static function render_meta_box($post){
        $all_meta = get_post_meta($post->ID);
        if ( ! $all_meta ) {
            echo '<p>No meta data found for this product.</p>';
            return;
        }
        ?>
        <p>Используйте этот список, чтобы найти правильный "Meta Key" для вашего GTIN (EAN/UPC). Затем вставьте его в <a href="<?php echo esc_url(admin_url('admin.php?page=rswi_settings')); ?>">настройках плагина</a>.</p>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th style="width: 40%;">Meta Key (Ключ)</th>
                    <th>Meta Value (Значение)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_meta as $key => $values) : ?>
                    <?php
                    if (strpos($key, '_rswi_') === 0) {
                        continue;
                    }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($key); ?></strong></td>
                        <td>
                            <?php
                            foreach ($values as $value) {
                                $display_value = maybe_unserialize($value);
                                echo '<pre style="white-space: pre-wrap; word-break: break-all;">' . esc_html(print_r($display_value, true)) . '</pre>';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
