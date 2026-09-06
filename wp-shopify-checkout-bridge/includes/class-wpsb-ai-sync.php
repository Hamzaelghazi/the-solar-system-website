<?php
/**
 * Two AI/sync jobs:
 *   1. Anthropic (Claude) helper used for recovery copy and the Assistant.
 *   2. An hourly price & stock re-sync that refreshes each linked WooCommerce
 *      product from live Shopify data so WordPress prices never drift from what
 *      shoppers pay at checkout (price mismatch is the #1 chargeback trigger).
 */

if (!defined('ABSPATH')) { exit; }

class WPSB_AI_Sync {

    const CRON_HOOK = 'wpsb_product_sync_cron';

    /** Default model. Overridable via the wpsb_anthropic_model filter. */
    const DEFAULT_MODEL = 'claude-sonnet-5';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(self::CRON_HOOK, [$this, 'sync_prices_batch']);
        add_action('init', [$this, 'maybe_schedule']);
    }

    public function maybe_schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK) && class_exists('WooCommerce')) {
            wp_schedule_event(time() + 180, 'hourly', self::CRON_HOOK);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Anthropic (Claude)                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Low-level Claude call via the Messages API. Returns the assistant text or
     * WP_Error. The API key never leaves the server.
     *
     * @return string|WP_Error
     */
    public function claude($system, $user, $max_tokens = 512) {
        $key = get_option('wpsb_anthropic_key', '');
        if (!$key) {
            return new WP_Error('wpsb_no_ai', __('Anthropic API key not configured.', 'wpsb'));
        }
        $model = apply_filters('wpsb_anthropic_model', self::DEFAULT_MODEL);

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'      => $model,
                'max_tokens' => (int) $max_tokens,
                'system'     => $system,
                'messages'   => [
                    ['role' => 'user', 'content' => $user],
                ],
            ]),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $msg = $body['error']['message'] ?? sprintf(__('Anthropic API HTTP %d.', 'wpsb'), $code);
            return new WP_Error('wpsb_ai_http_' . $code, $msg);
        }
        $text = '';
        if (!empty($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'];
                }
            }
        }
        return trim($text);
    }

    /**
     * Generate a subject + HTML body for one abandoned cart. Returns
     * ['subject'=>..., 'body'=>...] or null on failure (caller falls back to a
     * default template).
     */
    public function generate_recovery_copy($cart) {
        $items = json_decode($cart->cart_data, true);
        $names = [];
        if (is_array($items)) {
            foreach ($items as $it) {
                if (!empty($it['title'])) {
                    $names[] = $it['title'];
                }
            }
        }
        $store = get_option('wpsb_store_name', get_bloginfo('name'));
        $tone  = get_option('wpsb_ai_tone', 'friendly');
        $stage = (int) $cart->recovery_stage + 1;

        $system = "You write concise abandoned-cart reminder emails for the store \"{$store}\". "
            . "Tone: {$tone}. Return STRICT JSON only, shape: {\"subject\":\"...\",\"body\":\"<p>...</p>\"}. "
            . "The body is safe HTML (p/a/strong only), no styles, no images. Never invent discounts. "
            . "This is reminder #{$stage}.";
        $user = 'Items left in cart: ' . ($names ? implode(', ', array_slice($names, 0, 8)) : 'one or more products') . '.';

        $out = $this->claude($system, $user, 400);
        if (is_wp_error($out) || !$out) {
            return null;
        }
        $json = json_decode($this->extract_json($out), true);
        if (!is_array($json) || empty($json['subject']) || empty($json['body'])) {
            return null;
        }
        return [
            'subject' => sanitize_text_field($json['subject']),
            'body'    => wp_kses($json['body'], ['p' => [], 'a' => ['href' => []], 'strong' => [], 'br' => []]),
        ];
    }

    private function extract_json($text) {
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return $text;
        }
        return substr($text, $start, $end - $start + 1);
    }

    /* ------------------------------------------------------------------ */
    /* Price & stock re-sync                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Refresh a bounded batch of linked WooCommerce products from live Shopify
     * data. Rotates through the catalogue via a stored offset so the hourly cron
     * eventually covers everything without long-running requests.
     *
     * @param int $limit batch size.
     * @return array{updated:int,checked:int}
     */
    public function sync_prices_batch($limit = 25) {
        if (!class_exists('WooCommerce')) {
            return ['updated' => 0, 'checked' => 0];
        }
        global $wpdb;
        $offset = (int) get_option('wpsb_sync_offset', 0);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
              WHERE meta_key = '_wpsb_handle' AND meta_value != ''
              ORDER BY post_id ASC
              LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));

        if (!$ids) {
            update_option('wpsb_sync_offset', 0, false); // wrap around
            return ['updated' => 0, 'checked' => 0];
        }

        $api = WPSB_Shopify_API::instance();
        $updated = 0;
        foreach ($ids as $pid) {
            $handle = get_post_meta($pid, '_wpsb_handle', true);
            $product = $api->get_product_by_handle($handle);
            $wc = wc_get_product($pid);
            if (!$wc) {
                continue;
            }
            if (!$product) {
                // Deleted in Shopify → mark out of stock, never delete.
                if ($wc->get_stock_status() !== 'outofstock') {
                    $wc->set_stock_status('outofstock');
                    $wc->save();
                    $updated++;
                }
                continue;
            }
            if ($this->apply_product($wc, $product, $pid)) {
                $updated++;
            }
        }

        update_option('wpsb_sync_offset', $offset + count($ids), false);
        return ['updated' => $updated, 'checked' => count($ids)];
    }

    /** Full-catalogue on-demand sync (Sync prices now button). */
    public function sync_all() {
        update_option('wpsb_sync_offset', 0, false);
        $total = ['updated' => 0, 'checked' => 0];
        // Bounded loop so a huge catalogue can't run unbounded in one request.
        for ($i = 0; $i < 200; $i++) {
            $res = $this->sync_prices_batch(25);
            $total['updated'] += $res['updated'];
            $total['checked'] += $res['checked'];
            if ($res['checked'] === 0) {
                break;
            }
        }
        update_option('wpsb_sync_offset', 0, false);
        return $total;
    }

    private function apply_product($wc, $product, $pid) {
        $variant = null;
        foreach ($product['variants'] as $v) {
            if (!empty($v['available'])) {
                $variant = $v;
                break;
            }
        }
        if (!$variant) {
            $variant = $product['variants'][0] ?? null;
        }
        if (!$variant) {
            return false;
        }

        $changed = false;
        $regular = (string) ($variant['compare_at'] ?: $variant['price']);
        $sale    = ($variant['compare_at'] && $variant['compare_at'] > $variant['price']) ? (string) $variant['price'] : '';

        if ($wc->get_regular_price() !== $regular) {
            $wc->set_regular_price($regular);
            $changed = true;
        }
        if ($wc->get_sale_price() !== $sale) {
            $wc->set_sale_price($sale);
            $changed = true;
        }
        $status = $variant['available'] ? 'instock' : 'outofstock';
        if ($wc->get_stock_status() !== $status) {
            $wc->set_stock_status($status);
            $changed = true;
        }
        if ($changed) {
            $wc->save();
            update_post_meta($pid, '_wpsb_variant_id', $variant['id']);
            update_post_meta($pid, '_wpsb_snapshot', wp_json_encode([
                'price'      => $variant['price'],
                'compare_at' => $variant['compare_at'],
                'available'  => $variant['available'],
                'synced_at'  => current_time('mysql'),
            ]));
        }
        return $changed;
    }
}

// Self-bootstrap: the main plugin doesn't instantiate this class in init(), so
// register its cron hook + schedule check as soon as the file is loaded.
WPSB_AI_Sync::instance();
