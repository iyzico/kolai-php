<?php

declare(strict_types=1);

namespace Kolai\Address;

use Kolai\Exception\BadRequestException;
use Kolai\Exception\Errors;
use Kolai\Support\Str;

/**
 * Normalizes a Kolai address payload.
 *
 * Two things about the payload are not guessable from the code:
 *
 *  - The district (ilce) has arrived as `districtId`, then `district`, now
 *    `town`. All three are still in the wild; first non-empty wins.
 *  - `cityId` is a bare plate code ("6", "34"), not a state code.
 *
 * Output keys are neutral: country, state, city, postcode, addressLine1/2.
 */
final class AddressNormalizer
{
    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,string>
     * @throws BadRequestException
     */
    public static function destination(array $address): array
    {
        self::assertValid($address);

        $country = Str::clean($address['countryId'] ?? '');
        $state   = self::state($country, Str::clean($address['cityId'] ?? ''));
        $city    = Str::clean(self::district($address));

        // No district supplied: fall back to the province name so the city field
        // is not left blank. Coarser than the real ilce, but better than empty —
        // shipping zones and invoices both reject a blank city.
        if ($city === '' && $country === 'TR') {
            $city = TurkeyProvinces::name($state);
        }

        return [
            'country'      => $country,
            'state'        => $state,
            'city'         => $city,
            'postcode'     => Str::clean($address['postcode'] ?? ''),
            'addressLine1' => Str::clean($address['addressLine'] ?? ''),
            'addressLine2' => '',
        ];
    }

    /**
     * For a shipment-options quote, which carries no street address yet.
     *
     * @param array<string,mixed> $address
     * @return array<string,string>
     * @throws BadRequestException
     */
    public static function minimalDestination(array $address): array
    {
        self::assertValid($address);

        $country = Str::clean($address['countryId'] ?? '');

        return [
            'country'      => $country,
            'state'        => self::state($country, Str::clean($address['cityId'] ?? '')),
            'city'         => '',
            'postcode'     => '',
            'addressLine1' => '',
            'addressLine2' => '',
        ];
    }

    /**
     * Contact details belong on the billing address only — duplicating them onto
     * shipping spreads PII for no benefit.
     *
     * @param array<string,mixed> $address
     * @param array<string,mixed> $buyer
     * @return array<string,string>
     * @throws BadRequestException
     */
    public static function orderAddress(array $address, array $buyer, bool $includeContact): array
    {
        $destination = self::destination($address);

        $orderAddress = [
            'firstName'    => Str::clean($buyer['firstName'] ?? ''),
            'lastName'     => Str::clean($buyer['lastName'] ?? ''),
            'company'      => Str::clean($address['companyName'] ?? ''),
            'addressLine1' => $destination['addressLine1'],
            'addressLine2' => $destination['addressLine2'],
            'city'         => $destination['city'],
            'state'        => $destination['state'],
            'postcode'     => $destination['postcode'],
            'country'      => $destination['country'],
        ];

        if ($includeContact) {
            $orderAddress['email'] = Str::email($buyer['email'] ?? '');
            $orderAddress['phone'] = self::phone($buyer['phone'] ?? '');
        }

        return $orderAddress;
    }

    /**
     * @param array<string,mixed> $address
     * @throws BadRequestException
     */
    public static function assertValid(array $address): void
    {
        if (empty($address['countryId']) || empty($address['cityId'])) {
            throw Errors::invalidAddress('countryId and cityId are required');
        }
    }

    /**
     * "+905355401122" and "05355401122" both become "5355401122". Anything that
     * is not 10 digits after stripping is returned as-is, so foreign numbers are
     * not mangled into something undialable.
     *
     * @param mixed $phone
     */
    public static function phone($phone): string
    {
        $sanitized = Str::clean($phone);
        $digits    = (string) preg_replace('/\D+/', '', $sanitized);

        if (strlen($digits) === 12 && strpos($digits, '90') === 0) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && strpos($digits, '0') === 0) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : $sanitized;
    }

    /**
     * @param array<string,mixed> $address
     * @return mixed
     */
    private static function district(array $address)
    {
        foreach (['town', 'district', 'districtId'] as $key) {
            if (!empty($address[$key])) {
                return $address[$key];
            }
        }

        return '';
    }

    /** TR plate codes become "TR06"; everything else passes through as sent. */
    private static function state(string $country, string $cityId): string
    {
        if ($country !== 'TR') {
            return $cityId;
        }

        $plate = TurkeyProvinces::plateCode($cityId);

        return $plate === '' ? $cityId : 'TR' . $plate;
    }
}
