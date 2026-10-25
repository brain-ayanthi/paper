<?php

namespace App\Actions\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Services\Accounting\AccountMappingResolver;
use App\Services\Reports\AgedReceivablesQuery;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Compare the sale subledger with A/R and optionally post a control adjustment. */
class ReconcileAccountsReceivable
{
    public function __construct(
        private PostJournalEntry $post,
        private AccountMappingResolver $mappings,
        private AgedReceivablesQuery $agedReceivables,
    ) {}

    /**
     * @return list<array{store:Store, subledger:string, ledger:string, drift:string, entry:?JournalEntry}>
     */
    public function __invoke(bool $fix = false, ?int $onlyStoreId = null): array
    {
        $stores = Store::query()
            ->when($onlyStoreId, fn ($query) => $query->whereKey($onlyStoreId))
            ->orderBy('id')
            ->get();

        $results = [];

        foreach ($stores as $store) {
            $storeId = (int) $store->id;
            $receivableId = $this->mappings->id('accounts_receivable_customers', $storeId);
            $subledger = $this->subledger($storeId);
            $ledger = $this->ledger($storeId, $receivableId);
            $drift = bcsub($subledger, $ledger, 4);
            $entry = null;

            if ($fix && bccomp($drift, '0', 4) !== 0) {
                $entry = $this->postAdjustment($store, $receivableId, $drift);
            }

            $results[] = compact('store', 'subledger', 'ledger', 'drift', 'entry');
        }

        return $results;
    }

    private function subledger(int $storeId): string
    {
        $rows = ($this->agedReceivables)(storeId: $storeId);

        return Money::normalize($this->agedReceivables->summarise($rows)['total']);
    }

    private function ledger(int $storeId, int $receivableId): string
    {
        $totals = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.store_id', $storeId)
            ->where('journal_entries.is_posted', true)
            ->where('journal_lines.account_id', $receivableId)
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS debits')
            ->selectRaw('COALESCE(SUM(journal_lines.credit), 0) AS credits')
            ->first();

        return Money::normalize(bcsub(
            (string) ($totals->debits ?? '0'),
            (string) ($totals->credits ?? '0'),
            4,
        ));
    }

    private function postAdjustment(Store $store, int $receivableId, string $drift): JournalEntry
    {
        $amount = bccomp($drift, '0', 4) > 0 ? $drift : bcmul($drift, '-1', 4);
        $offsetId = $this->offsetId((int) $store->id);
        $lines = bccomp($drift, '0', 4) > 0
            ? [
                ['account_id' => $receivableId, 'debit' => $amount, 'credit' => 0, 'description' => __('accounting.lines.receivable')],
                ['account_id' => $offsetId, 'debit' => 0, 'credit' => $amount, 'description' => __('accounting.lines.reconciliation_offset')],
            ]
            : [
                ['account_id' => $offsetId, 'debit' => $amount, 'credit' => 0, 'description' => __('accounting.lines.reconciliation_offset')],
                ['account_id' => $receivableId, 'debit' => 0, 'credit' => $amount, 'description' => __('accounting.lines.receivable')],
            ];

        return ($this->post)([
            'store_id' => (int) $store->id,
            'entry_date' => now()->toDateString(),
            'source' => JournalEntry::SOURCE_AR_RECONCILIATION,
            'reference_type' => Store::class,
            'reference_id' => $store->id,
            'description' => __('accounting.descriptions.ar_reconciliation', ['store' => $store->name]),
            'lines' => $lines,
        ]);
    }

    private function offsetId(int $storeId): int
    {
        try {
            return $this->mappings->id('receivable_reconciliation_offset', $storeId);
        } catch (\RuntimeException) {
            return (int) Account::query()->active()->where('code', '3090')->value('id');
        }
    }
}
