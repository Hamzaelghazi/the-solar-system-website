<?php
/**
 * Settings → Shopify Products. Opens with a connection-status card (how many
 * WooCommerce products are checkout-ready vs unlinked, and how many exist in
 * Shopify), then offers: link WooCommerce → Shopify by name/SKU, push
 * WooCommerce → Shopify, import Shopify → WooCommerce, and an on-demand price
 * sync. Actions post to admin-post.php with nonces + capability checks.
 */

if (!defined('ABSPATH')) { exit; }

class WPSB_Products {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_post_wpsb_link_now', [$this, 'handle_link_now']);
        add_action('admin_post_wpsb_sync_now', [$this, 'handle_sync_now']);
        add_action('admin_post_wpsb_import_all', [$this, 'handle_import_all']);
        add_action('admin_post_wpsb_push_one', [$this, 'handle_push_one']);
    }

    public function menu() {
        add_submenu_page(
            WPSB_Settings::MENU_SLUG,
            __('Shopify Products', 'wpsb'),
            __('Products', 'wpsb'),
            'manage_options',
            'wpsb-products',
            [$this, 'render']
        );
    }

    /* ------------------------------------------------------------------ */
    /* Status counts                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * A WooCommerce product is "linked" (checkout-ready) when it carries a real,
     * non-empty Shopify variant id. Unlinked = the product row exists but that
     * meta is missing OR empty. Both the status card, the push list, and the
     * linker use exactly this definition so they can never disagree (v1.11.4).
     */
    private function unlinked_ids($limit = 0) {
        global $wpdb;
        $sql = "SELECT p.ID
                  FROM {$wpdb->posts} p
                  LEFT JOIN {$wpdb->postmeta} m
                         ON m.post_id = p.ID AND m.meta_key = '_wpsb_variant_id'
                 WHERE p.post_type = 'product'
                   AND p.post_status = 'publish'
                   AND (m.meta_value IS NULL OR m.meta_value = '')
                 ORDER BY p.ID ASC";
        if ($limit > 0) {
            $sql .= $wpdb->prepare(' LIMIT %d', $limit);
        }
        return array_map('intval', $wpdb->get_col($sql));
    }

    private function linked_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
              WHERE meta_key = '_wpsb_variant_id' AND meta_value != ''"
        );
    }

    private function total_products() {
        if (!class_exists('WooCommerce')) {
            return 0;
        }
        $counts = wp_count_posts('product');
        return (int) ($counts->publish ?? 0);
    }

    private function shopify_count() {
        $api = WPSB_Shopify_API::instance();
        if (!$api->has_admin()) {
            return null;
        }
        $res = $api->admin_rest('GET', 'products/count.json');
        if (is_wp_error($res) || !isset($res['count'])) {
            return null;
        }
        return (int) $res['count'];
    }

    /* ------------------------------------------------------------------ */
    /* Render                                                              */
    /* ------------------------------------------------------------------ */

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $api = WPSB_Shopify_API::instance();
        $linked   = $this->linked_count();
        $total    = $this->total_products();
        $unlinked = max(0, $total - $linked);
        $shop_n   = $this->shopify_count();
        $all_ok   = $total > 0 && $unlinked === 0;
        ?>
        <div class="wrap wpsb-admin">
            <h1><?php esc_html_e('Shopify Products', 'wpsb'); ?></h1>

            <?php $this->flash(); ?>

            <?php if (!$api->is_configured()): ?>
                <div class="notice notice-warning"><p>
                    <?php
                    printf(
                        wp_kses_post(__('Add your Shopify domain and Storefront token on the <a href="%s">Settings</a> page first.', 'wpsb')),
                        esc_url(admin_url('admin.php?page=' . WPSB_Settings::MENU_SLUG))
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <div class="wpsb-status-card <?php echo $all_ok ? 'is-ok' : ''; ?>">
                <h2><?php esc_html_e('Shopify connection status', 'wpsb'); ?>
                    <?php if ($all_ok): ?><span class="wpsb-badge">✓ <?php esc_html_e('All products connected', 'wpsb'); ?></span><?php endif; ?>
                </h2>
                <div class="wpsb-status-grid">
                    <div><strong><?php echo esc_html(number_format_i18n($linked)); ?></strong><span><?php esc_html_e('Linked to Shopify (checkout-ready)', 'wpsb'); ?></span></div>
                    <div><strong><?php echo esc_html(number_format_i18n($unlinked)); ?></strong><span><?php esc_html_e('Not linked yet', 'wpsb'); ?></span></div>
                    <div><strong><?php echo $shop_n === null ? '—' : esc_html(number_format_i18n($shop_n)); ?></strong><span><?php esc_html_e('Products in Shopify', 'wpsb'); ?></span></div>
                </div>
            </div>

            <div class="wpsb-actions">
                <?php $this->action_form('wpsb_link_now', __('Link to Shopify by name or SKU', 'wpsb'), __('Matches unlinked WooCommerce products to their Shopify twin by handle/name, falling back to SKU. Storefront token only.', 'wpsb')); ?>
                <?php $this->action_form('wpsb_sync_now', __('Sync prices now', 'wpsb'), __('Refresh price, sale price and stock for every linked product from live Shopify data.', 'wpsb')); ?>
                <?php $this->action_form('wpsb_import_all', __('Import Shopify → WooCommerce', 'wpsb'), __('Create WooCommerce products from your Shopify catalogue so your theme renders them natively.', 'wpsb')); ?>
            </div>

            <?php $this->render_push_panel(); ?>

            <h2><?php esc_html_e('Shortcodes', 'wpsb'); ?></h2>
            <p class="description"><?php esc_html_e('Drop these into any page or post.', 'wpsb'); ?></p>
            <textarea class="large-text code" rows="3" readonly onclick="this.select()">[wpsb_product handle="best-product"]
[wpsb_buy_button handle="best-product" text="Buy Now"]
[wpsb_cart]</textarea>
        </div>
        <?php
    }

    private function action_form($action, $label, $desc) {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wpsb-action">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <?php wp_nonce_field($action); ?>
            <button type="submit" class="button button-primary"><?php echo esc_html($label); ?></button>
            <p class="description"><?php echo esc_html($desc); ?></p>
        </form>
        <?php
    }

    /** Lists unlinked WooCommerce products that could be pushed to Shopify. */
    private function render_push_panel() {
        if (!class_exists('WooCommerce') || !WPSB_Shopify_API::instance()->has_admin()) {
            return;
        }
        $ids = $this->unlinked_ids(25);
        if (!$ids) {
            return;
        }
        echo '<h2>' . esc_html__('WordPress → Shopify', 'wpsb') . '</h2>';
        echo '<p class="description">' . esc_html__('These WooCommerce products are not linked to a Shopify variant yet. Push a product to create it in Shopify (requires write_products).', 'wpsb') . '</p>';
        echo '<table class="widefat striped"><tbody>';
        foreach ($ids as $pid) {
            $wc = wc_get_product($pid);
            if (!$wc) {
                continue;
            }
            echo '<tr><td>' . esc_html($wc->get_name()) . '</td><td style="text-align:right">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
            echo '<input type="hidden" name="action" value="wpsb_push_one">';
            echo '<input type="hidden" name="product_id" value="' . esc_attr($pid) . '">';
            wp_nonce_field('wpsb_push_one');
            echo '<button class="button">' . esc_html__('Push to Shopify', 'wpsb') . '</button>';
            echo '</form></td></tr>';
        }
        echo '</tbody></table>';
    }

    /* ------------------------------------------------------------------ */
    /* Handlers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Link unlinked WooCommerce products to Shopify by name/handle, then SKU.
     * Clears the per-run "already tried" markers at the start so a product that
     * was tried before it existed in Shopify is retried (v1.11.4).
     */
    public function handle_link_now() {
        $this->guard('wpsb_link_now');
        $api = WPSB_Shopify_API::instance();
        $woo = WPSB_Woo::instance();

        $ids = $this->unlinked_ids(50);
        $linked = 0;
        $unmatched = [];

        foreach ($ids as $pid) {
            $wc = wc_get_product($pid);
            if (!$wc) {
                continue;
            }
            // Try by handle/name first.
            $handle  = get_post_meta($pid, '_wpsb_handle', true);
            $candidate = $handle ?: sanitize_title($wc->get_name());
            $product = $api->get_product_by_handle($candidate);
            $variant = $product ? $product['default_variant'] : '';

            // Fall back to SKU.
            if (!$product && $wc->get_sku()) {
                $bySku = $api->get_variant_by_sku($wc->get_sku());
                if ($bySku && !empty($bySku['matched_variant'])) {
                    $product = $bySku;
                    $variant = $bySku['matched_variant']['id'];
                }
            }

            if ($product && $variant) {
                update_post_meta($pid, '_wpsb_variant_id', $variant);
                update_post_meta($pid, '_wpsb_handle', $product['handle']);
                if (!empty($product['id'])) {
                    update_post_meta($pid, '_wpsb_shopify_id', $product['id']);
                }
                $linked++;
            } else {
                $unmatched[] = $wc->get_name();
            }
        }

        $this->redirect_with(sprintf(
            /* translators: 1: linked count, 2: unmatched count */
            __('Linked %1$d product(s). %2$d could not be matched.', 'wpsb'),
            $linked,
            count($unmatched)
        ) . ($unmatched ? ' ' . __('Unmatched:', 'wpsb') . ' ' . esc_html(implode(', ', array_slice($unmatched, 0, 10))) : ''));
    }

    public function handle_sync_now() {
        $this->guard('wpsb_sync_now');
        $res = WPSB_AI_Sync::instance()->sync_all();
        $this->redirect_with(sprintf(
            __('Synced %1$d of %2$d linked product(s).', 'wpsb'),
            (int) $res['updated'],
            (int) $res['checked']
        ));
    }

    public function handle_import_all() {
        $this->guard('wpsb_import_all');
        $api = WPSB_Shopify_API::instance();
        $woo = WPSB_Woo::instance();
        $cursor = null;
        $imported = 0;
        for ($i = 0; $i < 100; $i++) {
            $page = $api->list_products($cursor, 20);
            if (is_wp_error($page) || empty($page['products'])) {
                break;
            }
            foreach ($page['products'] as $product) {
                $res = $woo->import_product($product);
                if (!is_wp_error($res)) {
                    $imported++;
                }
            }
            if (empty($page['cursor'])) {
                break;
            }
            $cursor = $page['cursor'];
        }
        $this->redirect_with(sprintf(__('Imported %d product(s) into WooCommerce.', 'wpsb'), $imported));
    }

    public function handle_push_one() {
        $this->guard('wpsb_push_one');
        $pid = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $wc  = $pid ? wc_get_product($pid) : null;
        if (!$wc) {
            $this->redirect_with(__('Product not found.', 'wpsb'), 'error');
        }

        // By design, Shopify holds ONLY SKU, price and item number for each
        // product — no images, no descriptions. Those stay in WordPress and are
        // shown on the WordPress checkout. So the push payload deliberately omits
        // body_html and images and sends just the title (required by Shopify),
        // the SKU and the price.
        $payload = [
            'title'    => $wc->get_name(),
            'status'   => 'active',
            'variants' => [[
                'price'                => (string) $wc->get_price(),
                'sku'                  => $wc->get_sku(),
                'inventory_management' => null,
            ]],
        ];

        $res = WPSB_Shopify_API::instance()->push_product($payload);
        if (is_wp_error($res) || empty($res['product'])) {
            $msg = is_wp_error($res) ? $res->get_error_message() : __('Shopify did not return a product.', 'wpsb');
            $this->redirect_with($msg, 'error');
        }

        $product = $res['product'];
        $variant_gid = '';
        if (!empty($product['variants'][0]['id'])) {
            $variant_gid = 'gid://shopify/ProductVariant/' . $product['variants'][0]['id'];
        }
        update_post_meta($pid, '_wpsb_variant_id', $variant_gid);
        update_post_meta($pid, '_wpsb_shopify_id', 'gid://shopify/Product/' . ($product['id'] ?? ''));
        if (!empty($product['handle'])) {
            update_post_meta($pid, '_wpsb_handle', $product['handle']);
        }
        $this->redirect_with(sprintf(__('Pushed "%s" to Shopify and linked it.', 'wpsb'), $wc->get_name()));
    }

    /* ------------------------------------------------------------------ */

    private function guard($action) {
        if (!current_user_can('manage_options') || !check_admin_referer($action)) {
            wp_die(esc_html__('Permission denied.', 'wpsb'));
        }
    }

    private function redirect_with($message, $type = 'success') {
        set_transient('wpsb_products_flash', ['type' => $type, 'msg' => $message], 60);
        wp_safe_redirect(admin_url('admin.php?page=wpsb-products'));
        exit;
    }

    private function flash() {
        $flash = get_transient('wpsb_products_flash');
        if (!$flash) {
            return;
        }
        delete_transient('wpsb_products_flash');
        $class = ($flash['type'] === 'error') ? 'notice-error' : 'notice-success';
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($flash['msg']));
    }
}
