<?php
/**
 * WooCommerce integration. WooCommerce is the catalogue/display layer; Shopify
 * stays the checkout. This class:
 *   - imports Shopify products into WooCommerce (title/description/price/image),
 *     stamping the Shopify variant id on the WC product so the buy button knows
 *     what to check out;
 *   - handles the permalink-safe ?wpsb_buy=<variant> fallback for block themes
 *     that swallow button clicks.
 */

if (!defined('ABSPATH')) { exit; }

class WPSB_Woo {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Catch-all buy interceptor (works even when a block theme eats the
        // button's JS click): ?wpsb_buy=<variant> → Shopify checkout.
        add_action('template_redirect', [$this, 'catch_buy_fallback'], 1);
    }

    public function catch_buy_fallback() {
        if (empty($_GET['wpsb_buy'])) {
            return;
        }
        $variant = sanitize_text_field(wp_unslash($_GET['wpsb_buy']));
        if (strpos($variant, 'gid://shopify/ProductVariant/') !== 0) {
            return;
        }
        // Reuse the direct-buy path so attribution + recovery recording are
        // identical to the AJAX buy button.
        $snapshot = [['variant' => $variant, 'qty' => 1, 'title' => '']];
        $url = WPSB_Checkout::instance()->create_and_record(
            [['variantId' => $variant, 'quantity' => 1]],
            $snapshot
        );
        if (is_wp_error($url)) {
            wp_die(esc_html($url->get_error_message()), '', ['back_link' => true]);
        }
        wp_redirect($url);
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Import Shopify product → WooCommerce product                        */
    /* ------------------------------------------------------------------ */

    /**
     * Create (or update) a WooCommerce product from a normalized Shopify product
     * array. Returns the WC product id, or WP_Error.
     */
    public function import_product($product) {
        if (!class_exists('WC_Product_Simple')) {
            return new WP_Error('wpsb_no_wc', __('WooCommerce is not active.', 'wpsb'));
        }
        if (empty($product['handle'])) {
            return new WP_Error('wpsb_bad_product', __('Product is missing a handle.', 'wpsb'));
        }

        $existing_id = $this->find_by_handle($product['handle']);
        $wc = $existing_id ? wc_get_product($existing_id) : new WC_Product_Simple();
        if (!$wc) {
            $wc = new WC_Product_Simple();
        }

        $wc->set_name($product['title']);
        $wc->set_description($product['description_html'] ?: $product['description']);
        $wc->set_catalog_visibility('visible');
        $wc->set_sku($this->pick_sku($product));

        $variant = $this->pick_default_variant_data($product);
        if ($variant && $variant['price'] !== null) {
            $wc->set_regular_price((string) ($variant['compare_at'] ?: $variant['price']));
            if ($variant['compare_at'] && $variant['compare_at'] > $variant['price']) {
                $wc->set_sale_price((string) $variant['price']);
            } else {
                $wc->set_sale_price('');
            }
        }
        $wc->set_stock_status($variant && $variant['available'] ? 'instock' : 'outofstock');

        $product_id = $wc->save();
        if (!$product_id) {
            return new WP_Error('wpsb_save_failed', __('Could not save WooCommerce product.', 'wpsb'));
        }

        // Stamp the linkage the checkout bridge reads.
        update_post_meta($product_id, '_wpsb_variant_id', $variant ? $variant['id'] : '');
        update_post_meta($product_id, '_wpsb_handle', $product['handle']);
        update_post_meta($product_id, '_wpsb_shopify_id', $product['id']);
        update_post_meta($product_id, '_wpsb_snapshot', wp_json_encode([
            'price'      => $variant['price'] ?? null,
            'compare_at' => $variant['compare_at'] ?? null,
            'available'  => $variant['available'] ?? false,
            'synced_at'  => current_time('mysql'),
        ]));

        if (!empty($product['image']) && !has_post_thumbnail($product_id)) {
            $this->sideload_image($product['image'], $product_id, $product['title']);
        }

        // Bust the cached handle lookup so the fresh linkage is visible.
        delete_transient('wpsb_ph_' . md5(get_option('wpsb_shop_domain', '') . '|' . $product['handle']));

        return $product_id;
    }

    private function pick_default_variant_data($product) {
        foreach ($product['variants'] as $v) {
            if (!empty($v['available'])) {
                return $v;
            }
        }
        return $product['variants'][0] ?? null;
    }

    private function pick_sku($product) {
        foreach ($product['variants'] as $v) {
            if (!empty($v['sku'])) {
                return $v['sku'];
            }
        }
        return '';
    }

    public function find_by_handle($handle) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wpsb_handle' AND meta_value = %s LIMIT 1",
            $handle
        ));
    }

    private function sideload_image($url, $product_id, $title) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_id = media_sideload_image($url, $product_id, $title, 'id');
        if (!is_wp_error($attach_id)) {
            set_post_thumbnail($product_id, $attach_id);
        }
    }
}
