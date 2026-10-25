<?php

namespace App\Actions\Accounting;

use App\Actions\Accounting\Concerns\GuardsAgainstDoublePosting;
use App\Models\CustomerCreditTransaction;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountMappingResolver;

/** Reclassify a legacy negative customer receivable into store credit. */
class PostCustomerCreditConversionEntry
{
    use GuardsAgainstDoublePosting;

    public function __construct(
        private PostJournalEntry $post,
        private AccountMappingResolver $mappings,
    ) {}

    public function __invoke(CustomerCreditTransaction $conversion, bool $repair = false): ?JournalEntry
    {
        if ($conversion->type !== CustomerCreditTransaction::TYPE_CREDIT_CONVERT
            || $this->alreadyPostedFor(JournalEntry::SOURCE_CUSTOMER_CREDIT_CONVERSION, $conversion)
        ) {
            return null;
        }

        $storeId = (int) $conversion->store_id;
        $amount = (string) $conversion->amount;

        if ($storeId <= 0 || bccomp($amount, '0', 4) <= 0) {
            return null;
        }

        return ($this->post)([
            // Historical repairs belong to the current open period. Live
            // conversions retain their actual transaction date.
            'entry_date' => $repair
                ? now()->toDateString()
                : ($conversion->created_at?->toDateString() ?? now()->toDateString()),
            'store_id' => $storeId,
            'source' => JournalEntry::SOURCE_CUSTOMER_CREDIT_CONVERSION,
            'reference_type' => CustomerCreditTransaction::class,
            'reference_id' => $conversion->id,
            'description' => __('accounting.descriptions.customer_credit_conversion', [
                'customer' => $conversion->customer?->name ?? $conversion->customer_id,
            ]),
            'created_by' => $conversion->created_by,
            'lines' => [
                $this->debit(
                    $this->mappings->id('accounts_receivable_customers', $storeId),
                    $amount,
                    __('accounting.lines.receivable'),
                ),
                $this->credit(
                    $this->mappings->id('customer_advances', $storeId),
                    $amount,
                    __('accounting.lines.customer_advance'),
                ),
            ],
        ]);
    }
}
