<?php

declare(strict_types=1);

namespace Kolai\Payment;

use Iyzipay\Model\Cancel;
use Iyzipay\Model\Currency;
use Iyzipay\Model\Locale;
use Iyzipay\Model\Refund;
use Iyzipay\Model\Status;
use Iyzipay\Options;
use Iyzipay\Request\CreateCancelRequest;
use Iyzipay\Request\CreateRefundRequest;
use Kolai\Support\Str;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Wraps iyzipay-php for the only two operations Kolai modules perform directly
 * against iyzico: refund and cancel.
 *
 * Returns a normalized array instead of throwing, because the caller must tell
 * three outcomes apart — succeeded, refused by iyzico, never reached iyzico —
 * and a thrown exception collapses the last two. "Did the money move?" is the
 * one question that must not be ambiguous.
 */
final class IyzicoClient
{
    private IyzicoCredentials $credentials;

    private LoggerInterface $logger;

    public function __construct(IyzicoCredentials $credentials, ?LoggerInterface $logger = null)
    {
        $this->credentials = $credentials;
        $this->logger      = $logger ?? new NullLogger();
    }

    public function isConfigured(): bool
    {
        return $this->credentials->isConfigured();
    }

    /**
     * One basket item's transaction, fully or partially.
     *
     * @param string $currency Store currency code, e.g. TRY.
     * @return array{success:bool,errorCode:?string,errorMessage:?string,raw:?object}
     */
    public function refund(
        string $paymentTransactionId,
        float $price,
        string $currency,
        string $conversationId = ''
    ): array {
        $request = new CreateRefundRequest();
        $request->setLocale(Locale::TR);
        if ($conversationId !== '') {
            $request->setConversationId($conversationId);
        }
        $request->setPaymentTransactionId($paymentTransactionId);
        $request->setPrice(Str::money($price));
        $request->setCurrency(self::currency($currency));

        $context = [
            'paymentTransactionId' => $paymentTransactionId,
            'price'                => Str::money($price),
            'currency'             => self::currency($currency),
            'conversationId'       => $conversationId,
        ];
        $this->logger->info('kolai.payment: iyzico refund request', $context);

        return $this->send(
            static function (Options $options) use ($request) {
                return Refund::create($request, $options);
            },
            'refund',
            ['paymentTransactionId' => $paymentTransactionId]
        );
    }

    /**
     * iyzico allows this same-day and for the full amount only; anything else
     * must go through refund().
     *
     * @return array{success:bool,errorCode:?string,errorMessage:?string,raw:?object}
     */
    public function cancel(string $paymentId, string $conversationId = ''): array
    {
        $request = new CreateCancelRequest();
        $request->setLocale(Locale::TR);
        if ($conversationId !== '') {
            $request->setConversationId($conversationId);
        }
        $request->setPaymentId($paymentId);

        $this->logger->info('kolai.payment: iyzico cancel request', [
            'paymentId'      => $paymentId,
            'conversationId' => $conversationId,
        ]);

        return $this->send(
            static function (Options $options) use ($request) {
                return Cancel::create($request, $options);
            },
            'cancel',
            ['paymentId' => $paymentId]
        );
    }

    /**
     * @param callable(Options):object $call
     * @param array<string,mixed>      $context
     * @return array{success:bool,errorCode:?string,errorMessage:?string,raw:?object}
     */
    private function send(callable $call, string $operation, array $context): array
    {
        try {
            $result = $call($this->options());
        } catch (Throwable $e) {
            // Network/SDK failure: the request may or may not have reached
            // iyzico. errorCode stays null so the caller can tell this apart
            // from a refusal, which always carries one.
            $this->logger->error('kolai.payment: iyzico ' . $operation . ' threw', $context + [
                'error' => $e->getMessage(),
            ]);

            return [
                'success'      => false,
                'errorCode'    => null,
                'errorMessage' => $e->getMessage(),
                'raw'          => null,
            ];
        }

        $success    = method_exists($result, 'getStatus') && $result->getStatus() === Status::SUCCESS;
        $normalized = [
            'success'      => $success,
            'errorCode'    => method_exists($result, 'getErrorCode') ? $result->getErrorCode() : null,
            'errorMessage' => method_exists($result, 'getErrorMessage') ? $result->getErrorMessage() : null,
            'raw'          => $result,
        ];

        if ($success) {
            $this->logger->info('kolai.payment: iyzico ' . $operation . ' succeeded', $context);
        } else {
            $this->logger->error('kolai.payment: iyzico ' . $operation . ' failed', $context + [
                'errorCode'    => $normalized['errorCode'],
                'errorMessage' => $normalized['errorMessage'],
            ]);
        }

        return $normalized;
    }

    private function options(): Options
    {
        $options = new Options();
        $options->setApiKey($this->credentials->apiKey());
        $options->setSecretKey($this->credentials->secretKey());
        $options->setBaseUrl($this->credentials->baseUrl());

        return $options;
    }

    /**
     * iyzico spells Turkish Lira "TRY". A store holding it as "TL" must be
     * translated or the refund is refused. Other codes pass through.
     */
    private static function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || $currency === 'TL') {
            return Currency::TL;
        }

        return $currency;
    }
}
