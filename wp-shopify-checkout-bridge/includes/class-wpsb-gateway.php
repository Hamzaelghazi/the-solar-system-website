<?php
/**
 * WooCommerce payment gateway: "Pay via Shopify".
 *
 * This is the hand-off point in the new flow. The shopper fills WooCommerce's
 * full native checkout (product images from the WordPress media library, plus
 * billing/shipping fields). On "Place Order", WooCommerce validates the form and
 * creates the order; this gateway then builds a Shopify hosted checkout carrying
 * ONLY the SKU-matched variant, quantity, customer info and the "Online Store"
 * source — never images or descriptions — and redirects the shopper to Shopify
 * to pay.
 *
 * WC_Payment_Gateway only exists once WooCommerce has loaded, so the class is
 * defined lazily on `plugins_loaded` and registered via the gateways filter.
 */

if (!defined('ABSPATH')) { exit; }

add_action('plugins_loaded', 'wpsb_register_gateway', 11);

function wpsb_register_gateway() {
    if (!class_exists('WC_Payment_Gateway') || class_exists('WPSB_Gateway')) {
        return;
    }

    class WPSB_Gateway extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'wpsb_shopify';
            $this->method_title       = __('Pay via Shopify', 'wpsb');
            $this->method_description = __('Sends the order to Shopify hosted checkout for payment. Only SKU, price, quantity and customer info are transmitted; product images and descriptions stay in WordPress.', 'wpsb');
            $this->has_fields         = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title', __('Secure checkout', 'wpsb'));
            $this->description  = $this->get_option('description', __('You will be redirected to our secure payment page to complete your purchase.', 'wpsb'));
            $this->enabled      = $this->get_option('enabled', 'yes');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title'   => __('Enable/Disable', 'wpsb'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable "Pay via Shopify"', 'wpsb'),
                    'default' => 'yes',
                ],
                'title' => [
                    'title'       => __('Title', 'wpsb'),
                    'type'        => 'text',
                    'description' => __('Payment method name shown to the customer at checkout.', 'wpsb'),
                    'default'     => __('Secure checkout', 'wpsb'),
                    'desc_tip'    => true,
                ],
                'description' => [
                    'title'       => __('Description', 'wpsb'),
                    'type'        => 'textarea',
                    'default'     => __('You will be redirected to our secure payment page to complete your purchase.', 'wpsb'),
                ],
            ];
        }

        /**
         * Build the Shopify checkout and hand the customer off. On failure we
         * keep the order (as failed) and surface the reason so nothing is lost.
         */
        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice(__('Order not found.', 'wpsb'), 'error');
                return ['result' => 'failure'];
            }

            $url = WPSB_Checkout::instance()->create_checkout_from_order($order);
            if (is_wp_error($url)) {
                $order->update_status('failed', $url->get_error_message());
                wc_add_notice($url->get_error_message(), 'error');
                return ['result' => 'failure'];
            }

            // Awaiting off-site payment. The order-poll cron marks it paid once
            // the matching Shopify order lands (see WPSB_Orders).
            $order->update_status('pending', __('Redirected to Shopify for payment.', 'wpsb'));
            $order->add_order_note(__('Customer sent to Shopify hosted checkout (source: Online Store).', 'wpsb'));

            // WooCommerce empties the cart itself on a successful result.
            return [
                'result'   => 'success',
                'redirect' => $url,
            ];
        }
    }

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WPSB_Gateway';
        return $gateways;
    });
}
