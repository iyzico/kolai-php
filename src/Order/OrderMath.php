<?php

declare(strict_types=1);

namespace Kolai\Order;

use Kolai\Exception\KolaiException;
use Kolai\Exception\Errors;

/**
 * The two money adjustments a Kolai order can need.
 *
 * Apply each as a single NON-TAXABLE fee line, so the total moves by exactly the
 * gross and no KDV is split out. That is what matches iyzico's paidPrice —
 * splitting tax out of an installment surcharge leaves the store total
 * disagreeing with the card statement by a few kurus.
 */
final class OrderMath
{
    /** Currency comparison tolerance. Sub-kurus differences are rounding, not drift. */
    public const EPSILON = 0.005;

    private function __construct()
    {
    }

    /**
     * @return float A positive, 2-decimal amount.
     * @throws KolaiException
     */
    public static function discount(float $orderTotal, $requested): float
    {
        if (!is_numeric($requested)) {
            throw Errors::invalidOrderRequest('discountAmount must be numeric');
        }

        $discount = round((float) $requested, 2);
        if ($discount <= 0) {
            throw Errors::invalidOrderRequest('discountAmount must be greater than 0');
        }
        if ($discount > round($orderTotal, 2)) {
            throw Errors::discountExceedsTotal();
        }

        return $discount;
    }

    /**
     * Reconciles the order total with what iyzico actually charged. The gap
     * between `paidPrice` and the base total is installment interest ("vade
     * farki", positive) or a discount ("indirim", negative).
     *
     * $baseTotal must EXCLUDE any previously-applied adjustment — strip the old
     * fee line and recalculate first. That is what makes repeated PATCHes with
     * the same paidPrice converge on one fee instead of stacking new ones.
     *
     * @param array<string,mixed> $payload
     * @return array{kind:string,amount:float,installment:int}
     *         kind 'interest' | 'discount' | 'none'; 'none' means remove any
     *         existing adjustment and write no fee.
     */
    public static function installmentAdjustment(array $payload, float $baseTotal): array
    {
        $installment = isset($payload['installment']) ? (int) $payload['installment'] : 1;
        $none        = ['kind' => 'none', 'amount' => 0.0, 'installment' => $installment];

        if (!isset($payload['paidPrice']) || !is_numeric($payload['paidPrice'])) {
            return $none;
        }

        $paidPrice = round((float) $payload['paidPrice'], 2);
        if ($paidPrice <= 0) {
            return $none;
        }

        $baseTotal = round($baseTotal, 2);
        $diff      = round($paidPrice - $baseTotal, 2);

        if (abs($diff) < self::EPSILON) {
            return $none;
        }

        // No lower-bound guard is needed: paidPrice is already known positive, so
        // baseTotal + diff == paidPrice > 0. The adjusted total can never go
        // negative, however large the discount.
        return [
            'kind'        => $diff > 0 ? 'interest' : 'discount',
            'amount'      => $diff,
            'installment' => $installment,
        ];
    }

    /**
     * How far an applied discount missed. Per-rate rounding shifts a total by
     * sub-kurus; anything larger is an allocation bug worth logging.
     */
    public static function discountDrift(float $totalBefore, float $totalAfter, float $discount): float
    {
        return round(($totalBefore - $totalAfter) - $discount, 2);
    }
}
