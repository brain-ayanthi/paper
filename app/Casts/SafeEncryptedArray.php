<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * The array counterpart to {@see SafeEncrypted} — stores a JSON array
 * encrypted at rest, and never throws on read.
 *
 * Security register SEC #61: `payment_methods.provider_credentials` was cast
 * as a plain `array`, so every live gateway secret (Stripe `sk_live`, Razorpay
 * `key_secret`, Paystack / Flutterwave `secret_key`, MercadoPago
 * `access_token`) and every `webhook_secret` sat in the database as readable
 * JSON. On shared hosting a leaked DB backup hands over the merchant's payment
 * account outright.
 *
 * Two tolerances the built-in `encrypted:array` cast does not give us, both of
 * which matter on a self-hosted product:
 *
 *  1. **Legacy plaintext.** Installs that predate the encrypting migration —
 *     or a restore from an older backup — still hold bare JSON. Those decode
 *     as-is instead of throwing, so an install keeps taking payments while the
 *     migration catches up. Re-saving the credential writes it back encrypted.
 *
 *  2. **Wrong APP_KEY.** A DB copied between environments, or a rotated key,
 *     would otherwise raise DecryptException on every read and 500 the
 *     checkout and the settings page alike. Degrading to an empty array means
 *     the gateway reports itself unconfigured and the operator just re-enters
 *     the keys — a bad afternoon, not an outage.
 */
class SafeEncryptedArray implements CastsAttributes
{
    /** @return array<string, mixed> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        try {
            $decoded = json_decode(Crypt::decryptString((string) $value), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            // Not decryptable. Either it predates the encrypting migration
            // (bare JSON — read it), or the APP_KEY no longer matches (give
            // up quietly rather than break the request).
            $decoded = json_decode((string) $value, true);

            if (is_array($decoded)) {
                return $decoded;
            }

            Log::warning('Could not read encrypted attribute; treating as unset.', [
                'model'     => $model::class,
                'attribute' => $key,
            ]);

            return [];
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }

        return Crypt::encryptString(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
