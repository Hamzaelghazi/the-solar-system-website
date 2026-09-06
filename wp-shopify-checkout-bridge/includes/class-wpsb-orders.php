<?php
/**
 * Order detection without webhooks. A 15-minute cron polls the Admin API for
 * recent orders and matches them back to abandoned-cart rows by the wpsb_sid
 * stamped into the Shopify cart attributes, marking carts recovered/converted
 * with the real order total.
 */

if (!defined('ABSPATH')) { exit; }

class WPSB_Orders {

    const CRON_HOOK = 'wpsb_order_poll_cron';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(self::CRON_HOOK, [$this, 'poll']);
        add_action('init', [$this, 'maybe_schedule']);
    }

    public function maybe_schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK) && WPSB_Shopify_API::instance()->has_admin()) {
            wp_schedule_event(time() + 60, 'wpsb_15min', self::CRON_HOOK);
        }
    }

    /**
     * Poll orders created since the last checkpoint and reconcile them against
     * open cart rows. Uses a small overlap window so an order that lands right
     * on the boundary isn't missed.
     */
    public function poll() {
        $api = WPSB_Shopify_API::instance();
        if (!$api->has_admin()) {
            return;
        }

        $since = get_option('wpsb_orders_since', '');
        if (!$since) {
            // First run: look back 24h so we don't scan the whole store.
            $since = gmdate('c', time() - DAY_IN_SECONDS);
        }

        $result = $api->get_orders_since($since);
        if (is_wp_error($result) || empty($result['orders'])) {
            return;
        }

        $latest = $since;
        foreach ($result['orders'] as $order) {
            $this->reconcile_order($order);
            if (!empty($order['created_at']) && strtotime($order['created_at']) > strtotime($latest)) {
                $latest = $order['created_at'];
            }
        }

        // Advance the checkpoint with a 5-minute overlap for safety.
        update_option('wpsb_orders_since', gmdate('c', strtotime($latest) - 5 * MINUTE_IN_SECONDS));
    }

    private function reconcile_order($order) {
        $sid = '';
        $wc_order_id = 0;
        if (!empty($order['note_attributes']) && is_array($order['note_attributes'])) {
            foreach ($order['note_attributes'] as $attr) {
                $name = $attr['name'] ?? '';
                if ($name === 'wpsb_sid') {
                    $sid = sanitize_text_field($attr['value'] ?? '');
                } elseif ($name === 'wpsb_wc_order_id') {
                    $wc_order_id = (int) ($attr['value'] ?? 0);
                }
            }
        }

        // Mark the matching WooCommerce order paid, if we carried its id through
        // the Shopify checkout attributes (new gateway flow).
        if ($wc_order_id) {
            $this->complete_wc_order($wc_order_id, $order);
        }

        if (!$sid) {
            return; // not one of ours (or attribution stripped)
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpsb_carts';
        $cart  = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_id = %s AND status != 'converted' ORDER BY id DESC LIMIT 1",
            $sid
        ));
        if (!$cart) {
            return;
        }

        // A recovery touch counts as attributed if the conversion lands inside
        // the configured attribution window after the first touch.
        $attributed = 0;
        if (!empty($cart->first_recovery_at)) {
            $window = (int) get_option('wpsb_attribution_window_days', 7) * DAY_IN_SECONDS;
            if (strtotime($order['created_at']) - strtotime($cart->first_recovery_at) <= $window) {
                $attributed = 1;
            }
        }

        $wpdb->update($table, [
            'recovered'           => 1,
            'recovery_attributed' => $attributed,
            'order_id'            => sanitize_text_field((string) ($order['id'] ?? '')),
            'order_total'         => isset($order['total_price']) ? (float) $order['total_price'] : null,
            'order_currency'      => sanitize_text_field($order['currency'] ?? get_option('wpsb_currency', 'USD')),
            'recovered_at'        => current_time('mysql'),
            'status'              => 'converted',
            'updated_at'          => current_time('mysql'),
        ], ['id' => $cart->id]);
    }

    /**
     * Mark a WooCommerce order paid once its Shopify counterpart is detected.
     * Only advances an order that is still awaiting payment, and only when the
     * Shopify order is actually paid — never downgrades a completed order, and
     * is idempotent across polls.
     */
    private function complete_wc_order($wc_order_id, $shopify_order) {
        if (!class_exists('WooCommerce')) {
            return;
        }
        $order = wc_get_order($wc_order_id);
        if (!$order) {
            return;
        }
        // Already handled?
        if ($order->get_meta('_wpsb_shopify_order_id')) {
            return;
        }
        // Only settle when Shopify reports the money is in.
        $financial = $shopify_order['financial_status'] ?? '';
        if (!in_array($financial, ['paid', 'partially_paid', 'authorized'], true)) {
            return;
        }
        if (!$order->needs_payment() && !$order->has_status(['pending', 'failed', 'on-hold'])) {
            return;
        }

        $order->update_meta_data('_wpsb_shopify_order_id', (string) ($shopify_order['id'] ?? ''));
        $order->save();

        $note = sprintf(
            __('Payment confirmed on Shopify (order %1$s, %2$s %3$s).', 'wpsb'),
            (string) ($shopify_order['id'] ?? '—'),
            isset($shopify_order['total_price']) ? $shopify_order['total_price'] : '',
            $shopify_order['currency'] ?? ''
        );
        // payment_complete() moves the order to processing/completed per WC rules
        // and records the transaction id.
        $order->payment_complete((string) ($shopify_order['id'] ?? ''));
        $order->add_order_note($note);
    }
}
