<?php

declare(strict_types=1);

namespace Kolai\Support;

/**
 * Sanitizers, so library code never reaches for a host platform global.
 *
 * These produce values safe to STORE. Escaping for output (HTML, SQL, JSON)
 * stays the platform's job, at the point of use.
 */
final class Str
{
    private function __construct()
    {
    }

    /**
     * Drop markup, invalid UTF-8 and control characters; collapse whitespace.
     *
     * @param mixed $value
     */
    public static function clean($value): string
    {
        if (is_bool($value) || $value === null) {
            return '';
        }
        if (is_array($value) || is_object($value)) {
            return '';
        }

        $text = (string) $value;

        // Drop anything that is not valid UTF-8 before further processing;
        // preg_* silently bail out on malformed input otherwise.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $text = strip_tags($text);
        // Control characters including NUL, plus the Unicode line/paragraph
        // separators. Codepoint escapes, not byte escapes: under /u, PCRE reads
        // \xE2 as U+00E2, so a raw UTF-8 byte sequence here never matches.
        $text = (string) preg_replace('/[\x{0}-\x{1F}\x{7F}\x{2028}\x{2029}]/u', ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Returns '' when the result is not valid, so a half-valid address is never
     * persisted.
     *
     * @param mixed $value
     */
    public static function email($value): string
    {
        $email = self::clean($value);
        $email = (string) filter_var($email, FILTER_SANITIZE_EMAIL);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * The shape iyzico and the Kolai protocol expect: 2 decimals, dot
     * separator, no thousands grouping.
     *
     * @param mixed $amount
     */
    public static function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Preserves case and a leading underscore: third-party plugins use
     * protected keys like _billing_tax_id.
     *
     * @param mixed $key
     */
    public static function metaKey($key): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) $key));
    }
}
