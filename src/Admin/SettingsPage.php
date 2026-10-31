<?php

namespace Opotopo\SellerApi\WooCommerce\Admin;

use Opotopo\SellerApi\WooCommerce\Plugin;
use Opotopo\SellerApi\WooCommerce\Settings;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Problem;
use WC_Settings_Page;

final class SettingsPage extends WC_Settings_Page
{
    public const ID = 'opotopo';

    private const STATUS_FIELD = 'opotopo_seller_api_status';

    private const CAPABILITY = 'manage_woocommerce';

    public function __construct(private readonly Plugin $plugin)
    {
        $this->id = self::ID;
        $this->label = __('OPOTOPO', Plugin::TEXT_DOMAIN);
        parent::__construct();
        add_action('woocommerce_admin_field_'.self::STATUS_FIELD, [$this, 'status']);
    }

    public static function requestSync(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to start the synchronization.', Plugin::TEXT_DOMAIN), 403);
        }
        check_admin_referer(Plugin::SYNC_REQUEST);
        Plugin::queue();
        wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab='.self::ID));
        exit;
    }

    protected function get_settings_for_default_section(): array
    {
        return [
            ['id' => 'opotopo_seller_api_connection', 'type' => 'title', 'title' => __('Connection', Plugin::TEXT_DOMAIN)],
            [
                'id' => Settings::URL,
                'type' => 'url',
                'title' => __('Platform address', Plugin::TEXT_DOMAIN),
                'default' => Settings::DEFAULT_URL,
            ],
            [
                'id' => Settings::KEY,
                'type' => 'password',
                'title' => __('API key', Plugin::TEXT_DOMAIN),
                'desc_tip' => __('Seller API key from the “API” section of your OPOTOPO account.', Plugin::TEXT_DOMAIN),
            ],
            [
                'id' => Settings::ORGANIZATION,
                'type' => 'text',
                'title' => __('Organization ID', Plugin::TEXT_DOMAIN),
                'desc_tip' => __('Leave empty to publish as the key owner.', Plugin::TEXT_DOMAIN),
            ],
            [
                'id' => Settings::PUBLISH,
                'type' => 'checkbox',
                'title' => __('Publication', Plugin::TEXT_DOMAIN),
                'desc' => __('Publish synchronized products', Plugin::TEXT_DOMAIN),
                'default' => 'yes',
            ],
            [
                'id' => Settings::WEBHOOK_SECRET,
                'type' => 'password',
                'title' => __('Webhook signing secret', Plugin::TEXT_DOMAIN),
                'desc' => sprintf(
                    __('OPOTOPO orders arrive at %s: add it as a webhook with the “New order”, “Purchase revoked” and “Order status changed” events in the “Webhooks” block of the “API” section of your OPOTOPO account.', Plugin::TEXT_DOMAIN),
                    '<code>'.esc_html(Plugin::webhookUrl()).'</code>',
                ),
            ],
            ['id' => 'opotopo_seller_api_connection', 'type' => 'sectionend'],
            ['id' => self::STATUS_FIELD, 'type' => self::STATUS_FIELD],
        ];
    }

    public function status(): void
    {
        $report = Settings::report();
        $failures = $this->plugin->items()->failures(Plugin::CATALOG);
        $sync = wp_nonce_url(admin_url('admin-post.php?action='.Plugin::SYNC_REQUEST), Plugin::SYNC_REQUEST);
        echo '<h2>'.esc_html__('Synchronization', Plugin::TEXT_DOMAIN).'</h2>';
        if ($report === null) {
            echo '<p>'.esc_html__('The catalog has not been synchronized yet.', Plugin::TEXT_DOMAIN).'</p>';
        } elseif (isset($report['error'])) {
            echo '<div class="notice notice-error inline"><p>'.esc_html(sprintf(
                __('The run of %1$s failed: %2$s', Plugin::TEXT_DOMAIN),
                wp_date(get_option('date_format').' '.get_option('time_format'), $report['finishedAt']),
                $report['error'],
            )).'</p></div>';
        } else {
            echo '<p>'.esc_html(sprintf(
                __('Last run %1$s: created %2$d, updated %3$d, unchanged %4$d, removed %5$d, failed %6$d.', Plugin::TEXT_DOMAIN),
                wp_date(get_option('date_format').' '.get_option('time_format'), $report['finishedAt']),
                $report['created'],
                $report['updated'],
                $report['skipped'],
                $report['archived'],
                $report['failed'],
            )).'</p>';
        }
        if (Settings::configured()) {
            echo '<p><a class="button" href="'.esc_url($sync).'">'.esc_html__('Synchronize now', Plugin::TEXT_DOMAIN).'</a></p>';
        }
        if ($failures === []) {
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>'.esc_html__('Product', Plugin::TEXT_DOMAIN).'</th><th>'
            .esc_html__('SKU', Plugin::TEXT_DOMAIN).'</th><th>'.esc_html__('Error', Plugin::TEXT_DOMAIN).'</th><th>'
            .esc_html__('Details', Plugin::TEXT_DOMAIN).'</th></tr></thead><tbody>';
        foreach ($failures as $failure) {
            $link = get_edit_post_link((int) $failure['model_key']);
            echo '<tr><td>'.($link ? '<a href="'.esc_url($link).'">'.esc_html(get_the_title((int) $failure['model_key'])).'</a>' : esc_html($failure['model_key']))
                .'</td><td>'.esc_html($failure['sku']).'</td><td><code>'.esc_html($failure['error']).'</code></td><td>'
                .esc_html(self::details($failure['error_details'])).'</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function details(?string $details): string
    {
        $decoded = $details === null ? [] : json_decode($details, true);

        return is_array($decoded) ? Problem::details($decoded) : '';
    }
}
