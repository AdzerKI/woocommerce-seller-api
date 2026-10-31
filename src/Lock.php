<?php

namespace Opotopo\SellerApi\WooCommerce;

final class Lock
{
    private const PREFIX = 'opotopo_seller_api_';

    public static function acquire(string $name, int $wait = 0): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::name($name), $wait)) === 1;
    }

    public static function release(string $name): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::name($name)));
    }

    public static function name(string $name): string
    {
        return self::PREFIX.md5($name);
    }
}
