<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Signs a release zip and prints the feed entry for it.
 *
 * VENDOR-SIDE, RUN AT RELEASE TIME. Every release published to the update feed
 * must be signed, or customer installs will refuse it — {@see \App\Services\Updater\SignatureVerifier}
 * enforces the signature unconditionally on the feed path.
 *
 * The secret key comes from the POS_UPDATE_SECRET_KEY environment variable or
 * `--key`, never from config: a customer install must never hold the signing
 * key, so there is deliberately no config entry for it to be read from.
 */
class SignRelease extends Command
{
    protected $signature = 'pos:sign-release
                            {zip : Path to the release zip to sign}
                            {--key= : Base64 Ed25519 secret key (default: POS_UPDATE_SECRET_KEY env var)}';

    protected $description = 'Sign a release zip and print its update-feed entry (vendor-side)';

    public function handle(): int
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            $this->error('The Sodium PHP extension is not available, so the release cannot be signed.');

            return self::FAILURE;
        }

        $zip = (string) $this->argument('zip');
        if (! is_file($zip)) {
            $this->error("No such file: {$zip}");

            return self::FAILURE;
        }

        $secretBase64 = (string) ($this->option('key') ?: getenv('POS_UPDATE_SECRET_KEY'));
        if ($secretBase64 === '') {
            $this->error('No signing key. Pass --key=<base64> or set POS_UPDATE_SECRET_KEY.');

            return self::FAILURE;
        }

        $secret = base64_decode($secretBase64, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->error('The signing key is not a valid base64 Ed25519 secret key.');

            return self::FAILURE;
        }

        $bytes = @file_get_contents($zip);
        if ($bytes === false) {
            $this->error("Couldn't read {$zip}.");

            return self::FAILURE;
        }

        $signature = base64_encode(sodium_crypto_sign_detached($bytes, $secret));
        $sha256    = hash('sha256', $bytes);

        // Sanity-check against the public key the product actually ships with,
        // so a mismatched keypair is caught here rather than by every customer.
        $shipped = (string) config('pos.updater.public_key', '');
        if ($shipped !== '') {
            $publicKey = base64_decode($shipped, true);
            $valid     = $publicKey !== false
                && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && sodium_crypto_sign_verify_detached(base64_decode($signature, true), $bytes, $publicKey);

            if (! $valid) {
                $this->error('This signature does NOT verify against the public key in config/pos.php.');
                $this->error('The keypair does not match — customers would reject this release.');

                return self::FAILURE;
            }

            $this->info('Signature verifies against the public key shipped in config/pos.php.');
        } else {
            $this->warn('No public key is set in config/pos.php — run `pos:updater-keygen --write` first.');
        }

        $this->newLine();
        $this->line('Feed entry — merge into your pos.json release object:');
        $this->newLine();
        $this->line(json_encode([
            'version'   => (string) config('pos.version'),
            'sha256'    => $sha256,
            'signature' => $signature,
            'size_bytes' => strlen($bytes),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();

        return self::SUCCESS;
    }
}
