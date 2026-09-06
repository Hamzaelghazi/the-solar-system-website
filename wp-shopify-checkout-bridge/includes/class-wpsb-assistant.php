<?php
/**
 * Settings → Shopify Assistant. A health check with plain-English diagnostics,
 * a small set of whitelisted one-click fixes, and a read-only "Ask" box backed
 * by Claude. No secrets ever leave the site beyond the AI call itself; every
 * action is nonce + capability gated.
 */

if (!defined('ABSPATH')) { exit; }

class WPSB_Assistant {

    private static $instance = null;

    /** Whitelisted fixes: id => [label, callable]. Nothing else can run. */
    private $fixes = [];

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_post_wpsb_fix', [$this, 'handle_fix']);
        add_action('wp_ajax_wpsb_ask', [$this, 'ajax_ask']);

        $this->fixes = [
            'reschedule_crons' => [
                'label' => __('Re-schedule background jobs', 'wpsb'),
                'run'   => [$this, 'fix_reschedule_crons'],
            ],
            'flush_product_cache' => [
                'label' => __('Clear product cache', 'wpsb'),
                'run'   => [$this, 'fix_flush_product_cache'],
            ],
        ];
    }

    public function menu() {
        add_submenu_page(
            WPSB_Settings::MENU_SLUG,
            __('Shopify Assistant', 'wpsb'),
            __('Assistant', 'wpsb'),
            'manage_options',
            'wpsb-assistant',
            [$this, 'render']
        );
    }

    /* ------------------------------------------------------------------ */
    /* Diagnostics                                                         */
    /* ------------------------------------------------------------------ */

    private function checks() {
        $api = WPSB_Shopify_API::instance();
        $out = [];

        $out[] = $this->check(
            !empty(get_option('wpsb_shop_domain')),
            __('Shop domain is set.', 'wpsb'),
            __('Shop domain is missing — set it on the Settings page.', 'wpsb')
        );
        $out[] = $this->check(
            !empty(get_option('wpsb_storefront_token')),
            __('Storefront token is set.', 'wpsb'),
            __('Storefront token is missing — checkout cannot be built without it.', 'wpsb')
        );
        $out[] = $this->check(
            class_exists('WooCommerce'),
            __('WooCommerce is active.', 'wpsb'),
            __('WooCommerce is not active — catalogue features are disabled.', 'wpsb')
        );
        $out[] = $this->check(
            $api->has_admin(),
            __('Admin token is set (recovery + push enabled).', 'wpsb'),
            __('No Admin token — order recovery measurement and product push are off.', 'wpsb'),
            'warning'
        );
        $out[] = $this->check(
            (bool) wp_next_scheduled('wpsb_order_poll_cron') || !$api->has_admin(),
            __('Order-poll job is scheduled.', 'wpsb'),
            __('Order-poll job is not scheduled — try the "Re-schedule background jobs" fix.', 'wpsb'),
            'warning'
        );

        // Live Storefront ping.
        if ($api->is_configured()) {
            $ping = $api->graphql('{ shop { name } }');
            $out[] = $this->check(
                !is_wp_error($ping) && !empty($ping['shop']['name']),
                is_wp_error($ping) ? '' : sprintf(__('Connected to Shopify: %s.', 'wpsb'), $ping['shop']['name'] ?? ''),
                is_wp_error($ping) ? sprintf(__('Storefront API error: %s', 'wpsb'), $ping->get_error_message()) : __('Storefront API did not return the shop.', 'wpsb')
            );
        }

        return $out;
    }

    private function check($ok, $ok_msg, $fail_msg, $fail_level = 'error') {
        return [
            'ok'    => (bool) $ok,
            'level' => $ok ? 'ok' : $fail_level,
            'msg'   => $ok ? $ok_msg : $fail_msg,
        ];
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap wpsb-admin">
            <h1><?php esc_html_e('Shopify Assistant', 'wpsb'); ?></h1>
            <?php $this->flash(); ?>

            <h2><?php esc_html_e('Health check', 'wpsb'); ?></h2>
            <ul class="wpsb-health">
                <?php foreach ($this->checks() as $c): if (empty($c['msg'])) continue; ?>
                    <li class="wpsb-health__item is-<?php echo esc_attr($c['level']); ?>">
                        <span class="wpsb-dot"></span><?php echo esc_html($c['msg']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <h2><?php esc_html_e('One-click fixes', 'wpsb'); ?></h2>
            <div class="wpsb-actions">
                <?php foreach ($this->fixes as $id => $fix): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wpsb-action">
                        <input type="hidden" name="action" value="wpsb_fix">
                        <input type="hidden" name="fix" value="<?php echo esc_attr($id); ?>">
                        <?php wp_nonce_field('wpsb_fix_' . $id); ?>
                        <button class="button"><?php echo esc_html($fix['label']); ?></button>
                    </form>
                <?php endforeach; ?>
            </div>

            <h2><?php esc_html_e('Ask (read-only)', 'wpsb'); ?></h2>
            <?php if (!get_option('wpsb_anthropic_key')): ?>
                <p class="description"><?php esc_html_e('Add an Anthropic API key on the Settings page to enable the Ask box.', 'wpsb'); ?></p>
            <?php else: ?>
                <textarea id="wpsb-ask" class="large-text" rows="3" placeholder="<?php esc_attr_e('e.g. Why might my products show as not linked?', 'wpsb'); ?>"></textarea>
                <p><button class="button button-primary" id="wpsb-ask-btn"><?php esc_html_e('Ask', 'wpsb'); ?></button></p>
                <div id="wpsb-ask-out" class="wpsb-ask-out"></div>
                <script>
                (function(){
                    var btn=document.getElementById('wpsb-ask-btn');
                    if(!btn)return;
                    btn.addEventListener('click',function(){
                        var q=document.getElementById('wpsb-ask').value.trim();
                        var out=document.getElementById('wpsb-ask-out');
                        if(!q){return;}
                        out.textContent='…';
                        var fd=new FormData();
                        fd.append('action','wpsb_ask');
                        fd.append('nonce','<?php echo esc_js(wp_create_nonce('wpsb_ask')); ?>');
                        fd.append('q',q);
                        fetch(ajaxurl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
                            out.textContent=(j&&j.data&&j.data.answer)?j.data.answer:(j&&j.data&&j.data.message)||'Error';
                        }).catch(function(){out.textContent='Error';});
                    });
                })();
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Actions                                                             */
    /* ------------------------------------------------------------------ */

    public function handle_fix() {
        $id = isset($_POST['fix']) ? sanitize_key(wp_unslash($_POST['fix'])) : '';
        if (!current_user_can('manage_options') || !check_admin_referer('wpsb_fix_' . $id)) {
            wp_die(esc_html__('Permission denied.', 'wpsb'));
        }
        if (!isset($this->fixes[$id])) {
            $this->redirect_with(__('Unknown fix.', 'wpsb'), 'error');
        }
        $msg = call_user_func($this->fixes[$id]['run']);
        $this->redirect_with($msg ?: __('Done.', 'wpsb'));
    }

    private function fix_reschedule_crons() {
        foreach (['wpsb_order_poll_cron', 'wpsb_recovery_cron', 'wpsb_product_sync_cron'] as $hook) {
            $ts = wp_next_scheduled($hook);
            while ($ts) {
                wp_unschedule_event($ts, $hook);
                $ts = wp_next_scheduled($hook);
            }
        }
        WPSB_Orders::instance()->maybe_schedule();
        WPSB_Recovery::instance()->maybe_schedule();
        WPSB_AI_Sync::instance()->maybe_schedule();
        return __('Background jobs re-scheduled.', 'wpsb');
    }

    private function fix_flush_product_cache() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wpsb_ph\_%' OR option_name LIKE '\_transient\_timeout\_wpsb_ph\_%'");
        return __('Product cache cleared.', 'wpsb');
    }

    /**
     * Read-only Ask box. Sends the question plus a compact, non-secret site
     * summary to Claude and returns the answer. No API keys or tokens are
     * included in the prompt.
     */
    public function ajax_ask() {
        check_ajax_referer('wpsb_ask', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wpsb')]);
        }
        $q = isset($_POST['q']) ? sanitize_textarea_field(wp_unslash($_POST['q'])) : '';
        if (!$q) {
            wp_send_json_error(['message' => __('Empty question.', 'wpsb')]);
        }

        $context = wp_json_encode([
            'woocommerce_active' => class_exists('WooCommerce'),
            'has_storefront'     => (bool) get_option('wpsb_storefront_token'),
            'has_admin_token'    => WPSB_Shopify_API::instance()->has_admin(),
            'recovery_enabled'   => get_option('wpsb_recovery_enabled') === '1',
            'plugin_version'     => WPSB_VERSION,
        ]);

        $system = 'You are a support assistant embedded in the "WP Shopify Checkout Bridge" WordPress plugin. '
            . 'Answer briefly and practically. You cannot change any setting; only explain and suggest. '
            . 'Never ask for or reveal API keys or tokens.';
        $answer = WPSB_AI_Sync::instance()->claude($system, "Site context: {$context}\n\nQuestion: {$q}", 500);

        if (is_wp_error($answer)) {
            wp_send_json_error(['message' => $answer->get_error_message()]);
        }
        wp_send_json_success(['answer' => $answer]);
    }

    private function redirect_with($message, $type = 'success') {
        set_transient('wpsb_assistant_flash', ['type' => $type, 'msg' => $message], 60);
        wp_safe_redirect(admin_url('admin.php?page=wpsb-assistant'));
        exit;
    }

    private function flash() {
        $flash = get_transient('wpsb_assistant_flash');
        if (!$flash) {
            return;
        }
        delete_transient('wpsb_assistant_flash');
        $class = ($flash['type'] === 'error') ? 'notice-error' : 'notice-success';
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($flash['msg']));
    }
}
