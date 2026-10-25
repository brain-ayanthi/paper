<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty redemption at the till (docs/features/customers.md §9.3).
 *
 * The redeemed value is applied as part of the sale's discount — it is
 * already inside `sales.discount_total` — but we snapshot the redemption
 * separately so we can:
 *   - print "Points redeemed: 500 (−₹50.00)" on the receipt,
 *   - exclude it from discount governance reporting (it isn't a cashier
 *     discount),
 *   - give the points back when the sale is voided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points_redeemed')->default(0)->after('discount_approved_by');
            $table->decimal('loyalty_value_redeemed', 15, 4)->default(0)->after('loyalty_points_redeemed');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['loyalty_points_redeemed', 'loyalty_value_redeemed']);
        });
    }
};
