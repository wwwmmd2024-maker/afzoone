<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
// Only plugin configuration is removed. Product pictures, credits and remote licenses remain intact.
wp_clear_scheduled_hook('pif_refresh_license');
foreach (['pif_license', 'pif_settings', 'pif_activation_redirect'] as $option) { delete_option($option); }
global $wpdb;
foreach (['_transient_pif_', '_transient_timeout_pif_', 'pif_import_lock_'] as $prefix) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%'));
}
