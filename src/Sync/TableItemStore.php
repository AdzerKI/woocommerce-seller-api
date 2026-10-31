<?php

namespace Opotopo\SellerApi\WooCommerce\Sync;

use DateTimeImmutable;
use DateTimeZone;
use Opotopo\SellerApi\WooCommerce\Schema;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Sync\ItemState;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Sync\ItemStore;

final class TableItemStore implements ItemStore
{
    private const DATETIME = 'Y-m-d H:i:s';

    private const PAGE = 1000;

    public function load(string $catalog): array
    {
        global $wpdb;
        $table = Schema::table(Schema::ITEMS);
        $states = [];
        $after = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE catalog = %s AND id > %d ORDER BY id LIMIT %d",
                $catalog,
                $after,
                self::PAGE,
            ), ARRAY_A);
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $states[$row['model_key']] = new ItemState(
                    $row['model_key'],
                    $row['sku'],
                    $row['advertisement_id'],
                    $row['fingerprint'],
                    $row['error'],
                    $row['error_details'] === null ? null : json_decode($row['error_details'], true, flags: JSON_THROW_ON_ERROR),
                    $row['synced_at'] === null ? null : new DateTimeImmutable($row['synced_at'], new DateTimeZone('UTC')),
                );
            }
        } while (count($rows) === self::PAGE);

        return $states;
    }

    public function save(string $catalog, ItemState $state): void
    {
        Upsert::row(Schema::table(Schema::ITEMS), ['catalog' => $catalog, 'model_key' => $state->key], [
            'sku' => $state->sku,
            'advertisement_id' => $state->advertisementId,
            'fingerprint' => $state->fingerprint,
            'error' => $state->error,
            'error_details' => $state->errorDetails === null ? null : json_encode($state->errorDetails, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'synced_at' => $state->syncedAt?->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME),
        ]);
    }

    public function delete(string $catalog, array $keys): void
    {
        global $wpdb;
        if ($keys === []) {
            return;
        }
        $table = Schema::table(Schema::ITEMS);
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE catalog = %s AND model_key IN ({$placeholders})", $catalog, ...$keys));
    }

    public function keyOf(string $catalog, string $advertisementId): ?string
    {
        global $wpdb;
        $table = Schema::table(Schema::ITEMS);

        return $wpdb->get_var($wpdb->prepare(
            "SELECT model_key FROM {$table} WHERE advertisement_id = %s AND catalog = %s LIMIT 1",
            $advertisementId,
            $catalog,
        ));
    }

    /**
     * @return list<array{model_key: string, sku: string, error: string, error_details: ?string}>
     */
    public function failures(string $catalog): array
    {
        global $wpdb;
        $table = Schema::table(Schema::ITEMS);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT model_key, sku, error, error_details FROM {$table} WHERE catalog = %s AND error IS NOT NULL ORDER BY id",
            $catalog,
        ), ARRAY_A);
    }
}
