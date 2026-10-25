<?php

namespace App\Support;

/**
 * Decimal-string amount ⇄ gateway minor units (cents, paise, kobo…).
 *
 * One shared conversion, used both when a gateway session is STARTED and
 * when a webhook's amount is CHECKED against it (code-review #8) — the two
 * sides must agree on the exponent or every legitimate payment would
 * "mismatch". Previously duplicated in GatewayController and
 * CustomerPayController; keep them delegating here.
 *
 * v1 assumes 2 decimals outside the known zero/three-decimal sets, matching
 * what the gateway integrations already did.
 */
class CurrencyMinorUnits
{
    private const ZERO_DECIMAL  = ['JPY', 'KRW', 'VND', 'CLP', 'IDR'];
    private const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /** "12.5000" + "USD" → "1250" */
    public static function toMinor(string $amount, string $currency): string
    {
        return \bcmul($amount, \bcpow('10', (string) self::decimalsFor($currency), 0), 0);
    }

    public static function decimalsFor(string $currency): int
    {
        $upper = \strtoupper($currency);

        return match (true) {
            \in_array($upper, self::ZERO_DECIMAL, true)  => 0,
            \in_array($upper, self::THREE_DECIMAL, true) => 3,
            default                                      => 2,
        };
    }
}
