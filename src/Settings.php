<?php

namespace Opotopo\SellerApi\WooCommerce;

use OpotopoSellerApiVendor\Opotopo\SellerApi\ClientConfig;

final class Settings
{
    public const URL = 'opotopo_seller_api_url';

    public const KEY = 'opotopo_seller_api_key';

    public const ORGANIZATION = 'opotopo_seller_api_organization';

    public const PUBLISH = 'opotopo_seller_api_publish';

    public const WEBHOOK_SECRET = 'opotopo_seller_api_webhook_secret';

    public const REPORT = 'opotopo_seller_api_report';

    public const SCHEMA = 'opotopo_seller_api_schema';

    public const OPTIONS = [self::URL, self::KEY, self::ORGANIZATION, self::PUBLISH, self::WEBHOOK_SECRET, self::REPORT, self::SCHEMA];

    public const DEFAULT_URL = 'https://api.opotopo.com';

    private const YES = 'yes';

    public static function configured(): bool
    {
        return self::key() !== '';
    }

    public static function config(): ClientConfig
    {
        $organization = trim((string) get_option(self::ORGANIZATION, ''));

        return new ClientConfig(
            rtrim((string) get_option(self::URL, self::DEFAULT_URL), '/') ?: self::DEFAULT_URL,
            self::key(),
            $organization === '' ? null : $organization,
            get_option(self::PUBLISH, self::YES) === self::YES,
        );
    }

    public static function webhookSecret(): string
    {
        return trim((string) get_option(self::WEBHOOK_SECRET, ''));
    }

    public static function report(): ?array
    {
        $report = get_option(self::REPORT);

        return is_array($report) ? $report : null;
    }

    private static function key(): string
    {
        return trim((string) get_option(self::KEY, ''));
    }
}
