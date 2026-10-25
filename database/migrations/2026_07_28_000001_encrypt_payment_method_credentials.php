<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt `payment_methods.provider_credentials` at rest — security register
 * SEC #61.
 *
 * Every live gateway secret (Stripe `sk_live`, Razorpay `key_secret`, Paystack
 * and Flutterwave `secret_key`, MercadoPago `access_token`) plus each
 * `webhook_secret` was stored as readable JSON, so a leaked database backup on
 * shared hosting handed over the merchant's payment account.
 *
 * The model now casts this column through {@see App\Casts\SafeEncryptedArray}.
 * This migration converts the rows already on disk.
 *
 * Written to be safe to re-run: a value that already decrypts is left alone,
 * so a partially-applied migration (interrupted on shared hosting, a common
 * failure mode) can simply be run again. It also touches the column directly
 * via the query builder rather than through the model, so it neither trips the
 * cast nor fires model events.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The column ships as JSON, and MySQL 8 REJECTS anything that is not
        // valid JSON — ciphertext is base64, so it must become TEXT first or
        // every write fails with "Invalid JSON text" (error 3140). SQLite does
        // not enforce this, so the tests would happily pass while every MySQL
        // install broke; hence the explicit type change rather than trusting
        // the test run.
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->text('provider_credentials')->nullable()->change();
        });

        DB::table('payment_methods')
            ->select('id', 'provider_credentials')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $raw = $row->provider_credentials;

                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    // Already encrypted? Leave it. Makes the migration
                    // idempotent and safe after a partial run.
                    try {
                        Crypt::decryptString($raw);

                        continue;
                    } catch (\Throwable $e) {
                        // Not encrypted yet — fall through and encrypt it.
                    }

                    $decoded = json_decode($raw, true);

                    if (! is_array($decoded)) {
                        // Neither ciphertext nor valid JSON. Don't guess at it;
                        // leave the row untouched and say so, rather than
                        // destroying whatever is in there.
                        Log::warning('Skipped un-parseable provider_credentials during encryption migration.', [
                            'payment_method_id' => $row->id,
                        ]);

                        continue;
                    }

                    DB::table('payment_methods')
                        ->where('id', $row->id)
                        ->update([
                            'provider_credentials' => Crypt::encryptString(
                                json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            ),
                        ]);
                }
            });
    }

    /**
     * Decrypt back to plaintext JSON so the column is readable by the previous
     * release's plain `array` cast — a rollback must not leave the app unable
     * to read its own gateway config.
     */
    public function down(): void
    {
        DB::table('payment_methods')
            ->select('id', 'provider_credentials')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $raw = $row->provider_credentials;

                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    try {
                        $plain = Crypt::decryptString($raw);
                    } catch (\Throwable $e) {
                        // Already plaintext (or undecryptable with this key) —
                        // nothing useful to do either way.
                        continue;
                    }

                    DB::table('payment_methods')
                        ->where('id', $row->id)
                        ->update(['provider_credentials' => $plain]);
                }
            });

        // Back to JSON only after every row holds valid JSON again, or MySQL
        // refuses the type change.
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->json('provider_credentials')->nullable()->change();
        });
    }
};
