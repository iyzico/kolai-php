<?php

declare(strict_types=1);

namespace Kolai\Payment;

use Kolai\Exception\Errors;
use Kolai\Exception\KolaiException;

/**
 * Spreads a refund amount across a payment's item transactions.
 *
 * iyzico refunds per paymentTransactionId, never "the order", so one refund
 * becomes several calls. Getting the split wrong pays a customer twice, which is
 * why this is pure: no I/O, no clock. The caller owns persistence and locking.
 *
 * The already-refunded ledger is what makes a retry safe — amounts recorded
 * there are skipped, so a retry sends only the outstanding balance.
 */
final class RefundAllocator
{
    /** Currency comparison tolerance, in the order's currency unit. */
    public const EPSILON = 0.005;

    private function __construct()
    {
    }

    /**
     * @param array<int,array<string,mixed>> $transactions Stored itemTransactions;
     *        each needs paymentTransactionId and paidPrice.
     * @param array<string,float>            $alreadyRefunded The persisted ledger.
     * @return array{
     *     steps: array<int,array{paymentTransactionId:string,amount:float}>,
     *     unallocated: float
     * }
     *     `unallocated` > EPSILON: the caller MUST abort without sending
     *     anything. A partial plan refunds less than the store recorded.
     * @throws KolaiException
     */
    public static function plan(array $transactions, array $alreadyRefunded, float $amount): array
    {
        $steps     = [];
        $remaining = round($amount, 2);

        // A non-positive amount would otherwise leave `unallocated` <= 0 with no
        // steps, which passes the caller's abort check and silently refunds
        // nothing. Same rule as OrderMath::discount().
        if ($remaining <= 0) {
            throw Errors::invalidOrderRequest('refund amount must be greater than 0');
        }

        foreach ($transactions as $transaction) {
            if ($remaining <= self::EPSILON) {
                break;
            }
            if (!is_array($transaction)) {
                continue;
            }

            $transactionId = trim((string) ($transaction['paymentTransactionId'] ?? ''));
            if ($transactionId === '') {
                continue;
            }

            $paid       = (float) ($transaction['paidPrice'] ?? 0.0);
            $refunded   = (float) ($alreadyRefunded[$transactionId] ?? 0.0);
            $refundable = round($paid - $refunded, 2);

            if ($refundable <= self::EPSILON) {
                continue;
            }

            $step      = round(min($remaining, $refundable), 2);
            $steps[]   = ['paymentTransactionId' => $transactionId, 'amount' => $step];
            $remaining = round($remaining - $step, 2);
        }

        return ['steps' => $steps, 'unallocated' => $remaining];
    }

    /**
     * @param array<int,array<string,mixed>> $transactions
     * @param array<string,float>            $alreadyRefunded
     */
    public static function refundableTotal(array $transactions, array $alreadyRefunded): float
    {
        $total = 0.0;
        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }
            $transactionId = trim((string) ($transaction['paymentTransactionId'] ?? ''));
            if ($transactionId === '') {
                continue;
            }
            $refundable = round(
                (float) ($transaction['paidPrice'] ?? 0.0) - (float) ($alreadyRefunded[$transactionId] ?? 0.0),
                2
            );
            if ($refundable > self::EPSILON) {
                $total = round($total + $refundable, 2);
            }
        }

        return $total;
    }

    /**
     * Rounds and accumulates exactly the way plan() reads it back. Writing the
     * ledger with different rounding is how a kurus-sized balance becomes
     * permanently un-refundable.
     *
     * @param array<string,float> $ledger
     * @return array<string,float>
     */
    public static function record(array $ledger, string $transactionId, float $amount): array
    {
        $ledger[$transactionId] = round((float) ($ledger[$transactionId] ?? 0.0) + $amount, 2);

        return $ledger;
    }

    /**
     * Threaded into the iyzico conversationId. Carries the per-attempt operation
     * id so two partial refunds against the SAME transaction are never conflated
     * in iyzico's dashboard or a support ticket.
     */
    public static function conversationId(string $orderId, string $transactionId, string $operationId): string
    {
        return 'order-' . $orderId . '-tx-' . $transactionId . '-' . substr($operationId, 0, 8);
    }

    /** Idempotency key and audit handle for one refund attempt. */
    public static function newOperationId(): string
    {
        $bytes = random_bytes(16);
        // RFC 4122 version 4.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
