<?php

namespace App\Services\Updater;

use App\Exceptions\InvalidUpdateSignature;

/**
 * Verifies a downloaded update zip before it's allowed anywhere near the live
 * install (docs/features/updater-hooks.md §4). Two independent guarantees:
 *
 *   1. SHA-256 — the download is bit-perfect (catches truncation / corruption).
 *   2. Ed25519 detached signature — the file genuinely came from us, not a
 *      compromised feed or a man-in-the-middle.
 *
 * Signature policy (SEC #3 / #100): verification is ALWAYS enforced. It used
 * to be skipped whenever `APP_ENV !== 'production'`, which is the wrong
 * default for a product installed by copying files onto shared hosting —
 * plenty of real installs never change APP_ENV from `local`, and such an
 * install would accept an unsigned or attacker-modified package and copy it
 * straight over the live application.
 *
 * The only way past is `pos.updater.allow_unsigned`, which must be turned on
 * deliberately and is never the default.
 */
class SignatureVerifier
{
    /** Abort unless the file's SHA-256 matches the feed's. */
    public function verifyChecksum(string $filePath, string $expectedSha256): void
    {
        if ($expectedSha256 === '') {
            return;
        }

        $actual = hash_file('sha256', $filePath);
        if ($actual === false || ! hash_equals(strtolower($expectedSha256), strtolower($actual))) {
            throw new InvalidUpdateSignature(__('updates.errors.checksum_mismatch'));
        }
    }

    /** Abort unless the detached Ed25519 signature validates against our key. */
    public function verifySignature(string $filePath, ?string $signatureBase64): void
    {
        // Deliberate, explicit opt-out for developing the flow without a
        // signing key. Checked first so the messages below are never reached
        // on a machine that has knowingly turned verification off.
        if ($this->unsignedAllowed()) {
            return;
        }

        $publicKeyBase64 = (string) config('pos.updater.public_key', '');

        if ($publicKeyBase64 === '') {
            throw new InvalidUpdateSignature(__('updates.errors.no_public_key'));
        }

        // libsodium ships with PHP by default but a host can disable it. We
        // cannot verify without it, and an unverifiable package must not be
        // installed — so this is fatal rather than a skip.
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new InvalidUpdateSignature(__('updates.errors.sodium_unavailable'));
        }

        if (! $signatureBase64) {
            throw new InvalidUpdateSignature(__('updates.errors.signature_missing'));
        }

        $bytes     = file_get_contents($filePath);
        $signature = base64_decode($signatureBase64, true);
        $publicKey = base64_decode($publicKeyBase64, true);

        if ($bytes === false || $signature === false || $publicKey === false) {
            throw new InvalidUpdateSignature(__('updates.errors.signature_invalid'));
        }

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidUpdateSignature(__('updates.errors.public_key_invalid'));
        }

        if (! sodium_crypto_sign_verify_detached($signature, $bytes, $publicKey)) {
            throw new InvalidUpdateSignature(__('updates.errors.signature_invalid'));
        }
    }

    /** Run both checks; throws {@see InvalidUpdateSignature} on the first failure. */
    public function verify(string $zipPath, string $expectedSha256, ?string $signatureBase64): void
    {
        $this->verifyChecksum($zipPath, $expectedSha256);
        $this->verifySignature($zipPath, $signatureBase64);
    }

    /**
     * True only when the operator has explicitly opted out of signature
     * verification. Deliberately NOT keyed on APP_ENV — see the class
     * docblock; that was the bug.
     */
    public function unsignedAllowed(): bool
    {
        return config('pos.updater.allow_unsigned', false) === true;
    }
}
