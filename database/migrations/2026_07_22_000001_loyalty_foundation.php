<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty points — foundation (docs/features/customers.md §9).
 *
 * Three small pieces:
 *   1. `company.loyalty_settings` — the whole program config as one JSON
 *      blob, mirroring `cashier_settings` / `scale_settings`. System-wide,
 *      NOT per-store: the spec says per-store, but every other localisation
 *      /program knob in this product is set once for the install and stores
 *      are locations of one company.
 *   2. `customer_groups.loyalty_multiplier` — the earn multiplier per tier
 *      (§12.1: Premium earns 2×). NULL means 1× so existing rows need no
 *      backfill.
 *   3. `customers.loyalty_manage` permission — manual point adjustments.
 *
 * The ledger itself needs no new table: `customer_credit_transactions`
 * already ships with `points`, `balance_after_points`, and `expires_at`.
 * We only add the index the ledger reads on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->json('loyalty_settings')->nullable()->after('scale_settings');
        });

        Schema::table('customer_groups', function (Blueprint $table) {
            // NULL = 1× (no multiplier). Kept nullable rather than
            // defaulting to 1 so "never configured" reads differently
            // from "deliberately set to 1×" in the group editor.
            $table->decimal('loyalty_multiplier', 7, 4)->nullable()->after('default_discount_percent');
        });

        Schema::table('customer_credit_transactions', function (Blueprint $table) {
            // The loyalty ledger tab and the reconciliation job both read
            // (customer, type) ordered by recency.
            $table->index(['customer_id', 'type'], 'cct_customer_type_idx');
        });

        $now = now();
        DB::table('permissions')->insertOrIgnore([
            'key'        => 'customers.loyalty_manage',
            'group'      => 'Customers',
            'label'      => 'Add or remove loyalty points manually',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Grant it to whoever already manages store credit — same trust
        // level, same person in practice.
        $newPermId    = (int) DB::table('permissions')->where('key', 'customers.loyalty_manage')->value('id');
        $creditPermId = (int) DB::table('permissions')->where('key', 'customers.credit_manage')->value('id');
        if (! $newPermId || ! $creditPermId) {
            return;
        }

        $roleIds = DB::table('role_permission')->where('permission_id', $creditPermId)->pluck('role_id');
        foreach ($roleIds as $roleId) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id'       => $roleId,
                'permission_id' => $newPermId,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'customers.loyalty_manage')->delete();

        Schema::table('customer_credit_transactions', function (Blueprint $table) {
            $table->dropIndex('cct_customer_type_idx');
        });

        Schema::table('customer_groups', function (Blueprint $table) {
            $table->dropColumn('loyalty_multiplier');
        });

        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn('loyalty_settings');
        });
    }
};
