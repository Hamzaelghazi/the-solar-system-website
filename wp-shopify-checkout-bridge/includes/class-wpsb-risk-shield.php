<?php
/**
 * Risk shield — uniform bot / fraud protection on the checkout-build step.
 *
 * IMPORTANT: this module treats every visitor the same. It exists to slow down
 * automated checkout abuse (scripted carts, credential/BIN testing, scraping),
 * NOT to detect reviewers, crawlers, or payment processors and show them a
 * different experience. It must never be used for cloaking. Everything it does
 * is a transparent, symmetric rate/behaviour check.
 *
 * The gate:
 *   - rate-limits how many checkouts a single session can build per hour;
 *   - can require a minimum dwell time before the first checkout;
 *   - can require at least one genuine interaction signal (set by the front-end);
 *   - can flag datacenter/VPN IPs for later review (flag only — no hidden block).
 */

if (!defined('ABSPATH')) { exit; }

class WPSB_Risk_Shield {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Front-end reports a lightweight interaction signal (scroll / click /
        // keypress). Stored server-side per session so the gate can require it.
        add_action('wp_ajax_wpsb_signal', [$this, 'ajax_signal']);
        add_action('wp_ajax_nopriv_wpsb_signal', [$this, 'ajax_signal']);
    }

    /**
     * Gate called right before a checkout is built. Returns true when the
     * request looks like a real shopper, or WP_Error to abort. All checks are
     * applied identically to everyone.
     *
     * @return true|WP_Error
     */
    public function check() {
        $sid = WP_Shopify_Bridge::session_id();
        $signals = $this->signals($sid);

        // 1. Rate limit — same ceiling for every session.
        $limit = (int) get_option('wpsb_rs_rate_limit', 30);
        if ($limit > 0) {
            $key   = 'wpsb_rl_' . $sid;
            $count = (int) get_transient($key);
            if ($count >= $limit) {
                return new WP_Error('wpsb_rate_limited', __('Too many checkout attempts. Please wait a moment and try again.', 'wpsb'));
            }
            set_transient($key, $count + 1, HOUR_IN_SECONDS);
        }

        // 2. Minimum dwell time before the first checkout.
        $min = (int) get_option('wpsb_rs_min_time_seconds', 0);
        if ($min > 0 && !empty($signals['session_start'])) {
            $elapsed = time() - (int) $signals['session_start'];
            if ($elapsed < $min) {
                return new WP_Error('wpsb_too_fast', __('Please take a moment to review your cart before checking out.', 'wpsb'));
            }
        }

        // 3. Require a genuine interaction signal.
        if (get_option('wpsb_rs_require_behavior') === '1' && empty($signals['interacted'])) {
            return new WP_Error('wpsb_no_behavior', __('Please interact with the page before checking out.', 'wpsb'));
        }

        // 4. VPN / datacenter flag — recorded for review only, never a silent,
        //    audience-specific block.
        if (get_option('wpsb_rs_block_vpn') === '1' && $this->looks_like_datacenter()) {
            $this->flag($sid, 'datacenter_ip');
        }

        return true;
    }

    public function ajax_signal() {
        check_ajax_referer('wpsb_nonce', 'nonce');
        $sid = WP_Shopify_Bridge::session_id();
        $signals = $this->signals($sid);
        $signals['interacted'] = 1;
        if (empty($signals['session_start'])) {
            $signals['session_start'] = time();
        }
        set_transient('wpsb_sig_' . $sid, $signals, HOUR_IN_SECONDS);
        wp_send_json_success();
    }

    private function signals($sid) {
        $signals = get_transient('wpsb_sig_' . $sid);
        return is_array($signals) ? $signals : [];
    }

    private function flag($sid, $reason) {
        $flags = get_option('wpsb_rs_flags', []);
        if (!is_array($flags)) {
            $flags = [];
        }
        $flags[$sid] = ['reason' => $reason, 'at' => time(), 'ip' => $this->client_ip()];
        // Keep the flag log bounded.
        if (count($flags) > 500) {
            $flags = array_slice($flags, -500, null, true);
        }
        update_option('wpsb_rs_flags', $flags, false);
    }

    /**
     * Heuristic only: reverse-DNS / header hints that a request originates from
     * a hosting/VPN range. Deliberately conservative — this only sets a review
     * flag, it does not block, so a false positive costs nothing to a shopper.
     */
    private function looks_like_datacenter() {
        // Forwarded-for chains longer than one hop are common for proxies/VPNs.
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $hops = explode(',', wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
            if (count($hops) > 2) {
                return true;
            }
        }
        $ip = $this->client_ip();
        if (!$ip) {
            return false;
        }
        $host = @gethostbyaddr($ip);
        if ($host && preg_match('/(amazonaws|googleusercontent|azure|digitalocean|linode|ovh|hetzner|vpn|proxy)/i', $host)) {
            return true;
        }
        return false;
    }

    private function client_ip() {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', wp_unslash($_SERVER[$k]))[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }
}

// Self-bootstrap: the main plugin doesn't instantiate this class in init(), so
// register its AJAX hooks as soon as the file is loaded.
WPSB_Risk_Shield::instance();
