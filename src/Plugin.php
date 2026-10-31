<?php

namespace Opotopo\SellerApi\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Opotopo\SellerApi\WooCommerce\Admin\OrderPanel;
use Opotopo\SellerApi\WooCommerce\Admin\SettingsPage;
use Opotopo\SellerApi\WooCommerce\Catalog\ProductCatalog;
use Opotopo\SellerApi\WooCommerce\Files\TableFileStore;
use Opotopo\SellerApi\WooCommerce\Orders\PlatformOrders;
use Opotopo\SellerApi\WooCommerce\Sync\TableItemStore;
use Opotopo\SellerApi\WooCommerce\Webhooks\TableDeliveryStore;
use OpotopoSellerApiVendor\GuzzleHttp\Client;
use OpotopoSellerApiVendor\GuzzleHttp\Psr7\HttpFactory;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Files\FileUploader;
use OpotopoSellerApiVendor\Opotopo\SellerApi\SellerApiClient;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Sync\Synchronizer;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Webhooks\WebhookReceiver;
use OpotopoSellerApiVendor\Psr\Http\Client\ClientInterface;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

final class Plugin
{
    public const TEXT_DOMAIN = 'opotopo-seller-api';

    public const GROUP = 'opotopo-seller-api';

    public const SYNC_HOOK = 'opotopo_seller_api_sync';

    public const SCHEDULE_HOOK = 'opotopo_seller_api_scheduled_sync';

    public const SYNC_REQUEST = 'opotopo_seller_api_sync';

    public const CATALOG = 'products';

    private const REST_NAMESPACE = 'opotopo-seller-api/v1';

    private const WEBHOOK_ROUTE = '/webhooks';

    private const FAILED = 500;

    private const BATCH = 100;

    private const TIMEOUT = 60;

    public const LOCK = 'sync';

    private const PRODUCT_HOOKS = [
        'woocommerce_new_product',
        'woocommerce_update_product',
        'woocommerce_delete_product',
        'woocommerce_trash_product',
        'woocommerce_new_product_variation',
        'woocommerce_update_product_variation',
        'woocommerce_delete_product_variation',
        'woocommerce_trash_product_variation',
        'woocommerce_product_set_stock',
        'woocommerce_variation_set_stock',
    ];

    public function __construct(
        private readonly string $file,
        private readonly ?ClientInterface $http = null,
    ) {}

    public function sync(): void
    {
        if (! Settings::configured()) {
            return;
        }
        if (! Lock::acquire(self::LOCK)) {
            self::queue();

            return;
        }
        try {
            $report = $this->synchronizer()->run([self::CATALOG => new ProductCatalog]);
            update_option(Settings::REPORT, [
                'finishedAt' => time(),
                'created' => $report->created,
                'updated' => $report->updated,
                'skipped' => $report->skipped,
                'archived' => $report->archived,
                'failed' => count($report->failures),
            ], false);
        } catch (Throwable $failure) {
            update_option(Settings::REPORT, ['finishedAt' => time(), 'error' => $failure->getMessage()], false);

            throw $failure;
        } finally {
            Lock::release(self::LOCK);
        }
    }

    public function webhook(WP_REST_Request $request): WP_REST_Response
    {
        $headers = [];
        foreach (WebhookReceiver::HEADERS as $header) {
            $headers[$header] = $request->get_header($header);
        }
        try {
            $result = (new WebhookReceiver(Settings::webhookSecret(), new TableDeliveryStore))
                ->receive($request->get_body(), $headers, $this->orders());
        } catch (Throwable $failure) {
            wc_get_logger()->error($failure->getMessage(), ['source' => self::GROUP]);

            return new WP_REST_Response(null, self::FAILED);
        }

        return new WP_REST_Response(null, $result->status());
    }

    public static function queue(): void
    {
        as_enqueue_async_action(self::SYNC_HOOK, [], self::GROUP, true);
    }

    public function items(): TableItemStore
    {
        return new TableItemStore;
    }

    public function orders(): PlatformOrders
    {
        return new PlatformOrders($this->client(), $this->items());
    }

    public function url(string $path): string
    {
        return plugins_url($path, $this->file);
    }

    public function version(): string
    {
        return get_file_data($this->file, ['version' => 'Version'])['version'];
    }

    public function register(): void
    {
        register_activation_hook($this->file, [Schema::class, 'upgrade']);
        add_action('before_woocommerce_init', fn () => FeaturesUtil::declare_compatibility('custom_order_tables', $this->file, true));
        add_action('init', fn () => load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename($this->file)).'/languages'));
        add_action('plugins_loaded', [Schema::class, 'upgrade']);
        add_action('action_scheduler_init', [self::class, 'schedule']);
        add_action(self::SYNC_HOOK, [$this, 'sync']);
        add_action(self::SCHEDULE_HOOK, [$this, 'sync']);
        foreach (self::PRODUCT_HOOKS as $hook) {
            add_action($hook, [self::class, 'queue']);
        }
        add_filter('woocommerce_get_settings_pages', function (array $pages) {
            $pages[] = new SettingsPage($this);

            return $pages;
        });
        add_action('admin_post_'.self::SYNC_REQUEST, [SettingsPage::class, 'requestSync']);
        (new OrderPanel($this))->register();
        add_action('rest_api_init', function () {
            if (Settings::webhookSecret() !== '') {
                register_rest_route(self::REST_NAMESPACE, self::WEBHOOK_ROUTE, [
                    'methods' => 'POST',
                    'callback' => [$this, 'webhook'],
                    'permission_callback' => '__return_true',
                ]);
            }
        });
    }

    public static function webhookUrl(): string
    {
        return rest_url(self::REST_NAMESPACE.self::WEBHOOK_ROUTE);
    }

    public static function schedule(): void
    {
        if (! as_has_scheduled_action(self::SCHEDULE_HOOK, [], self::GROUP)) {
            as_schedule_recurring_action(time(), HOUR_IN_SECONDS, self::SCHEDULE_HOOK, [], self::GROUP, true);
        }
    }

    private function synchronizer(): Synchronizer
    {
        $client = $this->client();

        return new Synchronizer($client, new FileUploader($client, new TableFileStore, new HttpFactory), $this->items(), self::BATCH);
    }

    private function client(): SellerApiClient
    {
        $factory = new HttpFactory;

        return new SellerApiClient($this->http ?? new Client(['timeout' => self::TIMEOUT]), $factory, $factory, Settings::config());
    }
}
