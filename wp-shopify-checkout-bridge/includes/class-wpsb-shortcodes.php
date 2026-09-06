<?php
/**
 * Server-rendered shortcodes:
 *   [wpsb_product handle="..."]        product card with a buy button
 *   [wpsb_buy_button handle="..." variant="gid://..." text="Buy Now"]
 *   [wpsb_cart]                         link/summary that routes to Shopify
 *
 * Rendering is done server-side (no per-card AJAX) using the cached product
 * lookup, so cards are fast and SEO-visible. The buy button posts to the
 * wpsb_buy_now AJAX endpoint.
 */

if (!defined('ABSPATH')) { exit; }

class WPSB_Shortcodes {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('wpsb_product', [$this, 'product']);
        add_shortcode('wpsb_buy_button', [$this, 'buy_button']);
        add_shortcode('wpsb_cart', [$this, 'cart']);
    }

    public function product($atts) {
        $atts = shortcode_atts([
            'handle'  => '',
            'variant' => '',
            'text'    => __('Buy Now', 'wpsb'),
        ], $atts, 'wpsb_product');

        $product = WPSB_Shopify_API::instance()->get_product_by_handle($atts['handle']);
        if (!$product) {
            return $this->notice(__('Product not found.', 'wpsb'));
        }

        $variant  = $atts['variant'] ?: $product['default_variant'];
        $price     = wpsb_format_price($product['price'], $product['currency']);
        $available = false;
        foreach ($product['variants'] as $v) {
            if ($v['id'] === $variant && $v['available']) {
                $available = true;
                break;
            }
        }

        ob_start(); ?>
        <div class="wpsb-card">
            <?php if (!empty($product['image'])): ?>
                <div class="wpsb-card__media">
                    <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($product['image_alt']); ?>" loading="lazy">
                </div>
            <?php endif; ?>
            <div class="wpsb-card__body">
                <h3 class="wpsb-card__title"><?php echo esc_html($product['title']); ?></h3>
                <div class="wpsb-card__price"><?php echo esc_html($price); ?></div>
                <?php if (!empty($product['description'])): ?>
                    <p class="wpsb-card__desc"><?php echo esc_html(wp_trim_words($product['description'], 28)); ?></p>
                <?php endif; ?>
                <?php echo $this->render_buy($variant, $atts['text'], $available); ?>
                <div class="wpsb-trust"><?php echo esc_html(get_option('wpsb_trust_text', 'Secure checkout · 30-day returns')); ?></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function buy_button($atts) {
        $atts = shortcode_atts([
            'handle'  => '',
            'variant' => '',
            'text'    => __('Buy Now', 'wpsb'),
        ], $atts, 'wpsb_buy_button');

        $variant = $atts['variant'];
        if (!$variant && $atts['handle']) {
            $product = WPSB_Shopify_API::instance()->get_product_by_handle($atts['handle']);
            if ($product) {
                $variant = $product['default_variant'];
            }
        }
        if (!$variant) {
            return $this->notice(__('No variant to buy.', 'wpsb'));
        }
        return $this->render_buy($variant, $atts['text'], true);
    }

    public function cart($atts) {
        $url = get_option('wpsb_wc_redirect', '');
        if (class_exists('WooCommerce') && function_exists('wc_get_cart_url')) {
            $url = wc_get_cart_url();
        }
        return sprintf(
            '<a class="wpsb-btn wpsb-btn--secondary" href="%s">%s</a>',
            esc_url($url ?: '#'),
            esc_html__('View cart', 'wpsb')
        );
    }

    private function render_buy($variant, $text, $available) {
        if (!$available) {
            return '<button class="wpsb-btn wpsb-btn--disabled" disabled>' . esc_html__('Sold out', 'wpsb') . '</button>';
        }
        return sprintf(
            '<button class="wpsb-btn wpsb-buy" data-variant="%s" data-qty="1">%s</button>'
            . '<a class="wpsb-fallback" href="%s" rel="nofollow">%s</a>',
            esc_attr($variant),
            esc_html($text),
            esc_url($this->fallback_url($variant)),
            esc_html__('Trouble? Continue to secure checkout →', 'wpsb')
        );
    }

    /** Plain permalink-safe fallback that hits a query-var handled by checkout. */
    private function fallback_url($variant) {
        return add_query_arg([
            'wpsb_buy'  => rawurlencode($variant),
            'wpsb_sid'  => WP_Shopify_Bridge::session_id(),
        ], home_url('/'));
    }

    private function notice($msg) {
        return '<div class="wpsb-notice">' . esc_html($msg) . '</div>';
    }
}

/** Format a price with the store currency. Kept as a shared helper. */
function wpsb_format_price($amount, $currency = 'USD') {
    if ($amount === null || $amount === '') {
        return '';
    }
    $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$', 'AUD' => 'A$', 'MAD' => 'MAD '];
    $symbol = isset($symbols[$currency]) ? $symbols[$currency] : ($currency . ' ');
    return $symbol . number_format((float) $amount, 2);
}
