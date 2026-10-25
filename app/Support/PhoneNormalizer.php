<?php

namespace App\Support;

/**
 * Light phone normalizer for storage. Strips spaces, dashes, dots, and
 * parens; keeps a leading `+` if present. WhatsApp callers can additionally
 * convert local numbers to an E.164-shaped value using the store's ISO country.
 */
class PhoneNormalizer
{
    /** Common ISO-3166 country calling codes; numbers with a leading + do not need this map. */
    private const CALLING_CODES = [
        'AE' => '971', 'AR' => '54', 'AT' => '43', 'AU' => '61', 'BD' => '880', 'BE' => '32',
        'BR' => '55', 'CA' => '1', 'CH' => '41', 'CL' => '56', 'CN' => '86', 'CO' => '57',
        'CZ' => '420', 'DE' => '49', 'DK' => '45', 'EG' => '20', 'ES' => '34', 'FI' => '358',
        'FR' => '33', 'GB' => '44', 'GH' => '233', 'GR' => '30', 'HK' => '852', 'HU' => '36',
        'ID' => '62', 'IE' => '353', 'IL' => '972', 'IN' => '91', 'IT' => '39', 'JP' => '81',
        'KE' => '254', 'KR' => '82', 'LK' => '94', 'MX' => '52', 'MY' => '60', 'NG' => '234',
        'NL' => '31', 'NO' => '47', 'NP' => '977', 'NZ' => '64', 'OM' => '968', 'PE' => '51',
        'PH' => '63', 'PK' => '92', 'PL' => '48', 'PT' => '351', 'QA' => '974', 'RO' => '40',
        'SA' => '966', 'SE' => '46', 'SG' => '65', 'TH' => '66', 'TR' => '90', 'TW' => '886',
        'TZ' => '255', 'UG' => '256', 'US' => '1', 'VN' => '84', 'ZA' => '27',
    ];

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $hasPlus = str_starts_with($trimmed, '+');
        $digits  = preg_replace('/[^\d]/', '', $trimmed);
        if ($digits === '') {
            return null;
        }

        return $hasPlus ? '+'.$digits : $digits;
    }

    /** Convert a stored/local number to WhatsApp's E.164 representation. */
    public static function toE164(?string $raw, ?string $countryCode = null): ?string
    {
        $normalized = self::normalize($raw);
        if ($normalized === null) {
            return null;
        }

        if (str_starts_with($normalized, '+')) {
            $digits = substr($normalized, 1);
        } else {
            $callingCode = self::CALLING_CODES[strtoupper((string) $countryCode)] ?? null;
            if ($callingCode === null) {
                return null;
            }
            $local = ltrim($normalized, '0');
            $digits = str_starts_with($local, $callingCode) ? $local : $callingCode.$local;
        }

        return preg_match('/^[1-9]\d{7,14}$/', $digits) ? '+'.$digits : null;
    }
}
