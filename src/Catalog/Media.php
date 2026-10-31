<?php

namespace Opotopo\SellerApi\WooCommerce\Catalog;

use OpotopoSellerApiVendor\Opotopo\SellerApi\Files\LocalFile;
use SplFileInfo;
use WC_Download_Handler;

final class Media
{
    private const DEFAULT_TYPE = 'application/octet-stream';

    public static function url(int $attachmentId): ?string
    {
        return $attachmentId > 0 ? (wp_get_attachment_url($attachmentId) ?: null) : null;
    }

    public static function download(string $file): string|SplFileInfo
    {
        $parsed = WC_Download_Handler::parse_file_path($file);

        return ! $parsed['remote_file'] && is_file($parsed['file_path']) ? new SplFileInfo($parsed['file_path']) : $file;
    }

    public static function file(mixed $entry): LocalFile
    {
        $path = $entry instanceof SplFileInfo ? ($entry->getRealPath() ?: $entry->getPathname()) : (string) $entry;

        return new LocalFile($path, wp_basename($path), wp_check_filetype($path)['type'] ?: self::DEFAULT_TYPE);
    }
}
