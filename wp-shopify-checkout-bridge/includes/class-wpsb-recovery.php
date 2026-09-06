<?php
/**
 * Multi-touch abandoned-cart recovery. A 15-minute cron walks a stage machine
 * (delays default 1h / 24h / 72h), generates or reuses AI-written copy per cart,
 * and sends it via Klaviyo (if configured) or a styled wp_mail, honouring an
 * unsubscribe list and List-Unsubscribe header.
 */

if (!defined('ABSPATH')) { exit; }

class WPSB_Recovery {

    const CRON_HOOK = 'wpsb_recovery_cron';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(self::CRON_HOOK, [$this, 'run']);
        add_action('init', [$this, 'maybe_schedule']);
        add_action('init', [$this, 'maybe_unsubscribe']);
    }

    public function maybe_schedule() {
        if (get_option('wpsb_recovery_enabled') !== '1') {
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 120, 'wpsb_15min', self::CRON_HOOK);
        }
    }

    /** Delays (in hours) between touches, from settings; defaults to 1,24,72. */
    private function sequence() {
        $raw = get_option('wpsb_recovery_sequence', '1,24,72');
        $parts = array_filter(array_map('intval', explode(',', $raw)), function ($n) {
            return $n > 0;
        });
        if (!$parts) {
            $parts = [1, 24, 72];
        }
        $max = (int) get_option('wpsb_recovery_max_touches', 3);
        if ($max > 0) {
            $parts = array_slice(array_values($parts), 0, $max);
        }
        return array_values($parts);
    }

    /**
     * Find carts whose next touch is due and send it. A cart becomes eligible
     * once it has an email and is still open; the first run seeds first/next
     * touch times from the sequence.
     */
    public function run() {
        if (get_option('wpsb_recovery_enabled') !== '1') {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'wpsb_carts';
        $now   = current_time('mysql');
        $seq   = $this->sequence();

        // Seed touch schedule for freshly abandoned carts (open, has email, no
        // schedule yet, older than the first delay).
        $first_delay = $seq[0] * HOUR_IN_SECONDS;
        $threshold   = gmdate('Y-m-d H:i:s', strtotime($now) - $first_delay);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET next_recovery_at = %s
              WHERE status = 'open'
                AND email IS NOT NULL AND email != ''
                AND recovery_stage = 0
                AND next_recovery_at IS NULL
                AND updated_at <= %s",
            $now,
            $threshold
        ));

        $due = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
              WHERE status = 'open'
                AND email IS NOT NULL AND email != ''
                AND next_recovery_at IS NOT NULL
                AND next_recovery_at <= %s
              ORDER BY next_recovery_at ASC
              LIMIT 20",
            $now
        ));

        foreach ($due as $cart) {
            $this->process_cart($cart, $seq);
        }
    }

    private function process_cart($cart, $seq) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpsb_carts';
        $stage = (int) $cart->recovery_stage;

        if ($stage >= count($seq)) {
            // Exhausted: stop touching this cart.
            $wpdb->update($table, ['next_recovery_at' => null], ['id' => $cart->id]);
            return;
        }
        if ($this->is_unsubscribed($cart->email)) {
            $wpdb->update($table, ['next_recovery_at' => null], ['id' => $cart->id]);
            return;
        }

        $copy = $this->get_copy($cart);
        $sent = apply_filters('wpsb_recovery_send', null, $cart, $copy);
        if ($sent === null) {
            $sent = $this->deliver($cart, $copy);
        }

        $update = [
            'recovery_sent' => 1,
            'recovery_stage'=> $stage + 1,
            'updated_at'    => current_time('mysql'),
        ];
        if (empty($cart->first_recovery_at)) {
            $update['first_recovery_at'] = current_time('mysql');
        }
        // Schedule the next touch, or stop if this was the last.
        $next_stage = $stage + 1;
        if ($next_stage < count($seq)) {
            $update['next_recovery_at'] = gmdate('Y-m-d H:i:s', time() + $seq[$next_stage] * HOUR_IN_SECONDS);
        } else {
            $update['next_recovery_at'] = null;
        }
        $wpdb->update($table, $update, ['id' => $cart->id]);
    }

    /** Return cached AI copy, generate it, or fall back to a default template. */
    private function get_copy($cart) {
        if (!empty($cart->ai_subject) && !empty($cart->ai_body)) {
            return ['subject' => $cart->ai_subject, 'body' => $cart->ai_body];
        }
        if (get_option('wpsb_recovery_ai_enabled') === '1') {
            $ai = WPSB_AI_Sync::instance()->generate_recovery_copy($cart);
            if ($ai && !empty($ai['subject']) && !empty($ai['body'])) {
                global $wpdb;
                $wpdb->update($wpdb->prefix . 'wpsb_carts', [
                    'ai_subject'      => $ai['subject'],
                    'ai_body'         => $ai['body'],
                    'ai_generated_at' => current_time('mysql'),
                ], ['id' => $cart->id]);
                return $ai;
            }
        }
        return $this->default_copy($cart);
    }

    private function default_copy($cart) {
        $store = get_option('wpsb_store_name', get_bloginfo('name'));
        $subject = sprintf(__('You left something at %s', 'wpsb'), $store);
        $url = $cart->checkout_url ?: home_url('/');
        $body = sprintf(
            "<p>%s</p><p><a href=\"%s\">%s</a></p>",
            esc_html__('Your cart is still saved — pick up right where you left off.', 'wpsb'),
            esc_url($url),
            esc_html__('Complete your order', 'wpsb')
        );
        return ['subject' => $subject, 'body' => $body];
    }

    /** Deliver via Klaviyo when a key is set, else styled wp_mail. */
    private function deliver($cart, $copy) {
        $klaviyo = get_option('wpsb_klaviyo_api_key', '');
        if ($klaviyo) {
            return $this->send_klaviyo($cart, $copy, $klaviyo);
        }
        return $this->send_mail($cart, $copy);
    }

    private function send_mail($cart, $copy) {
        $from_name  = get_option('wpsb_recovery_from_name', get_bloginfo('name'));
        $from_email = get_option('wpsb_recovery_from_email', get_bloginfo('admin_email'));
        $unsub      = $this->unsubscribe_url($cart->email);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', $from_name, $from_email),
            sprintf('List-Unsubscribe: <%s>', $unsub),
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        ];
        $html = $this->wrap_html($copy['body'], $unsub);
        return wp_mail($cart->email, $copy['subject'], $html, $headers);
    }

    private function send_klaviyo($cart, $copy, $key) {
        $response = wp_remote_post('https://a.klaviyo.com/api/events/', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Klaviyo-API-Key ' . $key,
                'Content-Type'  => 'application/json',
                'revision'      => '2024-10-15',
            ],
            'body' => wp_json_encode([
                'data' => [
                    'type' => 'event',
                    'attributes' => [
                        'metric'     => ['data' => ['type' => 'metric', 'attributes' => ['name' => 'WPSB Abandoned Cart']]],
                        'profile'    => ['data' => ['type' => 'profile', 'attributes' => ['email' => $cart->email]]],
                        'properties' => [
                            'checkout_url' => $cart->checkout_url,
                            'subject'      => $copy['subject'],
                            'stage'        => (int) $cart->recovery_stage + 1,
                        ],
                    ],
                ],
            ]),
        ]);
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) < 300;
    }

    private function wrap_html($body, $unsub) {
        return '<div style="font-family:system-ui,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1a1a1a">'
            . $body
            . '<hr style="border:none;border-top:1px solid #eee;margin:24px 0">'
            . '<p style="font-size:12px;color:#888">'
            . sprintf('<a href="%s" style="color:#888">%s</a>', esc_url($unsub), esc_html__('Unsubscribe', 'wpsb'))
            . '</p></div>';
    }

    /* ------------------------------------------------------------------ */
    /* Unsubscribe                                                         */
    /* ------------------------------------------------------------------ */

    private function unsubscribe_url($email) {
        return add_query_arg([
            'wpsb_unsub' => rawurlencode($email),
            'wpsb_k'     => $this->unsub_token($email),
        ], home_url('/'));
    }

    private function unsub_token($email) {
        return substr(wp_hash('wpsb_unsub_' . strtolower($email)), 0, 20);
    }

    public function maybe_unsubscribe() {
        if (empty($_GET['wpsb_unsub']) || empty($_GET['wpsb_k'])) {
            return;
        }
        $email = sanitize_email(wp_unslash($_GET['wpsb_unsub']));
        $token = sanitize_text_field(wp_unslash($_GET['wpsb_k']));
        if (!$email || !hash_equals($this->unsub_token($email), $token)) {
            return;
        }
        $list = get_option('wpsb_unsub_list', []);
        if (!is_array($list)) {
            $list = [];
        }
        $list[strtolower($email)] = time();
        update_option('wpsb_unsub_list', $list, false);
        wp_die(esc_html__('You have been unsubscribed from cart reminders.', 'wpsb'), '', ['response' => 200]);
    }

    private function is_unsubscribed($email) {
        $list = get_option('wpsb_unsub_list', []);
        return is_array($list) && isset($list[strtolower($email)]);
    }
}
