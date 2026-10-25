<?php

namespace App\Http\Controllers\Kiosk;

use App\Actions\Customers\ResolveKioskCustomer;
use App\Actions\Inventory\ResolveFefoBatch;
use App\Actions\Products\ResolveProductPrice;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\GenerateKioskPickupCode;
use App\Actions\Sales\PriceCart;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\PosPaymentSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockLevel;
use App\Models\Store;
use App\Services\Inventory\KioskBatchReservations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Kiosk `checkout` mode — the customer pays at the kiosk (Slice 3).
 *
 * Reuses the QR-chooser payment stack (`PosPaymentSession` + `/pay/pos/{uuid}`):
 *   POST /kiosk/checkout/start    — resolve prices, open a payment session,
 *                                   return the QR URL. Kiosk polls the shared
 *                                   `/cashier/pos-sessions/{uuid}/status`.
 *   POST /kiosk/checkout/complete — once the session is `paid`, finalise the
 *                                   sale through {@see CompleteSale} with
 *                                   `origin = kiosk` and return the receipt link.
 *
 * The kiosk NEVER sends prices. The authoritative sale lines are resolved at
 * `start` (same PriceCart the cashier + gateway charge use) and persisted on
 * the session row; `complete` replays those reserved lines, so the recorded
 * sale can't disagree with the amount charged, and a tampered client cart
 * can't change what's rung up. See docs/features/kiosk-self-ordering.md §5.
 */
class KioskCheckoutController extends Controller
{
    private const CACHE_PREFIX = 'kiosk:checkout:';

    public function start(
        Request $request,
        PriceCart $priceCart,
        ResolveProductPrice $resolvePrice,
        ResolveFefoBatch $resolveFefo,
        ResolveKioskCustomer $resolveCustomer,
        KioskBatchReservations $reservations,
    ): JsonResponse {
        $this->authorize('create', Sale::class);

        $terminal = current_terminal();
        if (! $terminal || ! $terminal->kioskEnabled()) {
            return response()->json(['message' => __('kiosk.errors.not_configured')], 422);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'local_uuid' => ['required', 'string', 'max:64'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
        ]);
        $lineKeys = collect($data['items'])->map(fn (array $line): string => (int) $line['product_id'].':'
            .(isset($line['variant_id']) && $line['variant_id'] !== '' ? (int) $line['variant_id'] : ''));
        if ($lineKeys->unique()->count() !== $lineKeys->count()) {
            return response()->json([
                'message' => __('validation.distinct', ['attribute' => 'items']),
            ], 422);
        }

        // Customer details (Slice 5) — required-mode blocks paying without a
        // name/phone; otherwise attach when provided. Resolved now and cached
        // with the lines so `complete` records it authoritatively.
        if ($terminal->kioskCustomerMode() === 'required'
            && trim((string) ($data['customer_name'] ?? '')) === ''
            && trim((string) ($data['customer_phone'] ?? '')) === '') {
            return response()->json(['message' => __('kiosk.customer.required')], 422);
        }
        $customerId = $resolveCustomer($data['customer_name'] ?? null, $data['customer_phone'] ?? null, $request->user());

        // Idempotency — a retried "Pay now" returns the existing live session
        // instead of opening a second charge for the same cart.
        $existing = PosPaymentSession::query()
            ->where('local_uuid', $data['local_uuid'])
            ->latest('id')
            ->first();
        if ($existing && ! in_array($existing->status, [PosPaymentSession::STATUS_EXPIRED, PosPaymentSession::STATUS_CANCELLED, PosPaymentSession::STATUS_FAILED], true)) {
            return response()->json($this->sessionPayload($existing));
        }

        return DB::transaction(function () use (
            $data,
            $request,
            $priceCart,
            $resolvePrice,
            $resolveFefo,
            $terminal,
            $customerId,
            $reservations,
        ): JsonResponse {
            $storeId = active_store_id();
            $store = Store::query()->where('is_active', true)->findOrFail((int) $storeId);

            // Resolve prices server-side and price the cart authoritatively.
            //
            // Bulk-load products, variants, and stock levels up front
            // (code-review #21): a 200-line cart used to fire 3+ queries per
            // line here; now it's three whereIn queries total.
            $items = array_values($data['items']);
            $productIds = collect($items)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->all();
            $variantIds = collect($items)
                ->filter(fn ($r) => isset($r['variant_id']) && $r['variant_id'] !== '')
                ->pluck('variant_id')->map(fn ($id) => (int) $id)->unique()->all();

            $productsById = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
            $variantsById = $variantIds === []
                ? collect()
                : ProductVariant::query()->whereIn('id', $variantIds)->get()->keyBy('id');
            $levelsByKey = StockLevel::query()
                ->where('store_id', (int) $storeId)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn ($l) => $l->product_id.':'.($l->variant_id ?? ''));

            $resolved = [];
            foreach ($items as $i => $raw) {
                // Missing id → same 404 findOrFail raised before the bulk load.
                $product = $productsById->get((int) $raw['product_id'])
                    ?? Product::query()->findOrFail((int) $raw['product_id']);
                $variant = isset($raw['variant_id']) && $raw['variant_id'] !== ''
                    ? $variantsById->get((int) $raw['variant_id'])
                    : null;
                $prices = $resolvePrice($product, (int) $storeId, $variant);

                // Never charge for stock we can't hand over. CompleteSale re-checks
                // under a row lock at `complete`, but that's AFTER the customer has
                // paid — so refuse up front, while it's still just a cart.
                if ($product->track_stock) {
                    $available = bcsub($this->availableFrom(
                        $levelsByKey->get($product->id.':'.($variant?->id ?? ''))
                    ), $reservations->forStock(
                        (int) $storeId,
                        (int) $product->id,
                        $variant?->id ? (int) $variant->id : null,
                    ), 4);
                    if (bccomp($available, '0', 4) < 0) {
                        $available = '0.0000';
                    }
                    if (bccomp((string) $raw['quantity'], $available, 4) > 0) {
                        return response()->json([
                            'message' => __('sales.errors.insufficient_stock', [
                                'name' => (string) $product->name,
                                'available' => $available,
                                'requested' => (string) $raw['quantity'],
                            ]),
                        ], 422);
                    }
                }

                $batch = null;
                if ($product->track_batches) {
                    $company = Company::current() ?? new Company;
                    $excludeExpired = (bool) ($company->block_expired_batch_sale ?? false);
                    $batch = $resolveFefo(
                        (int) $storeId,
                        (int) $product->id,
                        $variant?->id ? (int) $variant->id : null,
                        $excludeExpired,
                        (string) $raw['quantity'],
                        true,
                    );
                    if (! $batch) {
                        return response()->json([
                            'message' => __('sales.errors.invalid_batch', ['name' => $product->name]),
                        ], 422);
                    }
                }

                $resolved[] = [
                    'raw' => [
                        'product_id' => (int) $product->id,
                        'variant_id' => $variant?->id,
                        'batch_id' => $batch?->id,
                        'quantity' => (string) $raw['quantity'],
                        'unit_price' => $prices['selling_price'],
                    ],
                    'product' => $product,
                    'index' => $i,
                ];
            }

            $priced = $priceCart->handle($resolved, $store);

            // Narrow the scan page to the gateways the merchant enabled for THIS
            // kiosk. Empty = every configured gateway (unchanged behaviour); a
            // single id makes `/pay/pos/{uuid}` skip the chooser and jump straight
            // to that gateway. It lives on the session because the public pay page
            // has no terminal context. See docs/features/kiosk-self-ordering.md §5.
            $allowedMethodIds = $terminal->kioskPaymentMethodIds();

            $session = PosPaymentSession::create([
                'uuid' => (string) Str::uuid(),
                'store_id' => $store->id,
                'cashier_id' => $request->user()?->id,
                'local_uuid' => $data['local_uuid'],
                'allowed_method_ids' => $allowedMethodIds ?: null,
                'amount' => $priced->grandTotal,
                // Currency is a SYSTEM-WIDE setting (company.base_currency_code),
                // not a per-store one — the kiosk prices, displays, and charges in
                // it, and the cashier's QR flow already sends the same value. The
                // legacy `stores.currency_code` column can still hold a stale code
                // from install; trusting it here charged the customer in one
                // currency while the kiosk showed them another.
                'currency' => strtoupper((string) app_currency()['code']),
                'cart_summary' => [
                    '_kiosk_reservation' => [
                        'state' => 'active',
                        'lines' => collect($priced->lines)->pluck('raw')->all(),
                        'customer_id' => $customerId,
                    ],
                ],
                'status' => PosPaymentSession::STATUS_PENDING,
                'expires_at' => now()->addMinutes(PosPaymentSession::DEFAULT_TTL_MINUTES),
            ]);

            // Stash the authoritative lines (+ resolved customer) against the
            // session so `complete` rings up exactly what was priced — never the
            // client's resend.
            Cache::put(
                self::CACHE_PREFIX.$session->uuid,
                ['lines' => collect($priced->lines)->pluck('raw')->all(), 'customer_id' => $customerId],
                now()->addMinutes(PosPaymentSession::DEFAULT_TTL_MINUTES + 5),
            );

            return response()->json($this->sessionPayload($session));
        });
    }

    public function complete(
        Request $request,
        CompleteSale $complete,
        ResolveFefoBatch $resolveFefo,
        GenerateKioskPickupCode $pickupCode,
    ): JsonResponse {
        $this->authorize('create', Sale::class);

        $data = $request->validate([
            'uuid' => ['required', 'string', 'max:64'],
        ]);

        $session = PosPaymentSession::query()->where('uuid', $data['uuid'])->first();
        if (! $session) {
            return response()->json(['message' => __('kiosk.errors.checkout_expired')], 422);
        }

        // Already rung up (double-tap / retry) → return the existing receipt.
        if ($done = Sale::query()->where('local_uuid', $session->local_uuid)->first()) {
            $summary = (array) ($session->cart_summary ?? []);
            $reservation = (array) data_get($summary, '_kiosk_reservation', []);
            if (($reservation['state'] ?? null) === 'active') {
                $summary['_kiosk_reservation'] = array_merge($reservation, [
                    'state' => 'fulfilled',
                    'sale_id' => (int) $done->id,
                ]);
                $session->forceFill(['cart_summary' => $summary, 'failure_message' => null])->save();
            }
            Cache::forget(self::CACHE_PREFIX.$session->uuid);

            return response()->json([
                'ok' => true,
                'receipt_url' => $done->publicReceiptUrl(),
                'number' => $done->number,
                'pickup_code' => $done->pickup_code,
            ]);
        }

        if (! $session->isPaid()) {
            return response()->json(['message' => __('kiosk.errors.checkout_unpaid')], 422);
        }
        if (! $session->payment_method_id) {
            return response()->json(['message' => __('kiosk.errors.checkout_unpaid')], 422);
        }

        $reservation = (array) data_get($session->cart_summary, '_kiosk_reservation', []);
        $cached = Cache::get(self::CACHE_PREFIX.$session->uuid) ?: [
            'lines' => $reservation['lines'] ?? null,
            'customer_id' => $reservation['customer_id'] ?? null,
        ];
        $lines = $cached['lines'] ?? null;
        if (empty($lines)) {
            return response()->json(['message' => __('kiosk.errors.checkout_expired')], 422);
        }

        // Auto-assign a FEFO batch for any batch-tracked line. The customer
        // can't pick a lot at a self-serve kiosk (and shouldn't — it's an
        // inventory concern), so we resolve the earliest-expiring live batch
        // server-side here, mirroring the cashier's picker without a UI. This
        // keeps per-batch quantities in step with the stock decrement — a
        // batchless line would drift the batch rows out of sync. The kiosk
        // always enforces the company's expired-batch block; the background
        // staff session never grants an override to an anonymous shopper.
        $company = Company::current() ?? new Company;
        $blockExpired = (bool) ($company->block_expired_batch_sale ?? false);
        $excludeExpired = $blockExpired;

        // One whereIn instead of a Product::find per cached line
        // (code-review #21) — only the track_batches flag is needed here.
        $batchFlags = Product::query()
            ->whereIn('id', collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->all())
            ->pluck('track_batches', 'id');

        foreach ($lines as $i => $line) {
            if (! ($batchFlags[(int) $line['product_id']] ?? false)) {
                continue;
            }
            if (! empty($line['batch_id'])) {
                continue;
            }
            $batch = $resolveFefo(
                (int) $session->store_id,
                (int) $line['product_id'],
                isset($line['variant_id']) && $line['variant_id'] !== '' ? (int) $line['variant_id'] : null,
                $excludeExpired,
            );
            if ($batch) {
                $lines[$i]['batch_id'] = $batch->id;
            }
        }

        $provider = PaymentMethod::query()->whereKey($session->payment_method_id)->value('provider');

        try {
            $sale = $complete(
                [
                    'store_id' => $session->store_id,
                    'origin' => 'kiosk',
                    'customer_id' => $cached['customer_id'] ?? null,
                    'local_uuid' => $session->local_uuid,
                    'currency_code' => $session->currency,
                    'enforce_expired_batch_block' => true,
                    'batch_reservation_session_uuid' => (string) $session->uuid,
                    // The sale is paid, but the goods are still behind the counter.
                    // The shopper needs something short to be called by — a receipt
                    // QR is for their records, not for staff to match against.
                    'pickup_code' => $pickupCode(
                        (int) $session->store_id,
                        current_terminal()?->kioskPickupPrefix() ?? 'K',
                    ),
                ],
                $lines,
                [[
                    'payment_method_id' => (int) $session->payment_method_id,
                    'amount' => (string) $session->amount,
                    'gateway_provider' => $provider,
                    'gateway_payment_id' => $session->gateway_payment_id,
                    'gateway_status' => 'paid',
                ]],
                $request->user(),
            );
        } catch (\Throwable $e) {
            $session->forceFill([
                'failure_message' => __('kiosk.errors.checkout_recovery_required'),
            ])->save();
            Log::critical('Paid kiosk checkout requires recovery.', [
                'payment_session_id' => $session->id,
                'payment_session_uuid' => $session->uuid,
                'gateway_payment_id' => $session->gateway_payment_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $summary = (array) ($session->cart_summary ?? []);
        $summary['_kiosk_reservation'] = array_merge($reservation, [
            'state' => 'fulfilled',
            'sale_id' => (int) $sale->id,
        ]);
        $session->forceFill(['cart_summary' => $summary, 'failure_message' => null])->save();
        Cache::forget(self::CACHE_PREFIX.$session->uuid);

        return response()->json([
            'ok' => true,
            'receipt_url' => $sale->publicReceiptUrl(),
            'number' => $sale->number,
            'pickup_code' => $sale->pickup_code,
            'grand_total' => (string) $sale->grand_total,
        ]);
    }

    /**
     * On-hand minus what held tickets / pending kiosk orders already promised,
     * for one (store, product, variant). Floored at zero. A missing stock row
     * means "never stocked" — which CompleteSale treats as zero, so we do too.
     */
    /** Sellable quantity from a (possibly missing) pre-loaded level row. */
    private function availableFrom(?StockLevel $level): string
    {
        if (! $level) {
            return '0.0000';
        }

        $available = bcsub((string) $level->quantity, (string) $level->reserved_quantity, 4);

        return bccomp($available, '0', 4) < 0 ? '0.0000' : $available;
    }

    /** @return array<string, mixed> */
    private function sessionPayload(PosPaymentSession $session): array
    {
        return [
            'uuid' => $session->uuid,
            'pay_url' => route('pay.pos.show', $session->uuid),
            'amount' => (string) $session->amount,
            'currency' => $session->currency,
            'status' => $session->status,
            'expires_at' => optional($session->expires_at)->toIso8601String(),
        ];
    }
}
