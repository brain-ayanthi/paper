<?php

namespace App\Services\Commerce;

use App\Services\Commerce\Contracts\CommerceProvider;

final class CommerceCredentialManager
{
    /** @param array<string, mixed> $credentials */
    public function forDisplay(CommerceProvider $provider, array $credentials): array
    {
        if (pos_is_demo()) {
            foreach ($credentials as $field => $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $credentials[$field] = mask_secret((string) $value);
                }
            }
        } else {
            foreach ($provider->secretFields() as $field) {
                if (! empty($credentials[$field])) {
                    $credentials[$field] = '********';
                }
            }
        }

        unset($credentials['access_token'], $credentials['access_token_expires_at'], $credentials['granted_scopes']);

        return $credentials;
    }

    /**
     * Blank and masked secret inputs mean "keep the stored value".
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    public function merge(CommerceProvider $provider, array $existing, array $submitted): array
    {
        foreach ($provider->secretFields() as $field) {
            $value = trim((string) ($submitted[$field] ?? ''));
            if ($value === '' || $value === '********') {
                unset($submitted[$field]);
            }
        }

        $changed = collect($provider->secretFields())->contains(
            fn (string $field) => array_key_exists($field, $submitted)
                && ($existing[$field] ?? null) !== $submitted[$field],
        );

        $merged = array_replace($existing, $submitted);
        if ($changed) {
            unset($merged['access_token'], $merged['access_token_expires_at'], $merged['granted_scopes']);
        }

        return array_filter($merged, fn ($value) => $value !== null && $value !== '');
    }
}
