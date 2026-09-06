<?php
/**
 * Recovery dashboard (Settings → Shopify Recovery): headline KPIs, a 14-day
 * trend, and the most recent carts. Read-only; all figures come from the
 * wpsb_carts table.
 */

if (!defined('ABSPATH')) { exit; }

class WPSB_Analytics {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'menu'], 20);
    }

    public function menu() {
        add_submenu_page(
            WPSB_Settings::MENU_SLUG,
            __('Shopify Recovery', 'wpsb'),
            __('Recovery', 'wpsb'),
            'manage_options',
            'wpsb-recovery',
            [$this, 'render']
        );
    }

    private function kpis() {
        global $wpdb;
        $table = $wpdb->prefix . 'wpsb_carts';
        $row = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status='converted' THEN 1 ELSE 0 END) AS converted,
                SUM(CASE WHEN recovery_attributed=1 THEN 1 ELSE 0 END) AS attributed,
                SUM(CASE WHEN status='converted' THEN order_total ELSE 0 END) AS revenue,
                SUM(CASE WHEN recovery_attributed=1 THEN order_total ELSE 0 END) AS recovered_revenue,
                SUM(CASE WHEN recovery_sent=1 THEN 1 ELSE 0 END) AS touched
             FROM {$table}",
            ARRAY_A
        );
        $row = $row ?: [];
        $total     = (int) ($row['total'] ?? 0);
        $converted = (int) ($row['converted'] ?? 0);
        return [
            'total'             => $total,
            'converted'         => $converted,
            'attributed'        => (int) ($row['attributed'] ?? 0),
            'touched'           => (int) ($row['touched'] ?? 0),
            'revenue'           => (float) ($row['revenue'] ?? 0),
            'recovered_revenue' => (float) ($row['recovered_revenue'] ?? 0),
            'conv_rate'         => $total ? round($converted / $total * 100, 1) : 0.0,
        ];
    }

    private function trend() {
        global $wpdb;
        $table = $wpdb->prefix . 'wpsb_carts';
        $rows = $wpdb->get_results(
            "SELECT DATE(created_at) AS d,
                    COUNT(*) AS carts,
                    SUM(CASE WHEN status='converted' THEN 1 ELSE 0 END) AS converted
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             GROUP BY DATE(created_at)
             ORDER BY d ASC",
            ARRAY_A
        );
        return $rows ?: [];
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $k = $this->kpis();
        $currency = get_option('wpsb_currency', 'USD');
        ?>
        <div class="wrap wpsb-admin">
            <h1><?php esc_html_e('Shopify Recovery', 'wpsb'); ?></h1>

            <div class="wpsb-kpis">
                <?php
                $this->kpi(__('Carts tracked', 'wpsb'), number_format_i18n($k['total']));
                $this->kpi(__('Converted', 'wpsb'), number_format_i18n($k['converted']) . ' (' . $k['conv_rate'] . '%)');
                $this->kpi(__('Recovery-attributed', 'wpsb'), number_format_i18n($k['attributed']));
                $this->kpi(__('Reminders sent', 'wpsb'), number_format_i18n($k['touched']));
                $this->kpi(__('Total revenue', 'wpsb'), wpsb_format_price($k['revenue'], $currency));
                $this->kpi(__('Recovered revenue', 'wpsb'), wpsb_format_price($k['recovered_revenue'], $currency));
                ?>
            </div>

            <h2><?php esc_html_e('Last 14 days', 'wpsb'); ?></h2>
            <?php $this->render_trend(); ?>

            <h2><?php esc_html_e('Recent carts', 'wpsb'); ?></h2>
            <?php $this->render_recent($currency); ?>
        </div>
        <?php
    }

    private function kpi($label, $value) {
        printf(
            '<div class="wpsb-kpi"><div class="wpsb-kpi__value">%s</div><div class="wpsb-kpi__label">%s</div></div>',
            esc_html($value),
            esc_html($label)
        );
    }

    private function render_trend() {
        $rows = $this->trend();
        if (!$rows) {
            echo '<p>' . esc_html__('No data yet.', 'wpsb') . '</p>';
            return;
        }
        $max = 1;
        foreach ($rows as $r) {
            $max = max($max, (int) $r['carts']);
        }
        echo '<div class="wpsb-bars">';
        foreach ($rows as $r) {
            $h = (int) round((int) $r['carts'] / $max * 100);
            printf(
                '<div class="wpsb-bar" title="%s: %d carts, %d converted"><span style="height:%d%%"></span><small>%s</small></div>',
                esc_attr($r['d']),
                (int) $r['carts'],
                (int) $r['converted'],
                $h,
                esc_html(substr($r['d'], 5))
            );
        }
        echo '</div>';
    }

    private function render_recent($currency) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpsb_carts';
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 25");
        if (!$rows) {
            echo '<p>' . esc_html__('No carts recorded yet.', 'wpsb') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('When', 'wpsb'), __('Email', 'wpsb'), __('Source', 'wpsb'), __('Stage', 'wpsb'), __('Status', 'wpsb'), __('Order', 'wpsb')] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $utm = json_decode($r->utm_data, true);
            $source = is_array($utm) && !empty($utm['utm_source']) ? $utm['utm_source'] : '—';
            $order = $r->order_total ? wpsb_format_price($r->order_total, $r->order_currency ?: $currency) : '—';
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                esc_html($r->created_at),
                esc_html($r->email ?: '—'),
                esc_html($source),
                (int) $r->recovery_stage,
                esc_html($r->status),
                esc_html($order)
            );
        }
        echo '</tbody></table>';
    }
}
