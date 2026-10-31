<?php

namespace Opotopo\SellerApi\WooCommerce;

final class Schema
{
    public const ITEMS = 'opotopo_items';

    public const FILES = 'opotopo_files';

    public const DELIVERIES = 'opotopo_deliveries';

    public const TABLES = [self::ITEMS, self::FILES, self::DELIVERIES];

    private const VERSION = '1';

    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix.$name;
    }

    public static function upgrade(): void
    {
        if (get_option(Settings::SCHEMA) === self::VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $items = self::table(self::ITEMS);
        $files = self::table(self::FILES);
        $deliveries = self::table(self::DELIVERIES);
        dbDelta([
            "CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  catalog varchar(64) NOT NULL,
  model_key varchar(191) NOT NULL,
  sku varchar(64) NOT NULL,
  advertisement_id char(36) DEFAULT NULL,
  fingerprint char(64) DEFAULT NULL,
  error varchar(64) DEFAULT NULL,
  error_details longtext DEFAULT NULL,
  synced_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY catalog_model_key (catalog,model_key),
  KEY advertisement_id (advertisement_id)
) {$charset};",
            "CREATE TABLE {$files} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source text NOT NULL,
  source_hash char(64) NOT NULL,
  gallery varchar(32) NOT NULL,
  category_id char(36) NOT NULL,
  size bigint(20) unsigned NOT NULL,
  modified_at bigint(20) unsigned NOT NULL,
  checksum char(64) NOT NULL,
  file_id char(36) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY target (source_hash,gallery,category_id),
  UNIQUE KEY file_id (file_id)
) {$charset};",
            "CREATE TABLE {$deliveries} (
  webhook_id varchar(191) NOT NULL,
  processed_at bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (webhook_id),
  KEY processed_at (processed_at)
) {$charset};",
        ]);
        update_option(Settings::SCHEMA, self::VERSION);
    }

    public static function drop(): void
    {
        global $wpdb;
        foreach (self::TABLES as $name) {
            $wpdb->query('DROP TABLE IF EXISTS '.self::table($name));
        }
    }
}
