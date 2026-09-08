<?php
/**
 * Runs when the plugin is deleted from WP Admin. Removes all plugin data.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$options = [
    'wpsb_shop_domain',
    'wpsb_storefront_token',
    'wpsb_admin_token',
    'wpsb_currency',
    'wpsb_pixel_fb',
    'wpsb_pixel_tt',
    'wpsb_recovery_enabled',
    'wpsb_recovery_delay',
    'wpsb_klaviyo_api_key',
    'wpsb_store_name',
    'wpsb_recovery_from_name',
    'wpsb_recovery_from_email',
    'wpsb_recovery_ai_enabled',
    'wpsb_recovery_sequence',
    'wpsb_recovery_max_touches',
    'wpsb_attribution_window_days',
    'wpsb_free_ship_threshold',
    'wpsb_trust_text',
    'wpsb_wc_redirect',
    'wpsb_order_metadata',
    'wpsb_direct_traffic',
    'wpsb_orders_since',
    'wpsb_sync_offset',
    'wpsb_anthropic_key',
    'wpsb_ai_auto_sync',
    'wpsb_ai_tone',
    'wpsb_ai_cron_offset',
    'wpsb_rs_block_vpn',
    'wpsb_rs_rate_limit',
    'wpsb_rs_min_time_seconds',
    'wpsb_rs_require_behavior',
    'wpsb_version',
];

foreach ($options as $option) {
    delete_option($option);
}

$table = $wpdb->prefix . 'wpsb_carts';
$wpdb->query("DROP TABLE IF EXISTS {$table}");
