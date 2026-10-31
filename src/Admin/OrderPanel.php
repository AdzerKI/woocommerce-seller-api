<?php

namespace Opotopo\SellerApi\WooCommerce\Admin;

use Opotopo\SellerApi\WooCommerce\Orders\PlatformOrders;
use Opotopo\SellerApi\WooCommerce\Plugin;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Orders\DispatchForm;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Orders\InvalidPlace;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Problem;
use OpotopoSellerApiVendor\Psr\Http\Message\StreamInterface;
use Throwable;
use WC_Admin_Meta_Boxes;
use WC_Order;
use WP_Post;

final class OrderPanel
{
    public const ACTION = 'opotopo_action';

    public const DISPATCH = 'dispatch';

    public const CANCEL = 'cancel';

    public const CARRIER_NAME = 'opotopo_carrier_name';

    public const TRACKING_NUMBER = 'opotopo_tracking_number';

    public const TRACKING_URL = 'opotopo_tracking_url';

    public const DROP_OFF_POINT = 'opotopo_drop_off_point';

    public const PLACES = 'opotopo_places';

    public const CANCEL_REASON = 'opotopo_cancel_reason';

    public const DOCUMENT_REQUEST = 'opotopo_seller_api_document';

    private const BOX = 'opotopo-fulfillment';

    private const SCRIPT = 'opotopo-seller-api-order';

    private const SCRIPT_PATH = 'assets/order.js';

    private const CAPABILITY = 'edit_shop_orders';

    private const SAVE_PRIORITY = 60;

    private const WEIGHT_UNIT = 'g';

    private const DIMENSION_UNIT = 'cm';

    private const INDEX = '__index__';

    private const NUMBER = '__number__';

    private const CHUNK = 8192;

    private array $fulfillments = [];

    private array $failures = [];

    public function __construct(private readonly Plugin $plugin) {}

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'box'], 10, 2);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save'], self::SAVE_PRIORITY);
        add_action('admin_post_'.self::DOCUMENT_REQUEST, [$this, 'download']);
    }

    public function box(string $screen, mixed $object): void
    {
        $order = $object instanceof WP_Post ? wc_get_order($object->ID) : $object;
        if ($order instanceof WC_Order && PlatformOrders::fulfillmentId($order) !== '') {
            add_meta_box(self::BOX, __('OPOTOPO', Plugin::TEXT_DOMAIN), fn () => $this->render($order), $screen, 'normal', 'high');
        }
    }

    public function save(int $orderId): void
    {
        $action = sanitize_key(wp_unslash($_POST[self::ACTION] ?? ''));
        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order || PlatformOrders::fulfillmentId($order) === '' || ! current_user_can(self::CAPABILITY)) {
            return;
        }
        try {
            match ($action) {
                self::DISPATCH => $this->dispatch($order),
                self::CANCEL => $this->plugin->orders()->cancel($order, self::field(self::CANCEL_REASON)),
                default => null,
            };
        } catch (Throwable $failure) {
            WC_Admin_Meta_Boxes::add_error(sprintf(__('OPOTOPO refused the action: %s', Plugin::TEXT_DOMAIN), Problem::message($failure)));
        }
    }

    public function document(WC_Order $order, string $type): ?StreamInterface
    {
        $shipmentId = in_array($type, DispatchForm::DOCUMENTS, true) ? $this->read($order)['shipmentId'] ?? null : null;

        return $shipmentId === null ? null : $this->plugin->orders()->document($shipmentId, $type);
    }

    public function download(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to download shipment documents.', Plugin::TEXT_DOMAIN), 403);
        }
        check_admin_referer(self::DOCUMENT_REQUEST);
        $order = wc_get_order(absint($_GET['order'] ?? 0));
        $type = sanitize_text_field(wp_unslash($_GET['type'] ?? ''));
        try {
            $document = $order instanceof WC_Order ? $this->document($order, $type) : null;
        } catch (Throwable $failure) {
            wp_die(esc_html(Problem::message($failure)), 502);
        }
        if ($document === null) {
            wp_die(esc_html__('The order has no such shipment document.', Plugin::TEXT_DOMAIN), 404);
        }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.strtolower($type).'-'.$order->get_order_number().'.pdf"');
        while (! $document->eof()) {
            echo $document->read(self::CHUNK);
        }
        exit;
    }

    private function dispatch(WC_Order $order): void
    {
        $fulfillment = $this->read($order);
        if ($fulfillment === null) {
            throw $this->failures[$order->get_id()];
        }
        try {
            $request = DispatchForm::request(
                $fulfillment,
                [
                    'carrierName' => self::field(self::CARRIER_NAME),
                    'trackingNumber' => self::field(self::TRACKING_NUMBER),
                    'trackingUrl' => self::field(self::TRACKING_URL),
                    DispatchForm::DROP_OFF_POINT => self::field(self::DROP_OFF_POINT),
                ],
                array_values((array) wp_unslash($_POST[self::PLACES] ?? [])),
                fn (float $weight) => wc_get_weight($weight, self::WEIGHT_UNIT),
                fn (float $dimension) => wc_get_dimension($dimension, self::DIMENSION_UNIT),
            );
        } catch (InvalidPlace $place) {
            WC_Admin_Meta_Boxes::add_error(sprintf(__('Place %d: enter the weight and all three dimensions.', Plugin::TEXT_DOMAIN), $place->number));

            return;
        }
        $this->plugin->orders()->dispatch($order, $request);
    }

    private function read(WC_Order $order): ?array
    {
        $id = $order->get_id();
        if (! array_key_exists($id, $this->fulfillments)) {
            try {
                $this->fulfillments[$id] = $this->plugin->orders()->fulfillment($order);
            } catch (Throwable $failure) {
                $this->fulfillments[$id] = null;
                $this->failures[$id] = $failure;
            }
        }

        return $this->fulfillments[$id];
    }

    private function render(WC_Order $order): void
    {
        $fulfillment = $this->read($order);
        if ($fulfillment === null) {
            echo '<div class="notice notice-error inline"><p>'.esc_html(sprintf(
                __('The OPOTOPO order could not be read: %s', Plugin::TEXT_DOMAIN),
                Problem::message($this->failures[$order->get_id()]),
            )).'</p></div>';

            return;
        }
        $available = $fulfillment['availableActions'];
        $method = $fulfillment['method'];
        echo '<p><strong>'.esc_html(PlatformOrders::status($fulfillment['status'])).'</strong> · '.esc_html(PlatformOrders::method($method)).'</p>';
        if (isset($fulfillment['trackingNumber'])) {
            echo '<p>'.esc_html(implode(' ', array_filter([$fulfillment['carrierName'] ?? null, $fulfillment['trackingNumber']]))).'</p>';
        }
        if (in_array('DISPATCH', $available, true) && isset($fulfillment['dispatchDueAt'])) {
            echo '<p>'.esc_html(sprintf(
                __('Hand over by %s', Plugin::TEXT_DOMAIN),
                wp_date(get_option('date_format').' '.get_option('time_format'), strtotime($fulfillment['dispatchDueAt'])),
            )).'</p>';
        }
        if (isset($fulfillment['shipmentId'])) {
            $this->documents($order);
        }
        if (in_array('DISPATCH', $available, true)) {
            match (true) {
                $method === DispatchForm::OWN_DELIVERY => self::tracking($fulfillment),
                in_array($method, DispatchForm::CARRIER, true) => $this->carrier($order, $fulfillment),
                default => null,
            };
            echo '<p><button type="submit" class="button button-primary" name="'.esc_attr(self::ACTION).'" value="'.esc_attr(self::DISPATCH).'">'
                .esc_html(self::dispatchLabel($method)).'</button></p>';
        }
        if (in_array('CANCEL', $available, true)) {
            echo '<p><label for="'.esc_attr(self::CANCEL_REASON).'">'.esc_html__('Cancellation reason', Plugin::TEXT_DOMAIN).'</label><br>'
                .'<textarea class="large-text" rows="2" id="'.esc_attr(self::CANCEL_REASON).'" name="'.esc_attr(self::CANCEL_REASON).'"></textarea></p>'
                .'<p><button type="submit" class="button" name="'.esc_attr(self::ACTION).'" value="'.esc_attr(self::CANCEL).'">'
                .esc_html__('Cancel the order on OPOTOPO', Plugin::TEXT_DOMAIN).'</button></p>';
        }
    }

    private function documents(WC_Order $order): void
    {
        $labels = ['LABEL' => __('Label', Plugin::TEXT_DOMAIN), 'WAYBILL' => __('Waybill', Plugin::TEXT_DOMAIN)];
        $links = array_map(fn (string $type) => '<a class="button" href="'.esc_url(wp_nonce_url(add_query_arg(
            ['action' => self::DOCUMENT_REQUEST, 'order' => $order->get_id(), 'type' => $type],
            admin_url('admin-post.php'),
        ), self::DOCUMENT_REQUEST)).'">'.esc_html($labels[$type]).'</a>', DispatchForm::DOCUMENTS);
        echo '<p>'.implode(' ', $links).'</p>';
    }

    private static function tracking(array $fulfillment): void
    {
        foreach ([
            self::CARRIER_NAME => [__('Delivery service', Plugin::TEXT_DOMAIN), 'text', false],
            self::TRACKING_NUMBER => [__('Tracking number', Plugin::TEXT_DOMAIN), 'text', $fulfillment['trackingRequired']],
            self::TRACKING_URL => [__('Tracking address', Plugin::TEXT_DOMAIN), 'url', false],
        ] as $name => [$label, $type, $required]) {
            echo '<p><label for="'.esc_attr($name).'">'.esc_html($label).'</label><br>'
                .'<input class="regular-text" type="'.esc_attr($type).'" id="'.esc_attr($name).'" name="'.esc_attr($name).'"'.($required ? ' required' : '').'></p>';
        }
    }

    private function carrier(WC_Order $order, array $fulfillment): void
    {
        try {
            $points = $this->plugin->orders()->dropOffPoints($order);
        } catch (Throwable $failure) {
            $points = [];
            echo '<div class="notice notice-warning inline"><p>'.esc_html(sprintf(
                __('Drop-off points could not be read: %s', Plugin::TEXT_DOMAIN),
                Problem::message($failure),
            )).'</p></div>';
        }
        echo '<p><label for="'.esc_attr(self::DROP_OFF_POINT).'">'.esc_html__('Hand over to the carrier', Plugin::TEXT_DOMAIN).'</label><br>'
            .'<select id="'.esc_attr(self::DROP_OFF_POINT).'" name="'.esc_attr(self::DROP_OFF_POINT).'">'
            .'<option value="">'.esc_html__('Carrier courier pickup', Plugin::TEXT_DOMAIN).'</option>';
        foreach ($points as $point) {
            echo '<option value="'.esc_attr($point['externalId']).'">'.esc_html(implode(' — ', array_filter([$point['name'] ?? null, $point['addressLine'] ?? null]))).'</option>';
        }
        echo '</select></p>';
        wp_enqueue_script(self::SCRIPT, $this->plugin->url(self::SCRIPT_PATH), [], $this->plugin->version(), true);
        $weightUnit = get_option('woocommerce_weight_unit');
        $dimensionUnit = get_option('woocommerce_dimension_unit');
        $place = '<fieldset data-opotopo-place><legend>'.esc_html(sprintf(__('Place %s', Plugin::TEXT_DOMAIN), self::NUMBER)).'</legend><p>';
        foreach (DispatchForm::SIZES as $size) {
            $label = match ($size) {
                'weight' => sprintf(__('Weight, %s', Plugin::TEXT_DOMAIN), $weightUnit),
                'length' => sprintf(__('Length, %s', Plugin::TEXT_DOMAIN), $dimensionUnit),
                'width' => sprintf(__('Width, %s', Plugin::TEXT_DOMAIN), $dimensionUnit),
                'height' => sprintf(__('Height, %s', Plugin::TEXT_DOMAIN), $dimensionUnit),
            };
            $place .= '<label>'.esc_html($label).' <input class="small-text" type="number" min="0" step="any" name="'
                .esc_attr(self::PLACES.'['.self::INDEX.']['.$size.']').'"></label> ';
        }
        $place .= '</p>';
        foreach ($fulfillment['items'] as $line) {
            $place .= '<p><label>'.esc_html(implode(' — ', array_filter([$line['title'] ?? null, $line['variantName'] ?? null])))
                .' <input class="small-text" type="number" min="0" max="'.esc_attr((string) $line['quantity']).'" name="'
                .esc_attr(self::PLACES.'['.self::INDEX.']['.DispatchForm::LINES.']['.$line['id'].']').'"></label></p>';
        }
        $place .= '</fieldset>';
        echo '<div data-opotopo-places><p>'.esc_html__('Without places, every unit ships as its own place with the product package.', Plugin::TEXT_DOMAIN).'</p>'
            .str_replace([self::INDEX, self::NUMBER], ['0', '1'], $place)
            .'<template>'.$place.'</template></div>'
            .'<p><button type="button" class="button" data-opotopo-add-place>'.esc_html__('Add place', Plugin::TEXT_DOMAIN).'</button></p>';
    }

    private static function dispatchLabel(string $method): string
    {
        return match (true) {
            $method === DispatchForm::OWN_DELIVERY => __('Hand over to delivery', Plugin::TEXT_DOMAIN),
            $method === DispatchForm::PICKUP => __('Ready for pickup', Plugin::TEXT_DOMAIN),
            default => __('Ship with the carrier', Plugin::TEXT_DOMAIN),
        };
    }

    private static function field(string $name): ?string
    {
        $value = trim(sanitize_text_field(wp_unslash($_POST[$name] ?? '')));

        return $value === '' ? null : $value;
    }
}
