<?php

namespace Opotopo\SellerApi\WooCommerce\Orders;

use Opotopo\SellerApi\WooCommerce\Catalog\VariantRecord;
use Opotopo\SellerApi\WooCommerce\Lock;
use Opotopo\SellerApi\WooCommerce\Plugin;
use Opotopo\SellerApi\WooCommerce\Sync\TableItemStore;
use OpotopoSellerApiVendor\Opotopo\SellerApi\SellerApiClient;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Webhooks\FulfillmentStatusChanged;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Webhooks\PurchaseCreated;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Webhooks\PurchaseRevoked;
use OpotopoSellerApiVendor\Psr\Http\Message\StreamInterface;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

final class PlatformOrders
{
    public const FULFILLMENT_META = '_opotopo_fulfillment_id';

    public const STATUS_META = '_opotopo_fulfillment_status';

    public const PURCHASE_META = '_opotopo_purchase_id';

    public const SOURCE = 'opotopo';

    private const LOCK = 'fulfillment:';

    private const LOCK_WAIT = 10;

    private const OWN_DELIVERY = 'COURIER';

    private const STATUSES = ['RECEIVED' => 'completed', 'CANCELLED' => 'cancelled'];

    private const ACTIVE_STATUS = 'processing';

    public function __construct(
        private readonly SellerApiClient $client,
        private readonly TableItemStore $items,
    ) {}

    public function __invoke(object $event): void
    {
        match (true) {
            $event instanceof PurchaseCreated => $this->purchased($event),
            $event instanceof PurchaseRevoked => $this->revoked($event),
            $event instanceof FulfillmentStatusChanged => $this->changed($event),
        };
    }

    public static function order(string $fulfillmentId): ?WC_Order
    {
        $orders = wc_get_orders([
            'limit' => 1,
            'type' => 'shop_order',
            'status' => array_keys(wc_get_order_statuses()),
            'meta_query' => [['key' => self::FULFILLMENT_META, 'value' => $fulfillmentId]],
        ]);

        return $orders[0] ?? null;
    }

    private function purchased(PurchaseCreated $event): void
    {
        if ($event->fulfillmentId === null) {
            return;
        }
        if (! Lock::acquire(self::LOCK.$event->fulfillmentId, self::LOCK_WAIT)) {
            throw new RuntimeException('Fulfillment '.$event->fulfillmentId.' is locked');
        }
        try {
            if (self::order($event->fulfillmentId) === null) {
                $this->create($this->client->fulfillment($event->fulfillmentId));
            }
        } finally {
            Lock::release(self::LOCK.$event->fulfillmentId);
        }
    }

    private function create(array $fulfillment): void
    {
        $order = new WC_Order;
        $order->set_created_via(self::SOURCE);
        $order->set_currency($fulfillment['currency']);
        $order->set_payment_method_title(__('OPOTOPO', Plugin::TEXT_DOMAIN));
        foreach ($fulfillment['items'] as $line) {
            $order->add_item($this->line($line));
        }
        $shipping = new WC_Order_Item_Shipping;
        $shipping->set_method_title($fulfillment['tariffCarrierName'] ?? self::method($fulfillment['method']));
        $shipping->set_total($fulfillment['method'] === self::OWN_DELIVERY ? (string) ($fulfillment['deliveryPrice'] ?? 0) : '0');
        $order->add_item($shipping);
        $order->set_shipping_first_name($fulfillment['recipientName'] ?? '');
        $order->set_shipping_phone($fulfillment['recipientPhone'] ?? '');
        $order->set_shipping_address_1($fulfillment['deliveryAddressLine'] ?? $fulfillment['carrierPointAddressLine'] ?? $fulfillment['pickupAddressLine'] ?? '');
        $order->set_shipping_city($fulfillment['carrierPointCity'] ?? '');
        $order->set_shipping_postcode($fulfillment['deliveryPostalCode'] ?? '');
        $order->set_customer_note($fulfillment['deliveryComment'] ?? '');
        $order->update_meta_data(self::FULFILLMENT_META, $fulfillment['id']);
        $order->update_meta_data(self::STATUS_META, $fulfillment['status']);
        $order->calculate_totals(false);
        $order->set_date_paid(time());
        $order->set_status(self::ACTIVE_STATUS);
        $order->save();
        $order->add_order_note(implode("\n", array_filter([
            sprintf(__('OPOTOPO order %s', Plugin::TEXT_DOMAIN), $fulfillment['orderId']),
            self::method($fulfillment['method']),
            $fulfillment['carrierPointName'] ?? $fulfillment['pickupLocationName'] ?? null,
        ])));
    }

    private function line(array $line): WC_Order_Item_Product
    {
        $item = new WC_Order_Item_Product;
        $product = $this->product($line['advertisementId'], $line['variantName'] ?? null);
        if ($product !== null) {
            $item->set_product($product);
        }
        $item->set_name(implode(' — ', array_filter([$line['title'] ?? $product?->get_name(), $line['variantName'] ?? null])));
        $item->set_quantity($line['quantity']);
        $item->set_subtotal((string) $line['amount']);
        $item->set_total((string) $line['amount']);
        $item->add_meta_data(self::PURCHASE_META, $line['id'], true);

        return $item;
    }

    private function product(string $advertisementId, ?string $variant): ?WC_Product
    {
        $key = $this->items->keyOf(Plugin::CATALOG, $advertisementId);
        $product = $key === null ? null : wc_get_product((int) $key);
        if (! $product instanceof WC_Product) {
            return null;
        }
        if (! $product instanceof WC_Product_Variable || $variant === null) {
            return $product;
        }
        foreach ($product->get_children() as $child) {
            $variation = wc_get_product($child);
            if ($variation instanceof WC_Product_Variation && VariantRecord::name($variation) === $variant) {
                return $variation;
            }
        }

        return $product;
    }

    private function revoked(PurchaseRevoked $event): void
    {
        global $wpdb;
        $itemId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            self::PURCHASE_META,
            $event->purchaseId,
        ));
        $order = $itemId === 0 ? null : wc_get_order(wc_get_order_id_by_order_item_id($itemId));
        $item = $order instanceof WC_Order ? $order->get_item($itemId) : null;
        if (! $item instanceof WC_Order_Item_Product || $order->get_qty_refunded_for_item($itemId) !== 0) {
            return;
        }
        wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => $item->get_total(),
            'reason' => __('Purchase revoked on OPOTOPO', Plugin::TEXT_DOMAIN),
            'line_items' => [$itemId => ['qty' => $item->get_quantity(), 'refund_total' => $item->get_total()]],
            'refund_payment' => false,
            'restock_items' => true,
        ]);
    }

    public function fulfillment(WC_Order $order): array
    {
        return $this->client->fulfillment(self::fulfillmentId($order));
    }

    public function dropOffPoints(WC_Order $order): array
    {
        return $this->client->dropOffPoints(self::fulfillmentId($order));
    }

    public function dispatch(WC_Order $order, array $request): void
    {
        $this->apply($order, $this->client->dispatch(self::fulfillmentId($order), $request)['status']);
    }

    public function cancel(WC_Order $order, ?string $reason): void
    {
        $this->apply($order, $this->client->cancel(self::fulfillmentId($order), $reason)['status']);
    }

    public function document(string $shipmentId, string $type): StreamInterface
    {
        return $this->client->shipmentDocument($shipmentId, $type);
    }

    public static function fulfillmentId(WC_Order $order): string
    {
        return (string) $order->get_meta(self::FULFILLMENT_META);
    }

    private function changed(FulfillmentStatusChanged $event): void
    {
        $order = self::order($event->fulfillmentId);
        if ($order !== null) {
            $this->apply($order, $event->status);
        }
    }

    private function apply(WC_Order $order, string $status): void
    {
        if ($order->get_meta(self::STATUS_META) === $status) {
            return;
        }
        $order->update_meta_data(self::STATUS_META, $status);
        $order->set_status(
            self::STATUSES[$status] ?? self::ACTIVE_STATUS,
            sprintf(__('OPOTOPO status: %s', Plugin::TEXT_DOMAIN), self::status($status)),
        );
        $order->save();
    }

    public static function method(string $method): string
    {
        return match ($method) {
            'COURIER' => __('Seller delivery', Plugin::TEXT_DOMAIN),
            'SELLER_PICKUP' => __('Pickup', Plugin::TEXT_DOMAIN),
            'CARRIER_DELIVERY' => __('Carrier delivery to the address', Plugin::TEXT_DOMAIN),
            'CARRIER_POINT' => __('Carrier pickup point', Plugin::TEXT_DOMAIN),
            default => __('Digital delivery', Plugin::TEXT_DOMAIN),
        };
    }

    public static function status(string $status): string
    {
        return match ($status) {
            'PENDING' => __('awaiting shipment', Plugin::TEXT_DOMAIN),
            'SHIPPED' => __('shipped', Plugin::TEXT_DOMAIN),
            'READY_FOR_PICKUP' => __('ready for pickup', Plugin::TEXT_DOMAIN),
            'DELIVERED' => __('delivered', Plugin::TEXT_DOMAIN),
            'RECEIVED' => __('received by the buyer', Plugin::TEXT_DOMAIN),
            'CANCELLED' => __('cancelled', Plugin::TEXT_DOMAIN),
            default => $status,
        };
    }
}
