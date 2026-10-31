<?php

namespace Opotopo\SellerApi\WooCommerce\Catalog;

use OpotopoSellerApiVendor\Opotopo\SellerApi\Catalog\CatalogSource;
use WC_Product;

final class ProductCatalog implements CatalogSource
{
    public const TYPES = ['simple', 'variable'];

    private const STATUS = 'publish';

    private const HIDDEN = 'hidden';

    private const PAGE = 100;

    public function mapping(): array
    {
        return [
            'shop_category' => ProductRecord::SHOP_CATEGORY,
            'fields' => [
                'sku' => ProductRecord::SKU,
                'title' => ProductRecord::TITLE,
                'description' => ProductRecord::DESCRIPTION,
                'price' => ProductRecord::PRICE,
                'quantity' => ProductRecord::QUANTITY,
                'barcode' => ProductRecord::BARCODE,
                'brand' => ProductRecord::BRAND,
            ],
            'attributes' => ProductRecord::ATTRIBUTES,
            'images' => ['cover' => ProductRecord::COVER, 'gallery' => ProductRecord::GALLERY],
            'variants' => [
                'each' => ProductRecord::VARIANTS,
                'name' => VariantRecord::NAME,
                'price' => VariantRecord::PRICE,
                'image' => VariantRecord::IMAGE,
                'files' => VariantRecord::FILES,
            ],
            'parcel' => [
                'weight' => ProductRecord::WEIGHT,
                'length' => ProductRecord::LENGTH,
                'width' => ProductRecord::WIDTH,
                'height' => ProductRecord::HEIGHT,
            ],
        ];
    }

    public function records(): iterable
    {
        $ids = wc_get_products([
            'status' => self::STATUS,
            'type' => self::TYPES,
            'limit' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'return' => 'ids',
        ]);
        foreach (array_chunk($ids, self::PAGE) as $chunk) {
            _prime_post_caches($chunk);
            foreach ($chunk as $id) {
                $product = wc_get_product($id);
                if ($product instanceof WC_Product && $product->get_catalog_visibility() !== self::HIDDEN) {
                    yield new ProductRecord($product);
                }
            }
            wp_cache_flush_runtime();
        }
    }
}
