<?php

namespace App\Actions\Accounting;

use App\Models\CustomerCreditTransaction;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Services\Accounting\AccountMappingResolver;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Find and optionally repair historical store-credit accounting gaps. */
class RepairStoreCreditAccounting
{
    public function __construct(
        private PostCustomerCreditConversionEntry $postConversion,
        private PostJournalEntry $post,
        private AccountMappingResolver $mappings,
    ) {}

    /**
     * @return list<array{store:Store, missing_conversions:int, posted_conversions:int, legacy_balance:string, reclassification:?JournalEntry}>
     */
    public function __invoke(bool $fix = false, ?int $onlyStoreId = null): array
    {
        $stores = Store::query()
            ->when($onlyStoreId, fn ($query) => $query->whereKey($onlyStoreId))
            ->orderBy('id')
            ->get();

        $results = [];

        foreach ($stores as $store) {
            $conversions = $this->missingConversions((int) $store->id);
            $missingConversions = $conversions->count();
            $postedConversions = 0;

            if ($fix) {
                foreach ($conversions as $conversion) {
                    if (($this->postConversion)($conversion, repair: true)) {
                        $postedConversions++;
                    }
                }
            }

            $legacyId = $this->mappings->id('store_credit_liability', (int) $store->id);
            $canonicalId = $this->mappings->id('customer_advances', (int) $store->id);
            $legacyBalance = $legacyId === $canonicalId
                ? '0.0000'
                : $this->liabilityBalance((int) $store->id, $legacyId);
            $reclassification = null;

            if ($fix && bccomp($legacyBalance, '0', 4) !== 0) {
                $reclassification = $this->reclassify(
                    $store,
                    $legacyId,
                    $canonicalId,
                    $legacyBalance,
                );
            }

            $results[] = compact(
                'store',
                'missingConversions',
                'postedConversions',
                'legacyBalance',
                'reclassification',
            );
        }

        return $results;
    }

    /** @return Collection<int, CustomerCreditTransaction> */
    private function missingConversions(int $storeId)
    {
        return CustomerCreditTransaction::query()
            ->where('store_id', $storeId)
            ->where('type', CustomerCreditTransaction::TYPE_CREDIT_CONVERT)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('journal_entries')
                    ->where('source', JournalEntry::SOURCE_CUSTOMER_CREDIT_CONVERSION)
                    ->where('reference_type', CustomerCreditTransaction::class)
                    ->whereColumn('reference_id', 'customer_credit_transactions.id');
            })
            ->with('customer')
            ->orderBy('id')
            ->get();
    }

    /** Credit minus debit on a liability account. */
    private function liabilityBalance(int $storeId, int $accountId): string
    {
        $totals = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.store_id', $storeId)
            ->where('journal_entries.is_posted', true)
            ->where('journal_lines.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(journal_lines.credit), 0) AS credits')
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS debits')
            ->first();

        return Money::normalize(bcsub(
            (string) ($totals->credits ?? '0'),
            (string) ($totals->debits ?? '0'),
            4,
        ));
    }

    private function reclassify(Store $store, int $legacyId, int $canonicalId, string $balance): JournalEntry
    {
        $amount = bccomp($balance, '0', 4) > 0 ? $balance : bcmul($balance, '-1', 4);
        $lines = bccomp($balance, '0', 4) > 0
            ? [
                ['account_id' => $legacyId, 'debit' => $amount, 'credit' => 0, 'description' => __('accounting.lines.store_credit')],
                ['account_id' => $canonicalId, 'debit' => 0, 'credit' => $amount, 'description' => __('accounting.lines.customer_advance')],
            ]
            : [
                ['account_id' => $canonicalId, 'debit' => $amount, 'credit' => 0, 'description' => __('accounting.lines.customer_advance')],
                ['account_id' => $legacyId, 'debit' => 0, 'credit' => $amount, 'description' => __('accounting.lines.store_credit')],
            ];

        return ($this->post)([
            'store_id' => (int) $store->id,
            'entry_date' => now()->toDateString(),
            'source' => JournalEntry::SOURCE_STORE_CREDIT_RECONCILIATION,
            'reference_type' => Store::class,
            'reference_id' => $store->id,
            'description' => __('accounting.descriptions.store_credit_reconciliation', ['store' => $store->name]),
            'lines' => $lines,
        ]);
    }
}
