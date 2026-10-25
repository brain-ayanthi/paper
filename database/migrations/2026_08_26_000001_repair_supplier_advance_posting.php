<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfills the supplier-advance account/mapping for installations upgraded
 * from a chart seeded before that business event existed. It also upgrades
 * unresolved legacy supplier-payment failures from a Supplier reference to
 * their replayable PurchasePayment batch reference when the timestamp match
 * is unambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->repairSupplierAdvanceMapping();
        $this->repairLegacyFailureReferences();
    }

    public function down(): void
    {
        // Accounting master data and repaired audit references are preserved.
        // Removing either on rollback could invalidate entries posted since up().
    }

    private function repairSupplierAdvanceMapping(): void
    {
        if (! Schema::hasTable('accounts')
            || ! Schema::hasTable('account_groups')
            || ! Schema::hasTable('account_mappings')) {
            return;
        }

        $account = DB::table('accounts')->where('code', '1091')->first();

        if (! $account) {
            $currency = Schema::hasTable('company')
                ? DB::table('company')->value('base_currency_code')
                : null;
            $groupId = DB::table('account_groups')
                ->where('type', 'asset')
                ->whereIn('name', ['Other Current Assets', 'Current Assets', 'Assets'])
                ->orderByRaw("CASE name WHEN 'Other Current Assets' THEN 1 WHEN 'Current Assets' THEN 2 ELSE 3 END")
                ->value('id');

            if (! $currency || ! $groupId) {
                return;
            }

            $now = now();
            $accountId = DB::table('accounts')->insertGetId([
                'account_group_id' => $groupId,
                'code' => '1091',
                'name' => 'Advances to Suppliers',
                'type' => 'asset',
                'currency_code' => $currency,
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } elseif ($account->type === 'asset') {
            $accountId = (int) $account->id;
        } else {
            // Never silently repurpose a customer-created account that already
            // owns the reserved code but has an incompatible accounting type.
            return;
        }

        $exists = DB::table('account_mappings')
            ->where('key', 'advances_to_suppliers')
            ->whereNull('store_id')
            ->exists();

        if (! $exists) {
            DB::table('account_mappings')->insert([
                'key' => 'advances_to_suppliers',
                'account_id' => $accountId,
                'store_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function repairLegacyFailureReferences(): void
    {
        if (! Schema::hasTable('posting_failures') || ! Schema::hasTable('purchase_payments')) {
            return;
        }

        $poster = 'App\\Actions\\Accounting\\PostSupplierPaymentEntry';
        $supplierType = 'App\\Models\\Supplier';
        $paymentType = 'App\\Models\\PurchasePayment';

        $failures = DB::table('posting_failures')
            ->where('poster', $poster)
            ->where('reference_type', $supplierType)
            ->whereNull('resolved_at')
            ->get();

        foreach ($failures as $failure) {
            $failedAt = Carbon::parse($failure->created_at);
            $rows = DB::table('purchase_payments')
                ->where('supplier_id', $failure->reference_id)
                ->whereBetween('created_at', [
                    $failedAt->copy()->subSeconds(5),
                    $failedAt->copy()->addSeconds(5),
                ])
                ->orderBy('id')
                ->get(['id', 'client_uuid']);

            $batches = $rows->groupBy(fn ($row) => $row->client_uuid ?: 'row-'.$row->id);
            if ($batches->count() !== 1) {
                continue;
            }

            $referenceId = (int) $batches->first()->first()->id;

            DB::table('posting_failures')->where('id', $failure->id)->update([
                'reference_type' => $paymentType,
                'reference_id' => $referenceId,
                'updated_at' => now(),
            ]);
        }
    }
};
