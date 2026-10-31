<?php

namespace Opotopo\SellerApi\WooCommerce\Webhooks;

use Opotopo\SellerApi\WooCommerce\Lock;
use Opotopo\SellerApi\WooCommerce\Schema;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Webhooks\DeliveryStore;

final class TableDeliveryStore implements DeliveryStore
{
    private const LOCK = 'webhook:';

    private const TTL = 2 * DAY_IN_SECONDS;

    public function acquire(string $webhookId): bool
    {
        return Lock::acquire(self::LOCK.$webhookId);
    }

    public function release(string $webhookId): void
    {
        Lock::release(self::LOCK.$webhookId);
    }

    public function processed(string $webhookId): bool
    {
        global $wpdb;
        $table = Schema::table(Schema::DELIVERIES);

        return $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE webhook_id = %s", $webhookId)) !== null;
    }

    public function markProcessed(string $webhookId): void
    {
        global $wpdb;
        $table = Schema::table(Schema::DELIVERIES);
        $now = time();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE processed_at < %d", $now - self::TTL));
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (webhook_id, processed_at) VALUES (%s, %d)", $webhookId, $now));
    }
}
