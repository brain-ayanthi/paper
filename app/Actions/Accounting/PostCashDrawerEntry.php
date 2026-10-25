<?php

namespace App\Actions\Accounting;

use App\Actions\Accounting\Concerns\GuardsAgainstDoublePosting;
use App\Models\Account;
use App\Models\CashDrawerEntry;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountMappingResolver;

/** Post standalone pay-ins/pay-outs without duplicating linked business JEs. */
class PostCashDrawerEntry
{
    use GuardsAgainstDoublePosting;

    public function __construct(
        private PostJournalEntry $post,
        private AccountMappingResolver $mappings,
    ) {}

    public function __invoke(CashDrawerEntry $entry): ?JournalEntry
    {
        if ($entry->type === CashDrawerEntry::TYPE_DRAWER_OPEN_NO_SALE
            || $entry->expense_id
            || $entry->supplier_payment_uuid
            || $this->alreadyPostedFor(JournalEntry::SOURCE_CASH_DRAWER, $entry)
        ) {
            return null;
        }

        $entry->loadMissing('shift');
        $storeId = (int) $entry->shift?->store_id;
        $amount = (string) $entry->amount;

        if ($storeId <= 0 || bccomp($amount, '0', 4) <= 0) {
            return null;
        }

        $cashId = $this->accountId('cash_drawer_cash', '1010', $storeId);
        $counterpartId = $this->counterpartId($entry, $storeId);

        $lines = $entry->type === CashDrawerEntry::TYPE_PAY_IN
            ? [
                $this->debit($cashId, $amount, __('accounting.lines.cash')),
                $this->credit($counterpartId, $amount, __('accounting.lines.cash_drawer_counterpart')),
            ]
            : [
                $this->debit($counterpartId, $amount, __('accounting.lines.cash_drawer_counterpart')),
                $this->credit($cashId, $amount, __('accounting.lines.cash')),
            ];

        return ($this->post)([
            'store_id' => $storeId,
            'entry_date' => $entry->created_at?->toDateString() ?? now()->toDateString(),
            'source' => JournalEntry::SOURCE_CASH_DRAWER,
            'reference_type' => CashDrawerEntry::class,
            'reference_id' => $entry->id,
            'description' => __('accounting.descriptions.cash_drawer', [
                'type' => __('cash_drawer.types.'.$entry->type),
                'reason' => $entry->reason,
            ]),
            'created_by' => $entry->created_by,
            'lines' => $lines,
        ]);
    }

    private function counterpartId(CashDrawerEntry $entry, int $storeId): int
    {
        if ($entry->type === CashDrawerEntry::TYPE_PAY_IN
            || in_array($entry->category, [
                CashDrawerEntry::CATEGORY_BANK_DEPOSIT,
                CashDrawerEntry::CATEGORY_CHANGE_TO_SAFE,
            ], true)
        ) {
            return $this->accountId('cash_drawer_transfer', '1020', $storeId);
        }

        return $this->accountId('cash_drawer_suspense', '7990', $storeId);
    }

    /** Existing installs fall back to shipped accounts until mappings are seeded. */
    private function accountId(string $key, string $fallbackCode, int $storeId): int
    {
        try {
            return $this->mappings->id($key, $storeId);
        } catch (\RuntimeException) {
            return (int) Account::query()->active()->where('code', $fallbackCode)->value('id');
        }
    }
}
