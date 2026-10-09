<?php

declare(strict_types=1);

namespace Kolai\Order;

use Kolai\Address\AddressNormalizer;
use Kolai\Exception\KolaiException;
use Kolai\Exception\Errors;

/**
 * Validates a create-order payload before a single row is written.
 *
 * Pure: no store lookups. The module runs this first, then does its own stock
 * and product checks — which need the catalogue — against the lines returned.
 */
final class OrderRequestValidator
{
    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array{productId:int,variationId:int,quantity:int}>
     * @throws KolaiException
     */
    public static function validate(array $payload): array
    {
        self::assertBuyer($payload['buyer'] ?? null);
        self::assertInvoice($payload['billingAddress'] ?? null);

        // Both addresses must be structurally valid before anything is created:
        // discovering a bad shipping address after the order shell exists is how
        // orphan orders happen.
        AddressNormalizer::assertValid((array) ($payload['billingAddress'] ?? []));
        AddressNormalizer::assertValid((array) ($payload['shippingAddress'] ?? []));

        $lines = self::lines($payload['products'] ?? null);

        if (empty($payload['shipmentOptionId'])) {
            throw Errors::invalidShipmentOption('shipmentOptionId is required');
        }

        return $lines;
    }

    /**
     * @param mixed $buyer
     * @throws KolaiException
     */
    public static function assertBuyer($buyer): void
    {
        if (!is_array($buyer) || empty($buyer['email'])) {
            throw Errors::invalidOrderRequest('buyer.email is required');
        }
    }

    /**
     * TCKN is 11 digits, VKN is 10. Swapping them yields an invoice the tax
     * office rejects long after the order shipped, so it is checked up front
     * rather than at invoice time.
     *
     * @param mixed $billing
     * @throws KolaiException
     */
    public static function assertInvoice($billing): void
    {
        if (!is_array($billing)) {
            return;
        }

        $invoiceType = strtolower(trim((string) ($billing['invoiceType'] ?? '')));
        if ($invoiceType === '') {
            return;
        }

        if (!in_array($invoiceType, ['personal', 'company'], true)) {
            throw Errors::invalidOrderRequest('billingAddress.invoiceType must be personal or company');
        }

        $taxId = trim((string) ($billing['taxId'] ?? ''));
        if ($taxId === '') {
            return;
        }

        if ($invoiceType === 'company' && !preg_match('/^\d{10}$/', $taxId)) {
            throw Errors::invalidOrderRequest('billingAddress.taxId must be 10 digits for company invoice');
        }
        if ($invoiceType === 'personal' && !preg_match('/^\d{11}$/', $taxId)) {
            throw Errors::invalidOrderRequest('billingAddress.taxId must be 11 digits for personal invoice');
        }
    }

    /**
     * Accepts every key spelling Kolai and the shipment-options endpoint have
     * used (productId / product_id / id, variationId / variation_id,
     * quantity / qty), so a caller can migrate one field at a time.
     *
     * @param mixed $products
     * @return array<int,array{productId:int,variationId:int,quantity:int}>
     * @throws KolaiException
     */
    public static function lines($products): array
    {
        if (!is_array($products) || $products === []) {
            throw Errors::invalidProductList('Products list is required');
        }

        $lines = [];
        foreach ($products as $entry) {
            $lines[] = self::line($entry);
        }

        return $lines;
    }

    /**
     * @param mixed $entry
     * @return array{productId:int,variationId:int,quantity:int}
     * @throws KolaiException
     */
    private static function line($entry): array
    {
        // A bare numeric id is the legacy shipment-options form: one unit.
        if (is_numeric($entry)) {
            $productId = (int) $entry;
            if ($productId <= 0) {
                throw Errors::invalidProductList('productId must be a positive integer');
            }

            return ['productId' => $productId, 'variationId' => 0, 'quantity' => 1];
        }

        if (!is_array($entry)) {
            throw Errors::invalidProductList('Each product entry must be an id or an object');
        }

        $productId   = (int) self::firstNumeric($entry, ['productId', 'product_id', 'id'], 0);
        $variationId = (int) self::firstNumeric($entry, ['variationId', 'variation_id'], 0);
        $quantity    = (int) self::firstNumeric($entry, ['quantity', 'qty'], 1);

        if ($productId <= 0) {
            throw Errors::invalidProductList('productId is required');
        }
        if ($quantity < 1) {
            throw Errors::invalidProductList('quantity must be at least 1');
        }

        return ['productId' => $productId, 'variationId' => $variationId, 'quantity' => $quantity];
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<int,string>   $keys
     * @param mixed               $default
     * @return mixed
     */
    private static function firstNumeric(array $entry, array $keys, $default)
    {
        foreach ($keys as $key) {
            if (isset($entry[$key]) && is_numeric($entry[$key])) {
                return $entry[$key];
            }
        }

        return $default;
    }
}
