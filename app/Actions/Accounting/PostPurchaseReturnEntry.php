<?php

namespace App\Actions\Accounting;

use App\Models\JournalEntry;
use App\Models\PurchaseReturn;
use App\Services\Accounting\AccountMappingResolver;

/**
 * Posts the journal entry for a purchase return — defect #101.
 *
 * `docs/features/accounting.md` §5 lists `purchase_return` as a posting source
 * and `JournalEntry::SOURCE_PURCHASE_RETURN` has existed since the schema was
 * written, but no poster was ever built and nothing listened to
 * `purchase.after_return`. So sending goods back to a supplier moved the stock
 * and the supplier balance while the books carried on as though the purchase
 * were untouched: inventory overstated, input tax overstated, and accounts
 * payable overstated by the value of every return ever made.
 *
 * The exact mirror of {@see PostPurchaseEntry}:
 *
 *   Dr  A/P — Suppliers   grand_total
 *     Cr  Inventory        grand_total − tax_amount   (goods + charges going back)
 *     Cr  Tax Input        tax_amount
 *
 * Balances by construction. Dated the return, not today, because a return is
 * an event with its own date on the document — unlike a receipt, where "today"
 * IS when the goods landed.
 *
 * Idempotent per return: the listener is best-effort and the recovery command
 * replays it, so posting twice must be impossible.
 */
class PostPurchaseReturnEntry
{
    public function __construct(
        private PostJournalEntry $post,
        private AccountMappingResolver $mappings,
    ) {}

    public function __invoke(PurchaseReturn $return): ?JournalEntry
    {
        if ($this->alreadyPosted($return)) {
            return null;
        }

        // A draft return has moved nothing — no stock, no supplier balance —
        // so there is nothing to post. Only a posted one reaches the books.
        if (($return->status ?? null) === 'draft') {
            return null;
        }

        $return->loadMissing('purchase');
        $rate      = (string) ($return->purchase?->exchange_rate_to_base ?: '1');
        $storeId   = (int) $return->store_id;
        $grand     = $this->base((string) $return->grand_total, $rate);
        $tax       = $this->base((string) $return->tax_amount, $rate);
        $inventory = bcsub($grand, $tax, 4);

        if (bccomp($grand, '0', 4) <= 0) {
            return null;
        }

        $lines = [
            [
                'account_id'  => $this->mappings->id('accounts_payable_suppliers', $storeId),
                'debit'       => $grand,
                'credit'      => 0,
                'description' => __('accounting.lines.payable'),
            ],
        ];

        if (bccomp($inventory, '0', 4) > 0) {
            $lines[] = [
                'account_id'  => $this->mappings->id('inventory', $storeId),
                'debit'       => 0,
                'credit'      => $inventory,
                'description' => __('accounting.lines.inventory'),
            ];
        }
        if (bccomp($tax, '0', 4) > 0) {
            $lines[] = [
                'account_id'  => $this->mappings->id('tax_input', $storeId),
                'debit'       => 0,
                'credit'      => $tax,
                'description' => __('accounting.lines.tax_input'),
            ];
        }

        if (count($lines) < 2) {
            return null;
        }

        return ($this->post)([
            'store_id'       => $storeId,
            'entry_date'     => $return->return_date instanceof \DateTimeInterface
                ? $return->return_date->format('Y-m-d')
                : (string) $return->return_date,
            'source'         => JournalEntry::SOURCE_PURCHASE_RETURN,
            'reference_type' => PurchaseReturn::class,
            'reference_id'   => $return->id,
            'description'    => __('accounting.descriptions.purchase_return', [
                'number' => $return->number ?? ('#'.$return->id),
            ]),
            'created_by'     => $return->updated_by ?? $return->created_by,
            'lines'          => $lines,
        ]);
    }

    private function alreadyPosted(PurchaseReturn $return): bool
    {
        return JournalEntry::where('source', JournalEntry::SOURCE_PURCHASE_RETURN)
            ->where('reference_type', PurchaseReturn::class)
            ->where('reference_id', $return->id)
            ->exists();
    }

    private function base(string $amount, string $rate): string
    {
        return \App\Support\Money::round(bcmul($amount, $rate, 8));
    }
}
