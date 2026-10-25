<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Code-review #57 (Critical): a return against a credit sale must first
 * settle the sale's outstanding balance (Cr A/R) and only pay out the
 * remainder. This column records the slice of the refund that settled
 * `sales.balance_due`, so the journal poster can credit
 * accounts_receivable_customers by exactly that amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->decimal('refunded_against_balance', 15, 4)->default(0)
                ->after('refunded_to_original_method');
        });
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropColumn('refunded_against_balance');
        });
    }
};
