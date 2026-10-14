<?php
/**
 * Plugin Name: تصویرجو — تصویر محصولات ووکامرس
 * Description: جست‌وجو و انتخاب تصویر شاخص و گالری محصول از پیشخوان، با اعتبارسنجی لایسنس سمت سرور.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: Afzoone
 * License: GPL-2.0-or-later
 * Text Domain: product-image-finder
 * Domain Path: /languages
 */
if (!defined('ABSPATH')) { exit; }
define('PIF_VERSION', '1.0.0');
define('PIF_DIR', plugin_dir_path(__FILE__));
define('PIF_URL', plugin_dir_url(__FILE__));
require_once PIF_DIR . 'includes/class-pif-license.php';
require_once PIF_DIR . 'includes/class-pif-images.php';
require_once PIF_DIR . 'includes/class-pif-admin.php';

register_activation_hook(__FILE__, function () {
    if (is_multisite()) { wp_die(esc_html__('نسخه فعلی برای وردپرس تک‌سایته است؛ نصب شبکه‌ای پشتیبانی نمی‌شود.', 'product-image-finder')); }
    add_option('pif_activation_redirect', 1, '', false);
    if (!wp_next_scheduled('pif_refresh_license')) { wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'pif_refresh_license'); }
});
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('pif_refresh_license'); });
add_action('pif_refresh_license', ['PIF_License', 'refresh']);
add_action('plugins_loaded', function () {
    PIF_Admin::init();
    PIF_Images::init();
});
add_action('before_woocommerce_init', function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
