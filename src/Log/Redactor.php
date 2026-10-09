<?php

declare(strict_types=1);

namespace Kolai\Log;

/**
 * Strips personal data out of a request payload before it is logged.
 *
 * Kolai order and shipping bodies carry names, e-mail, phone, TCKN/VKN and full
 * addresses. A log that keeps them is a second, long-lived, usually unprotected
 * copy of the store's customer database.
 *
 * What survives is the request's SHAPE — which keys, how many items, how big —
 * which is the part that actually helps when debugging.
 */
final class Redactor
{
    /** Hard cap on the encoded payload kept in one log entry. */
    public const MAX_BODY_BYTES = 2000;

    /** Max nesting depth walked before bailing out. */
    private const MAX_DEPTH = 6;

    public const MARKER = '[redacted]';

    /**
     * Dropped whole: everything inside is personal data, and walking them risks
     * missing a nested field.
     */
    private const SENSITIVE_KEYS = [
        'buyer',
        'billingaddress',
        'shippingaddress',
        'address',
        'itemtransactions',
    ];

    /**
     * Fragments matched anywhere in a key name. Kept specific on purpose: a bare
     * 'pan' would hit 'company', bare 'lat'/'lng' would hit 'late'/'flat'. Card
     * data is covered by card/iban/cvv, coordinates by latitude/longitude.
     */
    private const SENSITIVE_FRAGMENTS = [
        'email', 'phone', 'gsm', 'name', 'surname', 'identity', 'tckn',
        'taxid', 'taxoffice', 'address', 'city', 'district', 'town', 'zip',
        'postcode', 'postal', 'street', 'iban', 'card', 'cvv', 'cvc',
        'payment', 'latitude', 'longitude', 'contact',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<array-key,mixed> $data
     * @return array<array-key,mixed>
     */
    public static function payload(array $data): array
    {
        $redacted = self::walk($data, 0);
        $encoded  = json_encode($redacted);

        if (is_string($encoded) && strlen($encoded) > self::MAX_BODY_BYTES) {
            return ['_redactedSummary' => substr($encoded, 0, self::MAX_BODY_BYTES) . '… (truncated)'];
        }

        return is_array($redacted) ? $redacted : ['_value' => $redacted];
    }

    /**
     * For a body whose structure is unknown, so it cannot be field-redacted:
     * records its size, never its contents.
     *
     * @return array<string,int>
     */
    public static function unparsedBody(string $raw): array
    {
        return ['_unparsedBytes' => strlen($raw)];
    }

    public static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (strpos($key, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function walk($value, int $depth)
    {
        if ($depth > self::MAX_DEPTH) {
            return '[redacted-depth]';
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && self::isSensitiveKey($key)) {
                    $out[$key] = self::MARKER;
                    continue;
                }
                $out[$key] = self::walk($item, $depth + 1);
            }

            return $out;
        }

        // Long free-text values are the other way PII sneaks in (an address typed
        // into a note field). Keep enough to recognise, not enough to identify.
        if (is_string($value) && strlen($value) > 120) {
            return substr($value, 0, 120) . '…';
        }

        return $value;
    }
}
