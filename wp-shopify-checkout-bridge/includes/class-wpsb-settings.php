<?php
/**
 * Settings: registers the admin menu, all plugin options, and the main
 * "Shopify Bridge" settings screen. Other feature classes hang their own
 * submenus off the shared parent slug defined here.
 */

if (!defined('ABSPATH')) { exit; }

class WPSB_Settings {

    /** Shared top-level menu slug used by every admin screen in the plugin. */
    const MENU_SLUG = 'wpsb-settings';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register']);
    }

    /**
     * All option keys the plugin owns, each with a sanitize callback. The list
     * is the single source of truth for register_setting() and mirrors the keys
     * cleaned up by uninstall.php.
     */
    public static function fields() {
        return [
            'wpsb_shop_domain'            => 'wpsb_sanitize_domain',
            'wpsb_storefront_token'       => 'sanitize_text_field',
            'wpsb_admin_token'            => 'sanitize_text_field',
            'wpsb_currency'               => 'sanitize_text_field',
            'wpsb_pixel_fb'               => 'sanitize_text_field',
            'wpsb_pixel_tt'               => 'sanitize_text_field',
            'wpsb_store_name'             => 'sanitize_text_field',
            'wpsb_trust_text'             => 'sanitize_text_field',
            'wpsb_free_ship_threshold'    => 'wpsb_sanitize_float',
            'wpsb_wc_redirect'            => 'esc_url_raw',
            'wpsb_order_metadata'         => 'wpsb_sanitize_bool',
            'wpsb_recovery_enabled'       => 'wpsb_sanitize_bool',
            'wpsb_recovery_ai_enabled'    => 'wpsb_sanitize_bool',
            'wpsb_recovery_sequence'      => 'sanitize_text_field',
            'wpsb_recovery_max_touches'   => 'absint',
            'wpsb_recovery_from_name'     => 'sanitize_text_field',
            'wpsb_recovery_from_email'    => 'sanitize_email',
            'wpsb_klaviyo_api_key'        => 'sanitize_text_field',
            'wpsb_attribution_window_days'=> 'absint',
            'wpsb_anthropic_key'          => 'sanitize_text_field',
            'wpsb_ai_auto_sync'           => 'wpsb_sanitize_bool',
            'wpsb_ai_tone'                => 'sanitize_text_field',
            'wpsb_rs_block_vpn'           => 'wpsb_sanitize_bool',
            'wpsb_rs_rate_limit'          => 'absint',
            'wpsb_rs_min_time_seconds'    => 'absint',
            'wpsb_rs_require_behavior'    => 'wpsb_sanitize_bool',
        ];
    }

    public function register() {
        foreach (self::fields() as $key => $sanitize) {
            register_setting('wpsb_settings_group', $key, [
                'sanitize_callback' => $sanitize,
            ]);
        }
    }

    public function menu() {
        add_menu_page(
            __('Shopify Bridge', 'wpsb'),
            __('Shopify Bridge', 'wpsb'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-cart',
            56
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'wpsb'),
            __('Settings', 'wpsb'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $shop = get_option('wpsb_shop_domain', '');
        ?>
        <div class="wrap wpsb-admin">
            <h1><?php esc_html_e('Shopify Bridge — Settings', 'wpsb'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('wpsb_settings_group'); ?>

                <h2><?php esc_html_e('Shopify Connection', 'wpsb'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->text_row('wpsb_shop_domain', __('Shop domain', 'wpsb'), 'your-store.myshopify.com');
                    $this->text_row('wpsb_storefront_token', __('Storefront API token', 'wpsb'));
                    $this->text_row('wpsb_admin_token', __('Admin API token', 'wpsb'), '', __('Optional. Needed for order recovery measurement and WordPress → Shopify push.', 'wpsb'));
                    $this->text_row('wpsb_currency', __('Currency', 'wpsb'), 'USD');
                    ?>
                </table>

                <h2><?php esc_html_e('Storefront / Conversion', 'wpsb'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->text_row('wpsb_store_name', __('Store name', 'wpsb'), get_bloginfo('name'));
                    $this->text_row('wpsb_trust_text', __('Trust row text', 'wpsb'), 'Secure checkout · 30-day returns');
                    $this->text_row('wpsb_free_ship_threshold', __('Free shipping threshold', 'wpsb'), '0');
                    $this->text_row('wpsb_wc_redirect', __('WooCommerce → Shopify redirect URL', 'wpsb'), '', __('Where an empty-eligible WooCommerce cart is sent (usually your Shopify /cart or a collection).', 'wpsb'));
                    $this->checkbox_row('wpsb_order_metadata', __('Attach reconciliation data to Shopify orders', 'wpsb'));
                    ?>
                </table>
                <p class="description">
                    <?php esc_html_e('Leave unchecked (recommended) so Shopify orders look like normal Online Store sales — no note and no "Additional details" attributes. The WooCommerce order is still marked paid, matched by customer email + total. Check it only if you need the exact WooCommerce order id / UTMs stored on the Shopify order.', 'wpsb'); ?>
                </p>

                <h2><?php esc_html_e('Pixels', 'wpsb'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->text_row('wpsb_pixel_fb', __('Facebook Pixel ID', 'wpsb'));
                    $this->text_row('wpsb_pixel_tt', __('TikTok Pixel ID', 'wpsb'));
                    ?>
                </table>

                <h2><?php esc_html_e('Recovery', 'wpsb'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->checkbox_row('wpsb_recovery_enabled', __('Enable abandoned-cart recovery', 'wpsb'));
                    $this->checkbox_row('wpsb_recovery_ai_enabled', __('Use AI-written recovery copy', 'wpsb'));
                    $this->text_row('wpsb_recovery_sequence', __('Recovery delays (hours, comma-separated)', 'wpsb'), '1,24,72');
                    $this->text_row('wpsb_recovery_max_touches', __('Max touches', 'wpsb'), '3');
                    $this->text_row('wpsb_recovery_from_name', __('From name', 'wpsb'), get_bloginfo('name'));
                    $this->text_row('wpsb_recovery_from_email', __('From email', 'wpsb'), get_bloginfo('admin_email'));
                    $this->text_row('wpsb_klaviyo_api_key', __('Klaviyo API key', 'wpsb'), '', __('Optional. If set, recovery events are pushed to Klaviyo instead of wp_mail.', 'wpsb'));
                    $this->text_row('wpsb_attribution_window_days', __('Recovery attribution window (days)', 'wpsb'), '7');
                    ?>
                </table>

                <h2><?php esc_html_e('AI (Anthropic)', 'wpsb'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->text_row('wpsb_anthropic_key', __('Anthropic API key', 'wpsb'), '', __('Used by the Assistant and AI recovery copy. Stays on your server.', 'wpsb'));
                    $this->checkbox_row('wpsb_ai_auto_sync', __('Auto-generate recovery copy on abandonment', 'wpsb'));
                    $this->text_row('wpsb_ai_tone', __('AI tone', 'wpsb'), 'friendly');
                    ?>
                </table>

                <h2><?php esc_html_e('Fraud / Bot Protection', 'wpsb'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Applied uniformly to all visitors as store protection. Do not use these to show different content to reviewers, processors, or crawlers than to shoppers.', 'wpsb'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->checkbox_row('wpsb_rs_require_behavior', __('Require genuine interaction before checkout redirect', 'wpsb'));
                    $this->text_row('wpsb_rs_min_time_seconds', __('Minimum seconds on page before checkout', 'wpsb'), '0');
                    $this->text_row('wpsb_rs_rate_limit', __('Max checkout builds per session / hour', 'wpsb'), '30');
                    $this->checkbox_row('wpsb_rs_block_vpn', __('Flag datacenter/VPN IPs for review', 'wpsb'));
                    ?>
                </table>

                <?php submit_button(); ?>
            </form>

            <?php if ($shop): ?>
            <hr>
            <h2><?php esc_html_e('Return-to-WordPress Snippet', 'wpsb'); ?></h2>
            <p class="description"><?php esc_html_e('Paste into your Shopify theme cart template so "Return to cart" sends shoppers back to the WordPress page they came from.', 'wpsb'); ?></p>
            <textarea class="large-text code" rows="4" readonly onclick="this.select()">{% assign wpsb_return = cart.attributes.wpsb_return_to %}{% if wpsb_return %}<a href="{{ wpsb_return }}">&larr; Return to store</a>{% endif %}</textarea>
            <?php endif; ?>
        </div>
        <?php
    }

    private function text_row($key, $label, $placeholder = '', $desc = '') {
        $val = get_option($key, '');
        printf(
            '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>'
            . '<input type="text" id="%1$s" name="%1$s" value="%3$s" class="regular-text" placeholder="%4$s">'
            . ($desc ? '<p class="description">%5$s</p>' : '')
            . '</td></tr>',
            esc_attr($key),
            esc_html($label),
            esc_attr($val),
            esc_attr($placeholder),
            esc_html($desc)
        );
    }

    private function checkbox_row($key, $label) {
        $val = get_option($key, '');
        printf(
            '<tr><th scope="row">%2$s</th><td><label>'
            . '<input type="checkbox" name="%1$s" value="1" %3$s> %4$s</label></td></tr>',
            esc_attr($key),
            esc_html($label),
            checked($val, '1', false),
            esc_html__('Enabled', 'wpsb')
        );
    }
}

/* ---- Shared sanitizers referenced by register_setting() ---- */

function wpsb_sanitize_domain($value) {
    $value = sanitize_text_field($value);
    $value = preg_replace('#^https?://#', '', $value);
    return rtrim($value, '/');
}

function wpsb_sanitize_float($value) {
    return (string) (float) $value;
}

function wpsb_sanitize_bool($value) {
    return $value ? '1' : '';
}
