<?php
/**
 * Plugin Name: WP Shopify Checkout Bridge
 * Plugin URI:  https://example.com/
 * Description: Routes WordPress traffic to Shopify checkout with full roaming — cart, customer, UTMs and pixel attribution preserved end-to-end. Built on the Shopify Cart API.
 * Version:     1.11.4
 * Author:      MowerPro
 * License:     GPL-2.0+
 * Text Domain: wpsb
 */

if (!defined('ABSPATH')) { exit; }

define('WPSB_VERSION', '1.11.4');
define('WPSB_PATH', plugin_dir_path(__FILE__));
define('WPSB_URL',  plugin_dir_url(__FILE__));

// Shopify Storefront API version. The Cart API used by this plugin is stable
// in 2024-10 and later. Bump this only after testing against a newer version.
if (!defined('WPSB_API_VERSION')) {
    define('WPSB_API_VERSION', '2025-01');
}

require_once WPSB_PATH . 'includes/class-wpsb-settings.php';
require_once WPSB_PATH . 'includes/class-wpsb-shopify-api.php';
require_once WPSB_PATH . 'includes/class-wpsb-checkout.php';
require_once WPSB_PATH . 'includes/class-wpsb-shortcodes.php';
require_once WPSB_PATH . 'includes/class-wpsb-recovery.php';
require_once WPSB_PATH . 'includes/class-wpsb-orders.php';
require_once WPSB_PATH . 'includes/class-wpsb-analytics.php';
require_once WPSB_PATH . 'includes/class-wpsb-assistant.php';
require_once WPSB_PATH . 'includes/class-wpsb-products.php';
require_once WPSB_PATH . 'includes/class-wpsb-woo.php';
require_once WPSB_PATH . 'includes/class-wpsb-ai-sync.php';
require_once WPSB_PATH . 'includes/class-wpsb-risk-shield.php';

final class WP_Shopify_Bridge {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function init() {
        // Upgrade the DB schema in place when the stored version is behind the
        // code version, so existing installs pick up new columns without a
        // manual deactivate/reactivate cycle.
        $this->maybe_upgrade();

        WPSB_Settings::instance();
        WPSB_Checkout::instance();
        WPSB_Shortcodes::instance();
        WPSB_Recovery::instance();
        WPSB_Orders::instance();
        WPSB_Analytics::instance();
        WPSB_Assistant::instance();
        WPSB_Products::instance();
        WPSB_Woo::instance();

        // Custom cron cadence used by the order-poll and recovery crons. Both
        // need sub-hourly granularity to honour short recovery delays.
        add_filter('cron_schedules', [$this, 'cron_schedules']);

        // Ensure the session cookie exists on EVERY front-end page load, early,
        // before output. This is what makes behavioral signal tracking work
        // from the first interaction (fixes the v1.0 risk-shield dead spot).
        add_action('init', [$this, 'ensure_session_cookie'], 1);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Register a 15-minute interval. WordPress ships hourly/twicedaily/daily
     * only; the recovery sequence (first touch at 60 min) needs finer polling.
     */
    public function cron_schedules($schedules) {
        if (!isset($schedules['wpsb_15min'])) {
            $schedules['wpsb_15min'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __('Every 15 Minutes', 'wpsb'),
            ];
        }
        return $schedules;
    }

    /**
     * Run the schema installer when the stored version differs from the code
     * version. dbDelta diffs the table and ALTERs in any new columns/indexes.
     */
    public function maybe_upgrade() {
        $stored = get_option('wpsb_version');
        if ($stored === WPSB_VERSION) {
            return;
        }
        $this->install_schema();

        // Upgrading from a pre-1.3 install: carts that already received the old
        // single recovery email have recovery_sent=1 but recovery_stage=0. Seed
        // their stage so the new sequence treats them as already-touched and
        // does not re-email them. next_recovery_at stays NULL → they go dormant.
        if ($stored && version_compare($stored, '1.3.0', '<')) {
            global $wpdb;
            $table = $wpdb->prefix . 'wpsb_carts';
            $wpdb->query(
                "UPDATE {$table} SET recovery_stage = 1
                 WHERE recovery_sent = 1 AND recovery_stage = 0"
            );
        }

        update_option('wpsb_version', WPSB_VERSION);
    }

    /**
     * Current visitor's session id. Creates and sets the cookie when missing.
     * Safe to call early (on `init`) before headers are sent.
     *
     * @return string
     */
    public static function session_id() {
        static $sid = null;
        if ($sid !== null) {
            return $sid;
        }

        if (!empty($_COOKIE['wpsb_sid'])) {
            $sid = sanitize_text_field(wp_unslash($_COOKIE['wpsb_sid']));
            if (strlen($sid) > 64) {
                $sid = substr($sid, 0, 64);
            }
            return $sid;
        }

        $sid = wp_generate_uuid4();
        if (!headers_sent()) {
            setcookie('wpsb_sid', $sid, [
                'expires'  => time() + (86400 * 30),
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => false, // read client-side for attribution; not a secret
                'samesite' => 'Lax',
            ]);
        }
        // Make it usable within the current request too.
        $_COOKIE['wpsb_sid'] = $sid;
        return $sid;
    }

    /**
     * Runs on every front-end request: guarantees a session id + records the
     * session start time so duration signals are meaningful.
     */
    public function ensure_session_cookie() {
        if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }
        $sid = self::session_id();
        $key = 'wpsb_sig_' . $sid;
        $signals = get_transient($key);
        if (!is_array($signals)) {
            set_transient($key, ['session_start' => time()], HOUR_IN_SECONDS);
        }
    }

    /**
     * Cache-busting asset version: plugin version + the file's mtime, so any
     * edit to a JS/CSS file changes its ?ver= and browsers/CDNs can't serve a
     * stale copy across plugin updates that keep the same version number.
     */
    public static function asset_ver($relative) {
        $path  = WPSB_PATH . ltrim($relative, '/');
        $mtime = @filemtime($path);
        return $mtime ? WPSB_VERSION . '.' . $mtime : WPSB_VERSION;
    }

    public function enqueue_assets() {
        wp_enqueue_style('wpsb-style', WPSB_URL . 'assets/css/wpsb.css', [], self::asset_ver('assets/css/wpsb.css'));
        wp_enqueue_script('wpsb-script', WPSB_URL . 'assets/js/wpsb.js', [], self::asset_ver('assets/js/wpsb.js'), true);

        wp_localize_script('wpsb-script', 'WPSB', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('wpsb_nonce'),
            'shop'        => get_option('wpsb_shop_domain', ''),
            'pixel_fb'    => get_option('wpsb_pixel_fb', ''),
            'pixel_tt'    => get_option('wpsb_pixel_tt', ''),
            'currency'    => get_option('wpsb_currency', 'USD'),
            'free_ship'   => (float) get_option('wpsb_free_ship_threshold', 0),
            'trust_text'  => get_option('wpsb_trust_text', 'Secure checkout · 30-day returns'),
            'store_name'  => get_option('wpsb_store_name', get_bloginfo('name')),
            'wc'          => class_exists('WooCommerce'),
            'wc_checkout' => (class_exists('WooCommerce') && function_exists('wc_get_checkout_url')) ? wc_get_checkout_url() : '',
        ]);
    }

    public function activate() {
        $this->install_schema();
        update_option('wpsb_version', WPSB_VERSION);
    }

    /**
     * Create / upgrade the carts table. Safe to run repeatedly: dbDelta only
     * applies the diff. Called on activation and on a version-change check.
     *
     * v1.3 adds order-completion + multi-touch recovery columns and a status
     * index so the analytics dashboard stays index-friendly at volume.
     */
    public function install_schema() {
        global $wpdb;
        $table   = $wpdb->prefix . 'wpsb_carts';
        $charset = $wpdb->get_charset_collate();

        // dbDelta is whitespace-sensitive: two spaces after "PRIMARY KEY",
        // and each KEY must be named. Keep this formatting intact.
        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NOT NULL,
            checkout_id VARCHAR(191) DEFAULT NULL,
            checkout_url TEXT DEFAULT NULL,
            email VARCHAR(191) DEFAULT NULL,
            phone VARCHAR(40) DEFAULT NULL,
            cart_data LONGTEXT NOT NULL,
            utm_data TEXT DEFAULT NULL,
            recovered TINYINT(1) DEFAULT 0,
            recovery_sent TINYINT(1) DEFAULT 0,
            order_id VARCHAR(191) DEFAULT NULL,
            order_total DECIMAL(12,2) DEFAULT NULL,
            order_currency VARCHAR(8) DEFAULT NULL,
            recovered_at DATETIME DEFAULT NULL,
            recovery_stage TINYINT(1) DEFAULT 0,
            first_recovery_at DATETIME DEFAULT NULL,
            next_recovery_at DATETIME DEFAULT NULL,
            recovery_attributed TINYINT(1) DEFAULT 0,
            ai_subject VARCHAR(255) DEFAULT NULL,
            ai_body LONGTEXT DEFAULT NULL,
            ai_generated_at DATETIME DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'open',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY session_id (session_id),
            KEY email (email),
            KEY recovered (recovered),
            KEY status (status)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Clear every scheduled cron so nothing keeps firing after deactivation.
     */
    public function deactivate() {
        foreach (['wpsb_recovery_cron', 'wpsb_ai_sync_cron', 'wpsb_order_poll_cron', 'wpsb_product_sync_cron'] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            while ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
                $timestamp = wp_next_scheduled($hook);
            }
            wp_clear_scheduled_hook($hook);
        }
    }
}

WP_Shopify_Bridge::instance();
