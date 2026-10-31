<?php

namespace Opotopo\SellerApi\WooCommerce\Sync;

final class Upsert
{
    /**
     * @param  array<string, string>  $key
     * @param  array<string, string|int|null>  $values
     */
    public static function row(string $table, array $key, array $values): void
    {
        global $wpdb;
        $columns = array_merge($key, $values);
        $placeholders = array_map(fn ($value) => $value === null ? 'NULL' : (is_int($value) ? '%d' : '%s'), $columns);
        $updates = array_map(fn (string $column) => "{$column} = VALUES({$column})", array_keys($values));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (".implode(', ', array_keys($columns)).') VALUES ('.implode(', ', $placeholders).')'
            .' ON DUPLICATE KEY UPDATE '.implode(', ', $updates),
            ...array_values(array_filter($columns, fn ($value) => $value !== null)),
        ));
    }
}
