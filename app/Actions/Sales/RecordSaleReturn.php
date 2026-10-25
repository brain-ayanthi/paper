<?php

namespace App\Actions\Sales;

use App\Actions\Customers\ReverseLoyaltyPoints;
use App\Actions\Inventory\RecordStockMovement;
use App\Models\ChannelOrderAllocation;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Support\NumberFormat;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Process a refund / return against an existing completed sale.
 *
 * The action:
 *   - validates each refund line against the parent sale_item's
 *     remaining returnable quantity (`quantity - quantity_returned`)
 *   - allocates the line's tax proportionally against the refund qty
 *   - writes a `sale_returns` header + `sale_return_items` lines in a
 *     single transaction
 *   - bumps the parent sale's `quantity_returned` per line and flips
 *     status to `partially_refunded` / `refunded` based on what's left
 *   - reverses stock movements via {@see RecordStockMovement}
 *     (positive delta, type='return') for any line where restock is
 *     true (per-line override falls back to the header flag)
 *   - claws back the refunded share of any loyalty points the sale
 *     earned, via {@see ReverseLoyaltyPoints}
 *   - is idempotent by `client_uuid` — re-submitting the same UUID
 *     returns the previously-created refund instead of double-refunding
 *
 * The refund_method_id determines which bucket the money lands in:
 *   `payment_methods.type === 'cash'`   → `refunded_in_cash`
 *   `payment_methods.code  === 'store_credit'` → `refunded_to_store_credit`
 *   anything else                       → `refunded_to_original_method`
 *
 * Numbering: pulls the admin-configured format from
 * `company.refund_number_format` (default `REFUND-{store}-{Ym}-{seq:04}`).
 *
 * @phpstan-type RefundInput array{
 *     sale_id:int,
 *     reason_code_id:int,
 *     refund_method_id:?int,
 *     restock:bool,
 *     additional_refund_amount?:numeric-string,
 *     return_date?:string,
 *     notes:?string,
 *     client_uuid:?string,
 *     items:array<int, array{
 *         sale_item_id:int,
 *         quantity:numeric-string,
 *         restock:?bool,
 *         notes:?string
 *     }>
 * }
 */
class RecordSaleReturn
{
    public function __construct(
        private readonly RecordStockMovement $recordMovement,
        private readonly ReverseGatewayCharge $reverseGateway,
        private readonly ReverseLoyaltyPoints $reverseLoyalty,
    ) {}

    public function __invoke(array $input, ?User $cashier): SaleReturn
    {
        if (! $cashier) {
            throw new RuntimeException('Cashier user is required.');
        }
        $additionalRefund = bcadd((string) ($input['additional_refund_amount'] ?? '0'), '0', 4);
        if (bccomp($additionalRefund, '0', 4) < 0) {
            throw new RuntimeException(__('sales.errors.refund_additional_negative'));
        }
        if (empty($input['items'] ?? []) && bccomp($additionalRefund, '0', 4) <= 0) {
            throw new RuntimeException(__('sales.errors.refund_empty'));
        }

        // Idempotency — re-submitting the same UUID returns the prior
        // refund instead of double-refunding. Mirrors CompleteSale's
        // local_uuid short-circuit.
        if (! empty($input['client_uuid'])) {
            $existing = SaleReturn::query()->where('client_uuid', $input['client_uuid'])->first();
            if ($existing) {
                return $existing->load('items');
            }
        }

        $sale = Sale::query()
            ->where('id', (int) $input['sale_id'])
            ->where('status', '!=', Sale::STATUS_VOIDED)
            ->firstOrFail();

        if ($sale->status === Sale::STATUS_REFUNDED
            && (! empty($input['items'] ?? []) || bccomp($additionalRefund, '0', 4) <= 0)) {
            throw new RuntimeException(__('sales.errors.already_refunded'));
        }

        $saleReturn = DB::transaction(function () use ($input, $sale, $cashier, $additionalRefund) {
            $store = Store::query()->where('is_active', true)->findOrFail($sale->store_id);

            // Re-fetch the sale under lock: the credit-sale settlement below
            // reads and writes balance_due, and two concurrent refunds (or a
            // refund racing a customer payment) must serialize on it
            // (code-review #57).
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            // Pre-load the lines we're refunding, locked, so two
            // simultaneous refunds can't both pass the
            // remaining-quantity check.
            $itemIds = array_map(fn ($l) => (int) $l['sale_item_id'], $input['items']);
            $items = SaleItem::query()
                ->whereIn('id', $itemIds)
                ->where('sale_id', $sale->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // A provider may refund a separately charged amount such as
            // shipping without a synthetic product line. Treat that slice as
            // contra-revenue while item quantities continue to drive stock.
            $subtotal = $additionalRefund;
            $taxTotal = '0';
            $grand = $additionalRefund;
            $rows = [];
            // Cumulative requested qty per line THIS request (code-review
            // #58): two rows naming the same sale_item_id each passed the
            // remaining-qty check against the same un-updated baseline
            // (quantity_returned only increments in the apply loop), so a
            // crafted request refunded 12 off a qty-10 line. The Form
            // Request now has `distinct` too; this guards every other
            // caller of the action.
            $requestedByItem = [];

            foreach ($input['items'] as $line) {
                $itemId = (int) $line['sale_item_id'];
                $item = $items->get($itemId);
                if (! $item) {
                    throw new RuntimeException("Line {$itemId} does not belong to this sale.");
                }

                $qty = (string) $line['quantity'];
                $remaining = bcsub(
                    bcsub((string) $item->quantity, (string) $item->quantity_returned, 4),
                    $requestedByItem[$itemId] ?? '0',
                    4,
                );
                if (bccomp($qty, '0', 4) <= 0) {
                    throw new RuntimeException(__('sales.errors.refund_qty_zero', ['name' => $item->product_name_snapshot]));
                }
                if (bccomp($qty, $remaining, 4) > 0) {
                    throw new RuntimeException(__('sales.errors.refund_qty_exceeds', [
                        'name' => $item->product_name_snapshot,
                        'remaining' => rtrim(rtrim($remaining, '0'), '.'),
                        'requested' => rtrim(rtrim($qty, '0'), '.'),
                    ]));
                }

                // Proportional allocation — split the original line's
                // stored amounts by qty so partial refunds give back the
                // right slice.
                //
                // The net comes from the STORED `line_subtotal`, not
                // qty × unit_price (code-review #53, Critical): on a
                // tax-INCLUSIVE sale unit_price is the tax-embedded gross,
                // so qty×unit_price + tax refunded the tax TWICE — a
                // direct cash leak on every MRP/GST-style refund.
                // `line_subtotal` is the ex-tax, discount-net amount in
                // BOTH pricing modes (see CompleteSale step 5), so no
                // separate discount subtraction either — the old one would
                // now double-count it.
                $ratio = bcdiv($qty, (string) $item->quantity, 8);
                $lineTax = bcmul((string) $item->tax_amount, $ratio, 4);
                $lineSubtotal = bcmul((string) $item->line_subtotal, $ratio, 4);
                $lineTotal = bcadd($lineSubtotal, $lineTax, 4);

                $subtotal = bcadd($subtotal, $lineSubtotal, 4);
                $taxTotal = bcadd($taxTotal, $lineTax, 4);
                $grand = bcadd($grand, $lineTotal, 4);

                $rows[] = [
                    'item' => $item,
                    'qty' => $qty,
                    'unit_price' => (string) $item->unit_price,
                    'tax_amount' => $lineTax,
                    'line_total' => $lineTotal,
                    // null = "inherit the header flag"; true/false = explicit override.
                    // (bool) null would silently kill restocking on lines the
                    // caller didn't override — preserve null when ambiguous.
                    'restock' => array_key_exists('restock', $line) && $line['restock'] !== null
                        ? (bool) $line['restock']
                        : null,
                    'notes' => $line['notes'] ?? null,
                ];

                $requestedByItem[$itemId] = bcadd($requestedByItem[$itemId] ?? '0', $qty, 4);
            }

            // Header row.
            $return = new SaleReturn;
            $return->forceFill([
                'store_id' => $store->id,
                'sale_id' => $sale->id,
                'client_uuid' => $input['client_uuid'] ?? null,
                'original_currency_code' => $sale->currency_code,
                'exchange_rate_to_active' => '1',
                'number' => $this->nextRefundNumber($store),
                'return_date' => $input['return_date'] ?? now()->toDateString(),
                'cashier_id' => $cashier->id,
                // Bind the cashier's open shift (code-review #63): without
                // this every refund had shift_id NULL, ComputeShiftTotals
                // found zero refunds, and expected cash was inflated by
                // every cash refund — a phantom shortage at every close.
                // Mirrors CompleteSale's auto-bind; NULL when no shift is
                // open (back-office refunds), same as shift-less sales.
                'shift_id' => Shift::openForCashier((int) $store->id, (int) $cashier->id)?->id,
                'reason_code_id' => (int) $input['reason_code_id'],
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grand,
                'refund_method_id' => $input['refund_method_id'] ?? null,
                'restock' => (bool) ($input['restock'] ?? true),
                'notes' => $input['notes'] ?? null,
                'status' => SaleReturn::STATUS_COMPLETED,
                'created_by' => $cashier->id,
            ])->save();

            // Credit-sale settlement FIRST (code-review #57, Critical): the
            // original sale debited A/R for its unpaid balance, so a return
            // must settle that balance before any money leaves the till.
            // Without this, balance_due/outstanding stayed put, A/R stayed
            // overstated, and the store paid out CASH for goods it was
            // never paid for — a double loss on every credit-sale return.
            $settledAgainstBalance = '0.0000';
            if (bccomp((string) $sale->balance_due, '0', 4) > 0) {
                $settledAgainstBalance = bccomp($grand, (string) $sale->balance_due, 4) > 0
                    ? (string) $sale->balance_due
                    : $grand;
            }
            $payout = bcsub($grand, $settledAgainstBalance, 4);
            if (bccomp($settledAgainstBalance, '0', 4) > 0) {
                $return->forceFill(['refunded_against_balance' => $settledAgainstBalance])->save();
            }

            // Bucketing: cash / store-credit / original-method — only the
            // slice NOT settled against the balance is actually paid out.
            // The refund_method drives which column carries the amount so
            // the cash drawer reconciliation can pick out cash refunds
            // and the customer-credit ledger can pick out credit ones.
            if (bccomp($payout, '0', 4) > 0) {
                $channelManagedPayment = ChannelOrderAllocation::query()
                    ->where('sale_id', $sale->getKey())
                    ->exists();
                if ($channelManagedPayment) {
                    $return->forceFill(['refunded_to_original_method' => $payout])->save();
                } elseif ($return->refund_method_id) {
                    $method = PaymentMethod::query()->find($return->refund_method_id);
                    if ($method?->type === 'cash') {
                        $return->forceFill(['refunded_in_cash' => $payout])->save();
                    } elseif ($method?->code === 'store_credit') {
                        $return->forceFill(['refunded_to_store_credit' => $payout])->save();
                    } else {
                        $return->forceFill(['refunded_to_original_method' => $payout])->save();
                    }
                } else {
                    $return->forceFill(['refunded_in_cash' => $payout])->save();
                }
            }

            // Line rows + parent-line update + restock movement.
            foreach ($rows as $r) {
                /** @var SaleItem $item */
                $item = $r['item'];

                SaleReturnItem::query()->forceCreate([
                    'sale_return_id' => $return->id,
                    'sale_item_id' => $item->id,
                    'quantity' => $r['qty'],
                    'unit_price_snapshot' => $r['unit_price'],
                    'tax_amount' => $r['tax_amount'],
                    'line_total' => $r['line_total'],
                    'restock' => $r['restock'],
                    'notes' => $r['notes'],
                ]);

                $item->forceFill([
                    'quantity_returned' => bcadd((string) $item->quantity_returned, $r['qty'], 4),
                ])->save();

                // Restock honours per-line override → header flag fallback.
                $restock = $r['restock'] ?? (bool) ($input['restock'] ?? true);
                if ($restock && $item->product_id) {
                    ($this->recordMovement)(
                        storeId: $store->id,
                        productId: (int) $item->product_id,
                        variantId: $item->variant_id ? (int) $item->variant_id : null,
                        batchId: $item->batch_id ? (int) $item->batch_id : null,
                        quantityDelta: $r['qty'],
                        type: 'return',
                        referenceType: SaleReturn::class,
                        referenceId: $return->id,
                        notes: "Refund {$return->number}",
                        createdBy: $cashier->id,
                    );
                }
            }

            // Update parent sale status based on remaining returnable
            // qty across all lines. Fully refunded when no line has
            // any remaining qty; partial otherwise.
            $sale->load('items');
            $anyRemaining = $sale->items->contains(
                fn (SaleItem $i) => bccomp((string) $i->quantity, (string) $i->quantity_returned, 4) > 0
            );
            $saleUpdate = [
                'status' => $anyRemaining ? Sale::STATUS_PARTIALLY_REFUNDED : Sale::STATUS_REFUNDED,
            ];
            // The settled slice reduces what the customer still owes on
            // this sale AND their overall outstanding (code-review #57).
            if (bccomp($settledAgainstBalance, '0', 4) > 0) {
                $saleUpdate['balance_due'] = bcsub((string) $sale->balance_due, $settledAgainstBalance, 4);

                if ($sale->customer_id) {
                    $customer = Customer::query()
                        ->whereKey($sale->customer_id)
                        ->lockForUpdate()
                        ->first();
                    $customer?->forceFill([
                        'outstanding_balance' => bcsub(
                            (string) ($customer->outstanding_balance ?? '0'),
                            $settledAgainstBalance,
                            4,
                        ),
                    ])->save();
                }
            }
            $sale->forceFill($saleUpdate)->save();

            // Claw back the matching slice of loyalty points. A full
            // refund takes back everything the sale earned; a partial one
            // takes back the refunded share of it. Without this, refunds
            // are a points farm.
            $saleGrand = (string) $sale->grand_total;
            $portion = bccomp($saleGrand, '0', 4) > 0
                ? bcdiv($grand, $saleGrand, 8)
                : '1';
            ($this->reverseLoyalty)($sale, $cashier, $portion);

            return $return->load('items');
        });

        // Reverse the charge through the original gateway (Stripe, Razorpay,
        // …) for original-method refunds. Runs after commit — never inside
        // the transaction — and never throws: a gateway failure is recorded
        // on the return for retry, the refund itself stands.
        ($this->reverseGateway)($saleReturn);

        // Fired after commit so listeners (e.g. the admin bell) see a
        // persisted return. A failing listener can't roll back the refund.
        do_action('sale.after_return', $saleReturn);

        return $saleReturn;
    }

    /**
     * Next refund number — pulls the admin-configured format from
     * `company.refund_number_format` (default `REFUND-{store}-{Ym}-{seq:04}`).
     */
    private function nextRefundNumber(Store $store): string
    {
        $when = now();
        $format = (Company::current() ?? new Company)->numberFormat('refund');
        $prefix = NumberFormat::prefix($format, $store, $when);

        $latest = SaleReturn::withTrashed()
            ->where('store_id', $store->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $seq = $latest ? NumberFormat::extractSeq($latest) + 1 : 1;

        return NumberFormat::render($format, $store, $when, $seq);
    }
}
