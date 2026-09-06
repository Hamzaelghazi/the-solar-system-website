<?php
/**
 * Checkout bridge: captures first-touch attribution, intercepts the checkout
 * step, converts the WooCommerce cart into a Shopify hosted checkout, records
 * the cart for recovery, and exposes an AJAX endpoint so the front-end can build
 * the checkout in the background behind the "Taking you to secure checkout…"
 * spinner (with a server-side redirect fallback).
 */

if (!defined('ABSPATH')) { exit; }

class WPSB_Checkout {

    const ATTR_COOKIE = 'wpsb_attr';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // First-touch attribution: capture landing UTMs / referrer once.
        add_action('init', [$this, 'capture_attribution'], 2);

        // Intercept the WooCommerce checkout step (server-side fallback path).
        add_action('template_redirect', [$this, 'maybe_redirect_checkout']);

        // Background checkout builder used by the spinner.
        add_action('wp_ajax_wpsb_build_checkout', [$this, 'ajax_build_checkout']);
        add_action('wp_ajax_nopriv_wpsb_build_checkout', [$this, 'ajax_build_checkout']);

        // Direct "buy this variant now" path (shortcode buy button).
        add_action('wp_ajax_wpsb_buy_now', [$this, 'ajax_buy_now']);
        add_action('wp_ajax_nopriv_wpsb_buy_now', [$this, 'ajax_buy_now']);
    }

    /* ------------------------------------------------------------------ */
    /* Attribution                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Capture the genuine first-touch source once per visitor and mirror it into
     * a readable cookie so the server-side cart → checkout path can append the
     * real UTMs. Never overwrites an existing first-touch value; never fabricates
     * UTMs — a referrer-inferred source is only used when the tags are absent.
     */
    public function capture_attribution() {
        if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }
        if (!empty($_COOKIE[self::ATTR_COOKIE])) {
            return; // first touch already recorded
        }

        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'twclid', 'gclid', 'fbclid'] as $k) {
            if (!empty($_GET[$k])) {
                $utm[$k] = sanitize_text_field(wp_unslash($_GET[$k]));
            }
        }

        // If a social platform stripped UTMs, infer the source from the genuine
        // referrer (first-touch only). This labels the visit, it does not forge
        // campaign tags.
        if (empty($utm['utm_source']) && !empty($_SERVER['HTTP_REFERER'])) {
            $ref = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])), PHP_URL_HOST);
            $source = $this->infer_source_from_referrer($ref);
            if ($source) {
                $utm['utm_source']   = $source;
                $utm['utm_medium']   = 'social';
                $utm['wpsb_inferred'] = '1';
            }
        }

        if (empty($utm)) {
            return;
        }
        if (!headers_sent()) {
            setcookie(self::ATTR_COOKIE, wp_json_encode($utm), [
                'expires'  => time() + (86400 * max(1, (int) get_option('wpsb_attribution_window_days', 7))),
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::ATTR_COOKIE] = wp_json_encode($utm);
    }

    private function infer_source_from_referrer($host) {
        if (!$host) {
            return '';
        }
        $host = strtolower($host);
        $map = [
            't.co' => 'x', 'x.com' => 'x', 'twitter.com' => 'x',
            'facebook.com' => 'facebook', 'm.facebook.com' => 'facebook', 'l.facebook.com' => 'facebook',
            'instagram.com' => 'instagram', 'l.instagram.com' => 'instagram',
            'tiktok.com' => 'tiktok',
            'youtube.com' => 'youtube', 'youtu.be' => 'youtube',
            'pinterest.com' => 'pinterest',
            'reddit.com' => 'reddit', 'out.reddit.com' => 'reddit',
        ];
        foreach ($map as $needle => $source) {
            if ($host === $needle || substr($host, -strlen('.' . $needle)) === '.' . $needle) {
                return $source;
            }
        }
        return '';
    }

    /** Read the captured attribution cookie back as an array. */
    public static function get_attribution() {
        if (empty($_COOKIE[self::ATTR_COOKIE])) {
            return [];
        }
        $decoded = json_decode(wp_unslash($_COOKIE[self::ATTR_COOKIE]), true);
        return is_array($decoded) ? array_map('sanitize_text_field', $decoded) : [];
    }

    /* ------------------------------------------------------------------ */
    /* Checkout interception                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Server-side fallback: when a shopper reaches the WooCommerce checkout page
     * with an eligible cart, build the Shopify checkout and redirect. The /cart
     * page is intentionally left viewable so shoppers can review first.
     */
    public function maybe_redirect_checkout() {
        if (!class_exists('WooCommerce') || !function_exists('is_checkout')) {
            return;
        }
        if (!is_checkout() || is_wc_endpoint_url('order-received')) {
            return;
        }
        $result = $this->build_from_wc_cart();
        if (is_wp_error($result) || empty($result['url'])) {
            // No eligible items → send to the configured redirect if set.
            $fallback = get_option('wpsb_wc_redirect', '');
            if ($fallback) {
                wp_safe_redirect($fallback);
                exit;
            }
            return;
        }
        // Note: the WooCommerce cart is intentionally NOT emptied, so a shopper
        // who returns without buying keeps their items (v1.11).
        wp_redirect($result['url']); // external Shopify URL
        exit;
    }

    /**
     * Build a Shopify checkout URL from the current WooCommerce cart. Returns
     * ['url'=>..., 'lines'=>...] or WP_Error when nothing maps to Shopify.
     */
    public function build_from_wc_cart() {
        if (!class_exists('WooCommerce') || !WC()->cart) {
            return new WP_Error('wpsb_no_cart', __('No WooCommerce cart.', 'wpsb'));
        }

        // Fraud/bot gate — applies to everyone uniformly.
        $gate = WPSB_Risk_Shield::instance()->check();
        if (is_wp_error($gate)) {
            return $gate;
        }

        $lines = [];
        $snapshot = [];
        foreach (WC()->cart->get_cart() as $item) {
            $product_id = $item['product_id'];
            $variant_id = get_post_meta($product_id, '_wpsb_variant_id', true);
            if (!$variant_id) {
                continue; // not a Shopify-linked product
            }
            $lines[] = ['variantId' => $variant_id, 'quantity' => (int) $item['quantity']];
            $snapshot[] = [
                'variant'  => $variant_id,
                'qty'      => (int) $item['quantity'],
                'title'    => get_the_title($product_id),
            ];
        }
        if (!$lines) {
            return new WP_Error('wpsb_no_linked_items', __('No Shopify-linked items in cart.', 'wpsb'));
        }

        $url = $this->create_and_record($lines, $snapshot);
        if (is_wp_error($url)) {
            return $url;
        }
        return ['url' => $url, 'lines' => $lines];
    }

    /**
     * Create the Shopify checkout, append attribution UTMs, record the cart for
     * recovery, and return the final URL. Public so the block-theme buy
     * fallback and other entry points can reuse the exact same path.
     *
     * @param array $lines    [ ['variantId'=>gid, 'quantity'=>n], ... ]
     * @param array $snapshot recovery snapshot rows
     * @return string|WP_Error checkout URL
     */
    public function create_and_record($lines, $snapshot) {
        $attr   = self::get_attribution();
        $return = $this->current_return_url();

        $attributes = ['wpsb_sid' => WP_Shopify_Bridge::session_id()];
        if ($return) {
            $attributes['wpsb_return_to'] = $return;
        }
        foreach ($attr as $k => $v) {
            $attributes[$k] = $v;
        }

        $email = '';
        if (class_exists('WooCommerce') && WC()->customer) {
            $email = WC()->customer->get_billing_email();
        }

        $url = WPSB_Shopify_API::instance()->create_checkout($lines, [
            'email'      => $email,
            'attributes' => $attributes,
        ]);
        if (is_wp_error($url)) {
            return $url;
        }

        // Append UTMs to the checkout URL too, so Shopify's own session
        // attribution reads them rather than logging "Direct".
        $utm_query = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
            if (!empty($attr[$k])) {
                $utm_query[$k] = $attr[$k];
            }
        }
        if ($utm_query) {
            $url = add_query_arg($utm_query, $url);
        }

        $this->record_cart($snapshot, $url, $email, $attr);
        return $url;
    }

    /**
     * Persist the abandoned-cart row keyed by session id. Recovery + order
     * detection read from this table. Upserts on session id.
     */
    private function record_cart($snapshot, $url, $email, $attr) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpsb_carts';
        $sid   = WP_Shopify_Bridge::session_id();
        $now   = current_time('mysql');

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1", $sid));
        $data = [
            'checkout_url' => $url,
            'email'        => $email ?: null,
            'cart_data'    => wp_json_encode($snapshot),
            'utm_data'     => wp_json_encode($attr),
            'status'       => 'open',
            'updated_at'   => $now,
        ];
        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing]);
        } else {
            $data['session_id'] = $sid;
            $data['created_at'] = $now;
            $wpdb->insert($table, $data);
        }
    }

    private function current_return_url() {
        $ref = wp_get_referer();
        if (!$ref) {
            return '';
        }
        // Same-origin only, so the attribute can't be turned into an open redirect.
        if (wp_parse_url($ref, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            return '';
        }
        return esc_url_raw($ref);
    }

    /* ------------------------------------------------------------------ */
    /* AJAX                                                                */
    /* ------------------------------------------------------------------ */

    /** Background builder for the spinner: returns the Shopify checkout URL. */
    public function ajax_build_checkout() {
        check_ajax_referer('wpsb_nonce', 'nonce');
        $result = $this->build_from_wc_cart();
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['url' => $result['url']]);
    }

    /** Direct buy: one variant straight to Shopify checkout. */
    public function ajax_buy_now() {
        check_ajax_referer('wpsb_nonce', 'nonce');
        $variant = isset($_POST['variant']) ? sanitize_text_field(wp_unslash($_POST['variant'])) : '';
        $qty     = isset($_POST['qty']) ? max(1, (int) $_POST['qty']) : 1;
        if (!$variant || strpos($variant, 'gid://shopify/ProductVariant/') !== 0) {
            wp_send_json_error(['message' => __('Invalid variant.', 'wpsb')]);
        }
        $snapshot = [['variant' => $variant, 'qty' => $qty, 'title' => '']];
        $url = $this->create_and_record([['variantId' => $variant, 'quantity' => $qty]], $snapshot);
        if (is_wp_error($url)) {
            wp_send_json_error(['message' => $url->get_error_message()]);
        }
        wp_send_json_success(['url' => $url]);
    }
}
