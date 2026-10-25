<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier payment is made in a currency, and until now the row didn't say
 * which one.
 *
 * `purchases` has carried `currency_code` from day one, so the Record-payment
 * screen shows each PO's balance in (say) euros — but `purchase_payments` only
 * stored a bare amount, so the payments list and the payment detail page fell
 * back to the company currency. The same €729.29 read as ₹729.29 one screen
 * later.
 *
 * Backfill: an allocated row takes its purchase's currency; an unallocated
 * (credit) row takes the supplier's default, falling back to the company base
 * currency. Nullable rather than defaulted, so a row that genuinely predates
 * any currency information reads as "unknown" and renders in the base currency
 * exactly as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_payments', 'currency_code')) {
            Schema::table('purchase_payments', function (Blueprint $table) {
                $table->char('currency_code', 3)->nullable()->after('amount');
            });
        }

        // Allocated rows: the purchase they paid says which currency it was in.
        DB::table('purchase_payments')
            ->whereNull('currency_code')
            ->whereNotNull('purchase_id')
            ->update([
                'currency_code' => DB::raw(
                    '(SELECT p.currency_code FROM purchases p WHERE p.id = purchase_payments.purchase_id)'
                ),
            ]);

        // Credit rows have no purchase — fall back to the supplier's default.
        DB::table('purchase_payments')
            ->whereNull('currency_code')
            ->update([
                'currency_code' => DB::raw(
                    '(SELECT s.default_currency_code FROM suppliers s WHERE s.id = purchase_payments.supplier_id)'
                ),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('purchase_payments', 'currency_code')) {
            return;
        }

        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });
    }
};
