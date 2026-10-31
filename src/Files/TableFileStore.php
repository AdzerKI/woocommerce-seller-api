<?php

namespace Opotopo\SellerApi\WooCommerce\Files;

use Opotopo\SellerApi\WooCommerce\Schema;
use Opotopo\SellerApi\WooCommerce\Sync\Upsert;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Files\FileStore;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Files\FileTarget;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Files\StoredFile;

final class TableFileStore implements FileStore
{
    public function find(FileTarget $target): ?StoredFile
    {
        global $wpdb;
        $table = Schema::table(Schema::FILES);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT source, size, modified_at, checksum, file_id FROM {$table} WHERE source_hash = %s AND gallery = %s AND category_id = %s",
            $target->sourceHash,
            $target->gallery,
            $target->categoryId,
        ), ARRAY_A);

        return $row === null
            ? null
            : new StoredFile($row['source'], (int) $row['size'], (int) $row['modified_at'], $row['checksum'], $row['file_id']);
    }

    public function save(FileTarget $target, StoredFile $file): void
    {
        Upsert::row(Schema::table(Schema::FILES), [
            'source_hash' => $target->sourceHash,
            'gallery' => $target->gallery,
            'category_id' => $target->categoryId,
        ], [
            'source' => $file->source,
            'size' => $file->size,
            'modified_at' => $file->modifiedAt,
            'checksum' => $file->checksum,
            'file_id' => $file->fileId,
        ]);
    }

    public function forget(array $fileIds): void
    {
        global $wpdb;
        if ($fileIds === []) {
            return;
        }
        $table = Schema::table(Schema::FILES);
        $placeholders = implode(',', array_fill(0, count($fileIds), '%s'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE file_id IN ({$placeholders})", ...$fileIds));
    }
}
