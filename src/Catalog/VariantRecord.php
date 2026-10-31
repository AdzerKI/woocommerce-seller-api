<?php

namespace Opotopo\SellerApi\WooCommerce\Catalog;

use OpotopoSellerApiVendor\Opotopo\SellerApi\Catalog\ItemRecord;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Catalog\Text;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Files\FileSource;
use WC_Product;
use WC_Product_Variation;

final class VariantRecord implements ItemRecord
{
    public const NAME = 'name';

    public const PRICE = 'price';

    public const IMAGE = 'image';

    public const FILES = 'files';

    public function __construct(
        private readonly WC_Product $product,
        private readonly string $name,
    ) {}

    public static function name(WC_Product_Variation $variation): string
    {
        $name = Text::plain(wc_get_formatted_variation($variation, true, false, false));

        return $name === '' ? $variation->get_name() : $name;
    }

    public function key(): string
    {
        return (string) $this->product->get_id();
    }

    public function value(string $path): mixed
    {
        return match ($path) {
            self::NAME => $this->name,
            self::PRICE => $this->product->get_price(),
            self::IMAGE => Media::url((int) $this->product->get_image_id('edit')),
            self::FILES => $this->product->is_downloadable()
                ? array_values(array_map(fn ($download) => Media::download($download->get_file()), $this->product->get_downloads()))
                : [],
            default => null,
        };
    }

    public function file(mixed $entry): FileSource
    {
        return Media::file($entry);
    }
}
