<?php

declare(strict_types=1);

namespace Kolai\Mapper;

use Kolai\Support\Str;

/**
 * Maps a neutral product array into the camelCase DTO Kolai expects. Modules
 * produce the snake_case input from their own data layer; the wire format lives
 * here, so a renamed field changes in one place.
 *
 * Input keys, all optional:
 *   id, name, description, permalink, image['url'], gallery[]['url'],
 *   in_stock, price, sale_price, included_tax, tax_price, tax_percentage,
 *   date_on_sale_from, date_on_sale_to, average_rating, review_count, type,
 *   sku, parent_id, weight, dimensions{length,width,height},
 *   attributes, variations[]
 *
 * price / sale_price must already be TAX-INCLUSIVE when the product is taxable;
 * tax_price and tax_percentage describe the tax component of that figure.
 */
final class ProductMapper
{
    private string $currency;

    public function __construct(string $currency)
    {
        $this->currency = strtoupper($currency);
    }

    /**
     * @param array<string,mixed> $product
     * @return array<string,mixed>|null
     */
    public function map(array $product): ?array
    {
        if ($product === []) {
            return null;
        }

        $mapped = [];

        if (isset($product['id'])) {
            $mapped['id'] = (string) $product['id'];
        }
        if (isset($product['name'])) {
            $mapped['title'] = $product['name'];
        }
        if (isset($product['description'])) {
            $mapped['description'] = $product['description'];
        }
        if (isset($product['permalink'])) {
            $mapped['link'] = $product['permalink'];
        }
        if (isset($product['image']['url'])) {
            $mapped['imageLink'] = $product['image']['url'];
        }

        $gallery = [];
        foreach ((array) ($product['gallery'] ?? []) as $item) {
            if (isset($item['url'])) {
                $gallery[] = $item['url'];
            }
        }
        if ($gallery !== []) {
            $mapped['additionalImageLinks'] = $gallery;
        }

        if (isset($product['in_stock'])) {
            $mapped['inStock'] = (bool) $product['in_stock'];
        }

        $mapped['currency'] = $this->currency;

        $mapped += $this->priceFields($product);

        // Both ends of the window are required: a half-specified range tells the
        // consumer nothing about when the sale actually applies.
        if (!empty($product['date_on_sale_from']) && !empty($product['date_on_sale_to'])) {
            $mapped['salePriceEffectiveDate'] = $product['date_on_sale_from'] . '/' . $product['date_on_sale_to'];
        }

        if (isset($product['average_rating'])) {
            $mapped['averageRating'] = round((float) $product['average_rating'], 2);
        }
        if (isset($product['review_count'])) {
            $mapped['reviewCount'] = (int) $product['review_count'];
        }

        if (isset($product['type'])) {
            $mapped['productType'] = $product['type'];
            if ($product['type'] === 'bundle') {
                $mapped['isBundle'] = 'yes';
            }
        }

        if (!empty($product['sku'])) {
            $mapped['gtin'] = $product['sku'];
            $mapped['mpn']  = $product['sku'];
        }

        if (!empty($product['parent_id'])) {
            $mapped['itemGroupId'] = (string) $product['parent_id'];
        }

        foreach (['length' => 'productLength', 'width' => 'productWidth', 'height' => 'productHeight'] as $k => $out) {
            if (!empty($product['dimensions'][$k])) {
                $mapped[$out] = (string) $product['dimensions'][$k];
            }
        }
        if (!empty($product['weight'])) {
            $mapped['productWeight'] = (string) $product['weight'];
        }

        if (isset($product['variations']) && is_array($product['variations'])) {
            $mapped += $this->variationFields($product['variations']);
        }
        if (isset($product['attributes'])) {
            $mapped['attributes'] = $product['attributes'];
        }

        return $mapped;
    }

    /**
     * @param array<int,array<string,mixed>> $products
     * @return array<int,array<string,mixed>>
     */
    public function mapMany(array $products): array
    {
        $out = [];
        foreach ($products as $product) {
            $mapped = is_array($product) ? $this->map($product) : null;
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * Shared by products and variations.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function priceFields(array $data): array
    {
        $fields = [];

        if (isset($data['price'])) {
            $fields['price'] = Str::money($data['price']);
        }
        // Only an ACTIVE sale price belongs here. A scheduled or expired sale
        // must never be advertised as live, so the platform layer passes null.
        if (!empty($data['sale_price'])) {
            $fields['salePrice'] = Str::money($data['sale_price']);
        }

        $fields['includedTax'] = (bool) ($data['included_tax'] ?? false);
        if (isset($data['tax_price'])) {
            $fields['taxPrice'] = Str::money($data['tax_price']);
        }
        if (isset($data['tax_percentage'])) {
            $fields['taxPercentage'] = (float) $data['tax_percentage'];
        }

        return $fields;
    }

    /**
     * A platform that caps how many variations it loads appends a
     * ['_truncated' => true, '_max' => N] sentinel rather than silently
     * returning a short list — otherwise truncated data is indistinguishable
     * from a product that genuinely has few variants.
     *
     * @param array<int,mixed> $variations
     * @return array<string,mixed>
     */
    private function variationFields(array $variations): array
    {
        $fields = [];
        $mapped = [];

        foreach ($variations as $variation) {
            if (!is_array($variation)) {
                continue;
            }
            if (!empty($variation['_truncated'])) {
                $fields['variationsTruncated'] = true;
                if (isset($variation['_max'])) {
                    $fields['variationsMax'] = (int) $variation['_max'];
                }
                continue;
            }

            $row = [];
            if (isset($variation['id'])) {
                $row['id'] = (int) $variation['id'];
            }
            if (isset($variation['sku'])) {
                $row['sku'] = $variation['sku'];
            }
            if (isset($variation['description'])) {
                $row['description'] = $variation['description'];
            }

            $row += $this->priceFields($variation);

            if (isset($variation['in_stock'])) {
                $row['inStock'] = (bool) $variation['in_stock'];
            }
            if (isset($variation['attributes'])) {
                $row['attributes'] = $variation['attributes'];
            }
            if (isset($variation['image'])) {
                $row['image'] = $variation['image'];
            }

            $mapped[] = $row;
        }

        $fields['variations'] = $mapped;

        return $fields;
    }
}
