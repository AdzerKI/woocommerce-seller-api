<?php

namespace Opotopo\SellerApi\WooCommerce\Catalog;

use OpotopoSellerApiVendor\Opotopo\SellerApi\Catalog\ItemRecord;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Catalog\Text;
use OpotopoSellerApiVendor\Opotopo\SellerApi\Files\FileSource;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

final class ProductRecord implements ItemRecord
{
    public const SKU = 'sku';

    public const TITLE = 'title';

    public const DESCRIPTION = 'description';

    public const PRICE = 'price';

    public const QUANTITY = 'quantity';

    public const BARCODE = 'barcode';

    public const BRAND = 'brand';

    public const SHOP_CATEGORY = 'shop_category';

    public const ATTRIBUTES = 'attributes';

    public const COVER = 'cover';

    public const GALLERY = 'gallery';

    public const VARIANTS = 'variants';

    public const WEIGHT = 'weight';

    public const LENGTH = 'length';

    public const WIDTH = 'width';

    public const HEIGHT = 'height';

    private const WEIGHT_UNIT = 'g';

    private const DIMENSION_UNIT = 'cm';

    private const SKU_PREFIX = 'wc-';

    private const CATEGORY_TAXONOMY = 'product_cat';

    private const BRAND_TAXONOMY = 'product_brand';

    private const VALUE_SEPARATOR = ', ';

    private ?array $variations = null;

    public function __construct(private readonly WC_Product $product) {}

    public function key(): string
    {
        return (string) $this->product->get_id();
    }

    public function value(string $path): mixed
    {
        return match ($path) {
            self::SKU => $this->product->get_sku() ?: self::SKU_PREFIX.$this->product->get_id(),
            self::TITLE => $this->product->get_name(),
            self::DESCRIPTION => Text::plain($this->product->get_description()),
            self::PRICE => $this->price(),
            self::QUANTITY => $this->quantity(),
            self::BARCODE => $this->product->get_global_unique_id(),
            self::BRAND => array_map(fn ($term) => Text::plain($term->name), $this->terms(self::BRAND_TAXONOMY))[0] ?? null,
            self::SHOP_CATEGORY => $this->shopCategory(),
            self::ATTRIBUTES => $this->attributes(),
            self::COVER => Media::url((int) $this->product->get_image_id()),
            self::GALLERY => array_values(array_filter(array_map(
                fn ($id) => Media::url((int) $id),
                $this->product->get_gallery_image_ids(),
            ))),
            self::VARIANTS => $this->variants(),
            self::WEIGHT => $this->size(fn (WC_Product $product) => wc_get_weight((float) $product->get_weight(), self::WEIGHT_UNIT)),
            self::LENGTH => $this->size(fn (WC_Product $product) => wc_get_dimension((float) $product->get_length(), self::DIMENSION_UNIT)),
            self::WIDTH => $this->size(fn (WC_Product $product) => wc_get_dimension((float) $product->get_width(), self::DIMENSION_UNIT)),
            self::HEIGHT => $this->size(fn (WC_Product $product) => wc_get_dimension((float) $product->get_height(), self::DIMENSION_UNIT)),
            default => null,
        };
    }

    public function file(mixed $entry): FileSource
    {
        return Media::file($entry);
    }

    private function price(): string|float|null
    {
        if (! $this->product instanceof WC_Product_Variable) {
            return $this->product->get_price();
        }
        $prices = array_map(fn (VariantRecord $variant) => (float) $variant->value(VariantRecord::PRICE), $this->variants());

        return $prices === [] ? null : min($prices);
    }

    private function quantity(): ?int
    {
        if (! $this->product->is_in_stock()) {
            return 0;
        }
        if ($this->product->managing_stock() || ! $this->product instanceof WC_Product_Variable) {
            return $this->product->managing_stock() ? max(0, (int) $this->product->get_stock_quantity()) : null;
        }
        $total = 0;
        foreach ($this->variations() as $variation) {
            if (! $variation->is_in_stock()) {
                continue;
            }
            if (! $variation->managing_stock()) {
                return null;
            }
            $total += max(0, (int) $variation->get_stock_quantity());
        }

        return $total;
    }

    /**
     * @return list<string>
     */
    private function shopCategory(): array
    {
        $best = [];
        foreach ($this->terms(self::CATEGORY_TAXONOMY) as $term) {
            $path = [...array_reverse(get_ancestors($term->term_id, self::CATEGORY_TAXONOMY, 'taxonomy')), $term->term_id];
            if (count($path) > count($best) || (count($path) === count($best) && $term->term_id < end($best))) {
                $best = $path;
            }
        }

        return array_map(fn (int $id) => Text::plain(get_term($id, self::CATEGORY_TAXONOMY)->name), $best);
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        $attributes = [];
        foreach ($this->product->get_attributes() as $attribute) {
            if (! $attribute instanceof WC_Product_Attribute || ! $attribute->get_visible() || $attribute->get_variation()) {
                continue;
            }
            $values = $attribute->is_taxonomy()
                ? wc_get_product_terms($this->product->get_id(), $attribute->get_name(), ['fields' => 'names'])
                : $attribute->get_options();
            if ($values !== []) {
                $label = Text::plain(wc_attribute_label($attribute->get_name(), $this->product));
                $attributes[$label] = implode(self::VALUE_SEPARATOR, array_map(Text::plain(...), $values));
            }
        }

        return $attributes;
    }

    /**
     * @return list<VariantRecord>
     */
    private function variants(): array
    {
        if (! $this->product instanceof WC_Product_Variable) {
            return $this->product->is_downloadable() ? [new VariantRecord($this->product, $this->product->get_name())] : [];
        }
        return array_map(
            fn (WC_Product_Variation $variation) => new VariantRecord($variation, VariantRecord::name($variation)),
            $this->offeredVariations(),
        );
    }

    /**
     * @param  callable(WC_Product): float  $measure
     */
    private function size(callable $measure): float
    {
        $products = $this->product instanceof WC_Product_Variable ? $this->offeredVariations() : [];

        return max(array_map($measure, $products ?: [$this->product]));
    }

    /**
     * @return list<WC_Product_Variation>
     */
    private function offeredVariations(): array
    {
        return array_values(array_filter(
            $this->variations(),
            fn (WC_Product_Variation $variation) => $variation->is_purchasable() && $variation->is_in_stock(),
        ));
    }

    /**
     * @return list<WC_Product_Variation>
     */
    private function variations(): array
    {
        if ($this->variations === null) {
            $children = $this->product->get_children();
            _prime_post_caches($children);
            $this->variations = array_values(array_filter(
                array_map('wc_get_product', $children),
                fn ($variation) => $variation instanceof WC_Product_Variation,
            ));
        }

        return $this->variations;
    }

    /**
     * @return list<\WP_Term>
     */
    private function terms(string $taxonomy): array
    {
        $terms = taxonomy_exists($taxonomy) ? get_the_terms($this->product->get_id(), $taxonomy) : [];

        return is_array($terms) ? array_values($terms) : [];
    }
}
