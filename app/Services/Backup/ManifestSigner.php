<?php

namespace App\Services\Backup;

/**
 * Provenance for a backup archive — security register SEC #20 (defect #82).
 *
 * The restore replays `database/dump.sql` into the live database. An earlier
 * pass taught {@see \App\Services\Backup\DatabaseRestorer} to refuse statements
 * our own backups never emit (LOAD DATA, GRANT, CREATE TRIGGER…), which stops a
 * dump ESCAPING the just-data model. What that cannot stop is a structurally
 * legitimate dump carrying attacker-chosen ROWS — an extra super-admin, say.
 * Such a file is byte-for-byte indistinguishable from a genuine backup
 * containing the same row, and the manifest's own sha256 travels inside the
 * archive being checked, so it only ever proves the file is self-consistent.
 *
 * The missing ingredient is PROVENANCE: not "is this file intact" but "did WE
 * make it". This signs the manifest with a key the archive does not contain.
 *
 * Why HMAC over `APP_KEY` rather than the Ed25519 machinery the updater uses:
 *
 *  - The updater verifies packages WE publish to every customer, so it needs
 *    an asymmetric key whose public half ships in the product. A backup is
 *    verified only by the install that produced it, so a shared secret is the
 *    right shape and needs no key distribution, no key management UI, and no
 *    sodium extension on shared hosting.
 *  - `APP_KEY` is already the install's root secret. An attacker holding it
 *    can decrypt sessions and forge cookies; a forged backup is not the
 *    marginal risk at that point.
 *
 * Deliberately NOT a hard block on failure. Restoring an archive from another
 * install is a legitimate migration (new server, new APP_KEY), and refusing it
 * outright would make the product's own disaster-recovery story worse than the
 * bug. Instead {@see \App\Actions\Settings\RunRestore} requires the operator to
 * acknowledge an unverified archive explicitly, and records that they did.
 */
class ManifestSigner
{
    public const ALGORITHM = 'hmac-sha256';

    /** Provenance verdicts. */
    public const VERIFIED = 'verified';
    public const UNSIGNED = 'unsigned';
    public const INVALID  = 'invalid';

    /**
     * The signature for a manifest, over its canonical form.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function sign(array $manifest): string
    {
        return hash_hmac('sha256', $this->canonicalize($manifest), $this->key());
    }

    /**
     * Is this manifest ours, and unmodified since we wrote it?
     *
     * @param  array<string, mixed>  $manifest
     * @return self::VERIFIED|self::UNSIGNED|self::INVALID
     */
    public function verdict(array $manifest): string
    {
        $claimed = $manifest['signature'] ?? null;

        if (! is_string($claimed) || $claimed === '') {
            // Written by a build older than this feature, or by something
            // that is not us. Either way we cannot vouch for it.
            return self::UNSIGNED;
        }

        return hash_equals($this->sign($manifest), $claimed)
            ? self::VERIFIED
            : self::INVALID;
    }

    /**
     * The bytes actually signed.
     *
     * `signature` and `signature_alg` are removed first (a signature cannot
     * cover itself) and keys are sorted recursively, so the same manifest
     * always canonicalizes identically no matter what order json_decode
     * happened to produce. Slashes and unicode stay unescaped for the same
     * reason — one representation, not several.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function canonicalize(array $manifest): string
    {
        unset($manifest['signature'], $manifest['signature_alg']);

        $this->ksortRecursive($manifest);

        return (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<mixed> $array */
    private function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
        unset($value);

        ksort($array);
    }

    /**
     * The signing key. `APP_KEY` arrives base64-prefixed from the installer;
     * the raw string is fine as HMAC key material either way, but decoding it
     * keeps the key the same across a `config:cache` that might normalise the
     * prefix differently.
     */
    private function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }
}
