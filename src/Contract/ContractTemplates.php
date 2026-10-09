<?php

declare(strict_types=1);

namespace Kolai\Contract;

use Kolai\Exception\KolaiException;
use Kolai\Exception\Errors;

/**
 * The two documents Turkish distance-selling law (6502 / Mesafeli Sozlesmeler
 * Yonetmeligi) requires a store to present before checkout.
 *
 * The bundled texts are a STARTING POINT, not a requirement: a store that has
 * its own contract passes it as $stored and the default is never read. Only the
 * two type names are fixed, because Kolai asks for one of them by name.
 *
 * SELLER placeholders are filled from store settings here. BUYER and ORDER
 * placeholders are left in the output on purpose — Kolai substitutes those at
 * checkout, when it knows who is buying what.
 */
final class ContractTemplates
{
    public const DISTANCE_SALES   = 'distance_sales';
    public const PRELIMINARY_INFO = 'preliminary_info';

    /** @var array<string,string> type => human title */
    private const TYPES = [
        self::DISTANCE_SALES   => 'Mesafeli Satis Sozlesmesi',
        self::PRELIMINARY_INFO => 'On Bilgilendirme Formu',
    ];

    /**
     * Filled per order by Kolai. Listed so the admin UI can show a store owner
     * what is available while editing a template.
     *
     * @var array<string,string>
     */
    private const ORDER_PLACEHOLDERS = [
        '{{buyer_name}}'         => 'Alıcı adı',
        '{{buyer_company_name}}' => 'Alıcı firma unvanı',
        '{{buyer_tax_id}}'       => 'Alıcı VKN / TCKN',
        '{{buyer_tax_office}}'   => 'Alıcı vergi dairesi',
        '{{buyer_email}}'        => 'Alıcı e-posta adresi',
        '{{buyer_phone}}'        => 'Alıcı telefonu',
        '{{buyer_address}}'      => 'Alıcı adresi',
        '{{order_date}}'         => 'Sipariş tarihi',
        '{{order_total}}'        => 'Sipariş toplamı',
        '{{order_currency}}'     => 'Para birimi',
        '{{payment_method}}'     => 'Ödeme yöntemi',
        '{{shipping_method}}'    => 'Kargo yöntemi',
        '{{shipping_cost}}'      => 'Kargo ücreti',
        '{{product_list}}'       => 'Ürün listesi (HTML tablo)',
    ];

    /**
     * Filled from store settings, before Kolai ever sees the template.
     *
     * @var array<string,string>
     */
    private const SELLER_PLACEHOLDERS = [
        '{{seller_name}}'                => 'Satıcı unvanı',
        '{{seller_address}}'             => 'Satıcı adresi',
        '{{seller_phone}}'               => 'Satıcı telefonu',
        '{{seller_email}}'               => 'Satıcı e-posta adresi',
        '{{seller_tax_id}}'              => 'Satıcı VKN',
        '{{seller_mersis_no}}'           => 'Satıcı MERSIS numarası',
        '{{delivery_date}}'              => 'Tahmini teslim tarihi',
        '{{right_of_withdrawal_period}}' => 'Cayma hakkı süresi',
    ];

    private function __construct()
    {
    }

    /** @return array<string,string> */
    public static function types(): array
    {
        return self::TYPES;
    }

    /** @return array<string,string> */
    public static function orderPlaceholders(): array
    {
        return self::ORDER_PLACEHOLDERS;
    }

    /** @return array<string,string> */
    public static function sellerPlaceholders(): array
    {
        return self::SELLER_PLACEHOLDERS;
    }

    /**
     * @throws KolaiException
     */
    public static function assertValidType(string $type): void
    {
        if (!isset(self::TYPES[$type])) {
            throw Errors::invalidContractType('Invalid contract type: ' . $type);
        }
    }

    /**
     * @param string|null          $stored   The store's own template, or null for
     *                                       the bundled default.
     * @param array<string,string> $settings Seller settings, keyed WITHOUT braces:
     *                                       ['seller_name' => 'Acme Ltd'].
     * @return array{content:string,placeholders:array<string,string>}
     *         `placeholders` lists what the consumer still has to fill.
     * @throws KolaiException
     */
    public static function render(string $type, ?string $stored, array $settings): array
    {
        self::assertValidType($type);

        $template = ($stored === null || trim($stored) === '') ? self::defaultTemplate($type) : $stored;
        if (trim($template) === '') {
            throw Errors::contractNotFound('Contract template not found for type: ' . $type);
        }

        $content = self::applySellerSettings($template, $settings);

        return [
            'content'      => $content,
            'placeholders' => self::remainingPlaceholders($content),
        ];
    }

    /**
     * An absent setting leaves its placeholder in place rather than blanking it:
     * an empty MERSIS line in a legal document is worse than a visibly unfilled
     * one.
     *
     * @param array<string,string> $settings Keys without braces.
     */
    public static function applySellerSettings(string $template, array $settings): string
    {
        $replacements = [];
        foreach (array_keys(self::SELLER_PLACEHOLDERS) as $placeholder) {
            $key = trim($placeholder, '{}');
            if (isset($settings[$key]) && $settings[$key] !== '') {
                $replacements[$placeholder] = $settings[$key];
            }
        }

        return $replacements === [] ? $template : strtr($template, $replacements);
    }

    /** @return array<string,string> Order placeholders still in the content. */
    public static function remainingPlaceholders(string $content): array
    {
        $found = [];
        foreach (self::ORDER_PLACEHOLDERS as $placeholder => $description) {
            if (strpos($content, $placeholder) !== false) {
                $found[$placeholder] = $description;
            }
        }

        return $found;
    }

    /**
     * Placeholders in the content that nothing will ever fill — neither the
     * store's settings nor Kolai knows them.
     *
     * A store writing its own contract can invent `{{kargo_firmasi}}`, and it
     * would reach the customer as literal braces inside a legal document. Show
     * this in the admin UI so the author sees it before a customer does.
     *
     * @return array<int,string>
     */
    public static function unknownPlaceholders(string $content): array
    {
        preg_match_all('/\{\{[A-Za-z0-9_]+\}\}/', $content, $matches);

        $known = self::ORDER_PLACEHOLDERS + self::SELLER_PLACEHOLDERS;

        return array_values(array_unique(array_filter(
            $matches[0],
            static function (string $placeholder) use ($known): bool {
                return !isset($known[$placeholder]);
            }
        )));
    }

    /**
     * @throws KolaiException
     */
    public static function defaultTemplate(string $type): string
    {
        self::assertValidType($type);

        $file = __DIR__ . '/templates/' . $type . '.html';
        $html = is_readable($file) ? file_get_contents($file) : false;

        if ($html === false) {
            throw Errors::contractNotFound('Default template missing for type: ' . $type);
        }

        return $html;
    }
}
