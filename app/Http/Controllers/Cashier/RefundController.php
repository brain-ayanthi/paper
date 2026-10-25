<?php

namespace App\Http\Controllers\Cashier;

use App\Actions\Sales\RecordSaleReturn;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RefundSaleRequest;
use App\Models\ChannelOrderAllocation;
use App\Models\PaymentMethod;
use App\Models\ReturnReason;
use App\Models\Sale;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Cashier-surface refund flow — three thin JSON endpoints that drive
 * the cashier's refund modal. The business logic (qty caps, tax
 * proration, stock reversal, status flips, numbering) all stays in
 * {@see RecordSaleReturn} — these methods just shape the request +
 * response for the cashier-facing UI.
 *
 *   GET  /cashier/refund/lookup?q=…
 *       Search by sale number / receipt; falls back to the 20 most
 *       recent completed/partially-refunded sales in the active store
 *       when the query is empty (so the cashier always sees something
 *       on first open).
 *
 *   GET  /cashier/refund/{sale}
 *       Returns the sale + items + remaining returnable qty + reason
 *       + method picklists. Used to populate the refund modal once a
 *       sale has been picked from the lookup.
 *
 *   POST /cashier/refund/{sale}
 *       Records the refund via {@see RecordSaleReturn}.
 */
class RefundController extends Controller
{
    use RespondsJsonOrRedirect;

    public function lookup(Request $request): JsonResponse
    {
        // `refund` policy is per-Sale instance; for the lookup endpoint
        // (which doesn't have a sale yet) we gate on the underlying
        // permission directly.
        abort_unless($request->user()?->hasPermission('sales.refund'), 403);

        $q = trim((string) $request->query('q', ''));
        $storeId = $this->activeStoreId($request);

        $query = Sale::query()
            ->where('store_id', $storeId)
            ->whereIn('status', [Sale::STATUS_COMPLETED, Sale::STATUS_PARTIALLY_REFUNDED])
            ->whereNotIn('id', ChannelOrderAllocation::query()->whereNotNull('sale_id')->select('sale_id'))
            ->orderByDesc('sale_datetime')
            ->limit(20);

        if ($q !== '') {
            // Match against sale number OR customer name. Sale number
            // matches loosely (so a cashier can type the tail of a
            // receipt — e.g. "0042").
            $query->where(function ($qb) use ($q) {
                $qb->where('number', 'like', '%'.$q.'%')
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$q.'%'));
            });
        }

        $rows = $query->with('customer:id,name')->get()->map(fn (Sale $s) => [
            'id' => $s->id,
            'number' => $s->number,
            'sale_datetime' => optional($s->sale_datetime)->toIso8601String(),
            'grand_total' => (string) $s->grand_total,
            'status' => $s->status,
            'customer' => $s->customer?->name,
        ])->values();

        // Return the array directly to match the cashier customer-search
        // contract (the Alpine factory does `Array.isArray(data) ? data : []`).
        return response()->json($rows);
    }

    public function show(Sale $sale): JsonResponse
    {
        $this->authorize('refund', $sale);

        if (ChannelOrderAllocation::query()->where('sale_id', $sale->getKey())->exists()) {
            return response()->json([
                'message' => __('sales.channel_managed'),
                'errors' => ['_action' => [__('sales.channel_managed')]],
            ], 422);
        }

        if (! \in_array($sale->status, [Sale::STATUS_COMPLETED, Sale::STATUS_PARTIALLY_REFUNDED], true)) {
            return response()->json([
                'message' => __('sales.refund.not_refundable'),
                'errors' => ['_action' => [__('sales.refund.not_refundable')]],
            ], 422);
        }

        $sale->load([
            'store:id,name,code',
            'customer:id,name,phone',
            'items.product:id,sku,name',
            'items.variant:id,sku',
            'payments.paymentMethod:id,code,name,type',
        ]);

        $items = $sale->items->map(function ($i) {
            $remaining = bcsub((string) $i->quantity, (string) $i->quantity_returned, 4);

            return [
                'id' => $i->id,
                'name' => $i->product_name_snapshot ?: $i->product?->name,
                'sku' => $i->sku_snapshot ?: $i->product?->sku,
                'variant' => $i->variant?->sku,
                'unit' => $i->unit ?: 'pc',
                'quantity' => (string) $i->quantity,
                'returned' => (string) $i->quantity_returned,
                'remaining' => $remaining,
                'unit_price' => (string) $i->unit_price,
                'line_subtotal' => (string) $i->line_subtotal,
                'tax_amount' => (string) $i->tax_amount,
                'discount_amount' => (string) ($i->discount_amount ?? '0'),
            ];
        })->values();

        // Refund tenders are limited to the methods actually used on the
        // sale (can't refund a card/gateway charge via a different method).
        // Unpaid credit sales have none → fall back to the full active list.
        $methods = $sale->refundMethods();
        if ($methods->isEmpty()) {
            $methods = PaymentMethod::query()->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name', 'type']);
        }

        // Default refund method follows the original tender, falling
        // back to cash. One-click full refund needs no edits.
        $originalMethodId = $sale->payments->first()?->payment_method_id;
        $cashMethodId = PaymentMethod::query()->where('type', 'cash')->where('is_active', true)->value('id');

        return response()->json([
            'sale' => [
                'id' => $sale->id,
                'number' => $sale->number,
                'sale_datetime' => optional($sale->sale_datetime)->toIso8601String(),
                'grand_total' => (string) $sale->grand_total,
                'status' => $sale->status,
                'customer' => $sale->customer ? [
                    'id' => $sale->customer->id,
                    'name' => $sale->customer->name,
                    'phone' => demo_mask_phone($sale->customer->phone),
                ] : null,
            ],
            'items' => $items,
            'reasons' => ReturnReason::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'methods' => $methods,
            'default_method_id' => $originalMethodId ?: $cashMethodId,
        ]);
    }

    public function store(RefundSaleRequest $request, Sale $sale, RecordSaleReturn $record): JsonResponse|RedirectResponse
    {
        $this->authorize('refund', $sale);

        if (ChannelOrderAllocation::query()->where('sale_id', $sale->getKey())->exists()) {
            return $this->jsonOrError($request, __('sales.channel_managed'));
        }

        try {
            $refund = $record(
                array_merge($request->refundData($sale->id), [
                    'client_uuid' => $request->input('client_uuid'),
                ]),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect(
            $request,
            __('sales.refund.flash.created', ['number' => $refund->number]),
            route('cashier.index'),
            extra: [
                'refund' => [
                    'id' => $refund->id,
                    'number' => $refund->number,
                    'grand_total' => (string) $refund->grand_total,
                    'sale_number' => $sale->number,
                ],
            ],
        );
    }

    /**
     * Honour the session-active store (same key as the cashier index).
     * Falls back to the default / first active store so a fresh login
     * still finds something to scope the lookup against.
     */
    private function activeStoreId(Request $request): int
    {
        $sessionId = (int) $request->session()->get('active_store_id', 0);
        if ($sessionId > 0) {
            return $sessionId;
        }

        $store = Store::query()->where('is_active', true)->orderBy('id')->first();

        return $store?->id ?? 0;
    }
}
