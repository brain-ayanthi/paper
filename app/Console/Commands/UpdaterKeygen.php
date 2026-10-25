<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generates the Ed25519 keypair used to sign release packages.
 *
 * VENDOR-SIDE, RUN ONCE. This is a build-time tool for whoever cuts releases —
 * it is never run on a customer install. The secret key signs release zips and
 * must never be committed, shipped, or placed in .env on any install; only the
 * public key travels with the product.
 *
 * `--write` patches the generated public key straight into config/pos.php,
 * which is where it has to live: config/pos.php is replaced by every update,
 * whereas a customer's .env is not — so a key added only to .env.example would
 * never reach the installs already out there.
 *
 * Rotating the key invalidates every previously published signature, so old
 * feed entries must be re-signed with {@see SignRelease} at the same time.
 */
class UpdaterKeygen extends Command
{
    protected $signature = 'pos:updater-keygen {--write : Write the public key into config/pos.php}';

    protected $description = 'Generate the Ed25519 keypair used to sign release packages (vendor-side, run once)';

    public function handle(): int
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->error('The Sodium PHP extension is not available, so a keypair cannot be generated.');

            return self::FAILURE;
        }

        $pair   = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));

        $this->newLine();
        $this->info('PUBLIC KEY — ships with the product, safe to commit:');
        $this->line($public);
        $this->newLine();
        $this->warn('SECRET KEY — store offline, NEVER commit or ship:');
        $this->line($secret);
        $this->newLine();

        if ($this->option('write')) {
            if ($this->writeToConfig($public)) {
                $this->info('Wrote the public key into config/pos.php.');
            } else {
                $this->error("Couldn't patch config/pos.php — paste the public key in manually.");
            }
        } else {
            $this->line('Next: run again with --write, or paste the public key into');
            $this->line("config/pos.php under 'updater' => 'public_key'.");
        }

        $this->newLine();
        $this->line('Then sign each release zip with:');
        $this->line('  POS_UPDATE_SECRET_KEY=<secret> php artisan pos:sign-release path/to/release.zip');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Replace the `public_key` default in config/pos.php. Deliberately narrow:
     * it matches only that one line so an unexpected file shape fails loudly
     * (returns false) rather than corrupting the config.
     */
    private function writeToConfig(string $publicKey): bool
    {
        $path     = config_path('pos.php');
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return false;
        }

        $pattern = "/('public_key'\s*=>\s*env\('POS_UPDATE_PUBLIC_KEY',\s*)'[^']*'(\))/";

        $patched = preg_replace($pattern, "$1'".$publicKey."'$2", $contents, 1, $count);

        if ($patched === null || $count !== 1) {
            return false;
        }

        return @file_put_contents($path, $patched) !== false;
    }
}
