<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay-outs get a reason CATEGORY (docs/features/cash-drawer-shifts.md §4.3):
 * `expense`, `bank_deposit`, `change_to_safe`, `other`.
 *
 * Until now a pay-out carried free text only, so "money left the till" could
 * never be reconciled against anything — the `expense` category is what lets a
 * pay-out create/link a real `expenses` row (the FK `expense_id` has existed
 * since the original drawer migration but nothing ever populated it).
 *
 * Nullable: pay-ins and drawer-open-no-sale rows have no category, and rows
 * recorded before this migration keep NULL rather than being back-labelled
 * with a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_drawer_entries', function (Blueprint $table) {
            $table->string('category', 32)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('cash_drawer_entries', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
