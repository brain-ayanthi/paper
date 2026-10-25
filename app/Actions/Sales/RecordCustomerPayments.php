<?php

namespace App\Actions\Sales;

use App\Actions\Purchases\RecordSupplierPayment;
use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Store;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Multi-sale customer payment — the canonical "walk-in to settle the
 * tab" flow. Plural sibling of {@see RecordCustomerPayment} (single
 * sale); shape mirrors {@see RecordSupplierPayment}
 * so the mental model carries across.
 *
 * Input shape:
 *   $header = [
 *     'customer_id'       => int,
 *     'payment_method_id' => int,
 *     'payment_date'      => 'YYYY-MM-DD',
 *     'amount'            => decimal-string,        // total amount tendered
 *     'client_uuid'       => UUID|null,              // replay identity
 *     'reference'         => string|null,           // cheque #, UPI txn, etc.
 *     'notes'             => string|null,
 *   ]
 *   $allocations = [
 *     ['sale_id' => int, 'amount' => decimal-string],
 *     …
 *   ]
 *
 * Per allocation (atomic, single transaction with lockForUpdate on
 * the customer + each affected sale):
 *   1. Insert a `sale_payments` row tied to the sale. All rows share
 *      the request's `client_uuid` (or a generated fallback) so they're
 *      recognisable as one user action and a replay can return them.
 *   2. Increment `sales.paid_total` + decrement `sales.balance_due`.
 *   3. Apply the tender against the customer's receivable. Any amount
 *      above that receivable becomes auditable store credit instead of
 *      pushing `outstanding_balance` below zero.
 *
 * Guards:
 *   - A repeated client_uuid for the customer returns the original batch.
 *   - sum(allocations.amount) must be <= $header['amount']. Leftover
 *     (amount - sumAlloc) lands as ONE row with `sale_id = null` →
 *     customer store credit.
 *   - Each allocation's amount must be <= the sale's current balance_due.
 *   - Allocation's sale must belong to the same customer.
 *   - Sale must have a positive balance_due (already-settled / refunded
 *     sales are ineligible).
 *
 * Hooks:
 *   - filter `customer_payment.fillable`     → mutate header before insert
 *   - action `customer_payment.before_create`→ ($header, $allocations)
 *   - action `customer_payment.after_create` → (array of inserted rows, $customer)
 *
 * @param  array<string, mixed>  $header
 * @param  array<int, array<string, mixed>>  $allocations
 * @return array<int, SalePayment>
 */
class RecordCustomerPayments
{
    public function __invoke(array $header, array $allocations, ?User $user = null): array
    {
        $header = apply_filters('customer_payment.fillable', $header);

        return DB::transaction(function () use ($header, $allocations, $user) {
            $customer = Customer::query()
                ->whereKey((int) $header['customer_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $clientUuid = (string) ($header['client_uuid'] ?? '');
            if ($clientUuid !== '') {
                $existing = SalePayment::query()
                    ->where('customer_id', $customer->id)
                    ->where('client_uuid', $clientUuid)
                    ->orderBy('id')
                    ->get();

                if ($existing->isNotEmpty()) {
                    return $existing->all();
                }
            } else {
                $clientUuid = (string) Str::uuid();
            }

            $method = PaymentMethod::query()->find((int) ($header['payment_method_id'] ?? 0));
            if ($method?->requires_reference && trim((string) ($header['reference'] ?? '')) === '') {
                throw new RuntimeException(__('cashier.pay.reference_required', [
                    'method' => $method->name,
                ]));
            }

            // Check the replay identity before validating the mutable sales
            // again. A later retry returns the original result even if those
            // sales are now fully paid because the first request succeeded.
            $totalAmount = $this->fmt((string) ($header['amount'] ?? '0'));
            if (bccomp($totalAmount, '0', 4) <= 0) {
                throw new RuntimeException('Payment amount must be positive.');
            }

            $sumAlloc = array_reduce(
                $allocations,
                fn ($carry, $row) => bcadd($carry, $this->fmt((string) ($row['amount'] ?? '0')), 4),
                '0',
            );
            if (bccomp($sumAlloc, $totalAmount, 4) > 0) {
                throw new RuntimeException(
                    "Allocations ({$sumAlloc}) cannot exceed payment amount ({$totalAmount})."
                );
            }
            $unallocated = bcsub($totalAmount, $sumAlloc, 4);
            $hasCredit = bccomp($unallocated, '0', 4) > 0;

            // Run the public hook only for a genuinely new batch. The locked
            // customer row serialises same-customer replays, so even two
            // concurrent requests with the same UUID cannot both write.
            do_action('customer_payment.before_create', $header, $allocations);

            $inserted = [];
            $creditStore = null;

            foreach ($allocations as $alloc) {
                $sale = Sale::query()
                    ->whereKey((int) $alloc['sale_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $sale->customer_id !== (int) $customer->id) {
                    throw new RuntimeException("Sale {$sale->number} doesn't belong to this customer.");
                }
                // Voided/fully-refunded sales are not collectible even by a
                // hand-crafted request (code-review #64) — money must never
                // be taken against goods that came back.
                if (in_array($sale->status, [Sale::STATUS_VOIDED, Sale::STATUS_REFUNDED], true)) {
                    throw new RuntimeException("Sale {$sale->number} is {$sale->status} — not payable.");
                }
                if (bccomp((string) $sale->balance_due, '0', 4) <= 0) {
                    throw new RuntimeException("Sale {$sale->number} has no outstanding balance.");
                }

                $allocAmount = $this->fmt((string) $alloc['amount']);
                if (bccomp($allocAmount, '0', 4) <= 0) {
                    throw new RuntimeException("Allocation to {$sale->number} must be positive.");
                }
                if (bccomp($allocAmount, (string) $sale->balance_due, 4) > 0) {
                    throw new RuntimeException(
                        "Allocation {$allocAmount} exceeds {$sale->number}'s balance due of {$sale->balance_due}."
                    );
                }

                $creditStore ??= (int) $sale->store_id;

                $row = SalePayment::create([
                    'sale_id' => $sale->id,
                    'customer_id' => $customer->id,
                    'payment_method_id' => (int) $header['payment_method_id'],
                    'amount' => $allocAmount,
                    'reference' => $header['reference'] ?? null,
                    'currency_code' => (string) $sale->currency_code,
                    'client_uuid' => $clientUuid,
                    'notes' => $header['notes'] ?? null,
                    'paid_at' => $header['payment_date'] ?? now(),
                    'created_by' => $user?->id,
                ]);

                $newPaid = bcadd((string) $sale->paid_total, $allocAmount, 4);
                $newBalance = bcsub((string) $sale->balance_due, $allocAmount, 4);

                $sale->forceFill([
                    'paid_total' => $newPaid,
                    'balance_due' => $newBalance,
                ])->save();

                $inserted[] = $row;
            }

            // Keep the unallocated receipt row for the payment audit trail.
            // It becomes store credit exactly as the form previews; only
            // explicit sale allocations are allowed to clear A/R.
            $creditReference = null;
            if ($hasCredit) {
                $creditReference = SalePayment::create([
                    'sale_id' => null,
                    'customer_id' => $customer->id,
                    'payment_method_id' => (int) $header['payment_method_id'],
                    'amount' => $unallocated,
                    'reference' => $header['reference'] ?? null,
                    'currency_code' => null,
                    'client_uuid' => $clientUuid,
                    'notes' => $header['notes'] ?? null,
                    'paid_at' => $header['payment_date'] ?? now(),
                    'created_by' => $user?->id,
                ]);
                $inserted[] = $creditReference;
            }

            $openingOutstanding = (string) ($customer->outstanding_balance ?? '0');
            $positiveOutstanding = bccomp($openingOutstanding, '0', 4) > 0
                ? $openingOutstanding
                : '0.0000';

            $appliedToOutstanding = $sumAlloc;
            $creditAdded = $unallocated;
            $nextOutstanding = bcsub($positiveOutstanding, $appliedToOutstanding, 4);
            if (bccomp($nextOutstanding, '0', 4) < 0) {
                $nextOutstanding = '0.0000';
            }

            // Preserve historical negative outstanding until checkout
            // converts it into the dedicated credit ledger. New payments
            // never make the receivable negative.
            if (bccomp($openingOutstanding, '0', 4) < 0) {
                $nextOutstanding = $openingOutstanding;
            }

            $nextStoreCredit = (string) ($customer->store_credit_balance ?? '0');
            if (bccomp($creditAdded, '0', 4) > 0) {
                $nextStoreCredit = bcadd($nextStoreCredit, $creditAdded, 4);
            }

            $customer->forceFill([
                'outstanding_balance' => $nextOutstanding,
                'store_credit_balance' => $nextStoreCredit,
            ])->save();

            if (bccomp($creditAdded, '0', 4) > 0) {
                $reference = $creditReference ?? $inserted[0];
                CustomerCreditTransaction::create([
                    'customer_id' => $customer->id,
                    'store_id' => $creditStore
                        ?? (int) (current_store_id() ?: $customer->first_store_id ?: Store::query()->value('id')),
                    'type' => CustomerCreditTransaction::TYPE_CREDIT_ADD,
                    'amount' => $creditAdded,
                    'balance_after_amount' => $nextStoreCredit,
                    'reference_type' => SalePayment::class,
                    'reference_id' => $reference->id,
                    'notes' => $header['notes'] ?? null,
                    'created_by' => $user?->id,
                ]);
            }

            do_action(
                'customer_payment.after_create',
                $inserted,
                $customer->fresh(),
                $appliedToOutstanding,
                $creditAdded,
            );

            return $inserted;
        });
    }

    private function fmt(string $v): string
    {
        return Money::normalize($v);
    }
}
