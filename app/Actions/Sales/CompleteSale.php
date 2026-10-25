<?php

namespace App\Actions\Sales;

use App\Actions\Customers\EarnLoyaltyPoints;
use App\Actions\Customers\RedeemLoyaltyPoints;
use App\Actions\Inventory\RecordStockMovement;
use App\Actions\Inventory\ResolveFefoBatch;
use App\Actions\Products\ResolveLineCost;
use App\Exceptions\CreditLimitExceeded;
use App\Exceptions\CreditRequiresCustomer;
use App\Exceptions\DiscountAboveThreshold;
use App\Exceptions\DiscountNotAllowed;
use App\Exceptions\ExpiredBatchSale;
use App\Exceptions\InsufficientStock;
use App\Exceptions\LoyaltyRedemptionInvalid;
use App\Exceptions\ShiftRequired;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleDiscount;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\User;
use App\Services\Customers\CustomerDiscountResolver;
use App\Services\Inventory\KioskBatchReservations;
use App\Services\Loyalty\LoyaltyCalculator;
use App\Services\Sales\DiscountApprovalToken;
use App\Services\Tax\TaxResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Complete a sale — the single canonical path the cashier flow goes
 * through to finalize a ring-up.
 *
 * Input shape (decimal-strings at 4dp, matching every other money path
 * in the system):
 *
 *   $header = [
 *     'store_id'       => int,
 *     'customer_id'    => ?int,           // null = walk-in
 *     'currency_code'  => 'USD',
 *     'sale_date'      => 'YYYY-MM-DD',
 *     'local_uuid'     => string-uuid|null,  // client-generated idempotency key
 *     'notes'          => ?string,
 *   ]
 *   $lines = [
 *     [
 *       'product_id'        => int,
 *       'variant_id'        => ?int,
 *       'batch_id'          => ?int,        // optional — a batch-tracked line that omits it gets an automatic FEFO pick ({@see resolveBatchForLine})
 *       'quantity'          => decimal-string,
 *       'unit_price'        => decimal-string,  // pre-discount
 *       'discount_percent'  => decimal-string,  // optional
 *       'discount_amount'   => decimal-string,  // optional, line-level absolute discount
 *       'notes'             => ?string,
 *     ], …
 *   ]
 *   $payments = [
 *     [
 *       'payment_method_id'  => int,
 *       'amount'             => decimal-string,
 *       'tendered_amount'    => decimal-string|null,    // for cash, the customer's tendered cash
 *       'reference'          => ?string,                // for manual-reference, the txn id
 *       'gateway_provider'   => ?string,                // Slice 2 fields, pass-through
 *       'gateway_payment_id' => ?string,
 *       'gateway_signature'  => ?string,
 *       'gateway_status'     => ?string,
 *     ], …
 *   ]
 *
 * Atomic transaction:
 *   1. Lock per-(store, product, variant) `product_stock_levels` row.
 *   2. Slice-1 negative-stock policy: block if `quantity_on_hand < line_qty`.
 *   3. Resolve tax per line via {@see TaxResolver}, snapshot the breakdown.
 *   4. Compute header totals (subtotal/discount/tax/grand) from rounded line values.
 *   5. Sum of payments MUST equal grand_total exactly (no partial-pay in Slice 1).
 *   6. Generate next `SALE-{STORE}-YYYYMM-NNNN`, insert sales/items/payments.
 *   7. Loop {@see RecordStockMovement} (`type='sale'`, negative delta) per line —
 *      the WAC stays untouched on negative movements.
 *   8. Idempotency: if a sale with this `local_uuid` already exists, return it
 *      unchanged. Lets the cashier retry on network glitches without dupes.
 *
 * Hooks:
 *   - filter `sale.fillable`         → ($header, $lines, $payments)
 *   - action `sale.before_complete`  → ($header, $lines, $payments)
 *   - action `sale.after_complete`   → ($sale)
 *
 * Returns the persisted Sale row (with `items` and `payments` loaded).
 *
 * @param array<string, mixed>            $header
 * @param array<int, array<string, mixed>> $lines
 * @param array<int, array<string, mixed>> $payments
 */
class CompleteSale
{
    /**
     * Where a sale was rung up. Reports lean on this to tell counter sales
     * from self-service and back-office ones. Anything not listed here
     * falls back to `cashier`.
     */
    public const ORIGINS = ['cashier', 'kiosk', 'api', 'admin'];

    /**
     * Origins that never need an open till shift. A back-office sale is
     * raised from the admin area by someone who has no drawer open — that's
     * the entire point of the screen — so per-store shift enforcement
     * doesn't apply to it. Cash taken on one of these consequently sits
     * outside the drawer count and Z-report; the admin create form says so.
     */
    private const SHIFTLESS_ORIGINS = ['admin'];

    public function __construct(
        private readonly TaxResolver $taxResolver,
        private readonly GenerateSaleNumber $generateNumber,
        private readonly RecordStockMovement $recordMovement,
        private readonly PriceCart $priceCart,
        private readonly EarnLoyaltyPoints $earnLoyalty,
        private readonly RedeemLoyaltyPoints $redeemLoyalty,
        private readonly LoyaltyCalculator $loyalty,
        private readonly CustomerDiscountResolver $customerDiscount,
        private readonly ResolveFefoBatch $resolveBatch,
        private readonly ResolveLineCost $resolveLineCost,
        private readonly KioskBatchReservations $kioskReservations,
    ) {}

    public function __invoke(
        array $header,
        array $lines,
        array $payments,
        ?User $user = null,
        bool $honorProvidedUnitPrices = false,
    ): Sale
    {
        [$header, $lines, $payments] = apply_filters(
            'sale.fillable',
            [$header, $lines, $payments]
        );

        if (empty($lines)) {
            throw new RuntimeException('Cannot complete a sale with no items.');
        }
        // Empty payments is allowed only when a customer is attached
        // (pure-credit sale — entire grand lands on balance_due). The
        // sum-vs-grand check below enforces the rest.
        $hasCustomer = isset($header['customer_id']) && $header['customer_id'] !== '';
        if (empty($payments) && ! $hasCustomer) {
            throw new RuntimeException('Cannot complete a sale with no payments.');
        }

        // Idempotency short-circuit BEFORE the transaction. The
        // `sales.local_uuid` column is uniquely indexed; a retry from
        // the cashier (same uuid) returns the existing row instead of
        // double-posting.
        $clientUuid = isset($header['local_uuid']) && $header['local_uuid'] !== ''
            ? (string) $header['local_uuid']
            : (string) Str::uuid();

        if ($existing = Sale::query()->where('local_uuid', $clientUuid)->first()) {
            return $existing->load(['items', 'payments']);
        }

        pos_guard('sale.complete', $header, $lines, $payments, $user);
        do_action('sale.before_complete', $header, $lines, $payments);

        return DB::transaction(function () use ($header, $lines, $payments, $clientUuid, $user, $honorProvidedUnitPrices) {
            $storeId = (int) $header['store_id'];
            $store   = Store::query()->findOrFail($storeId);

            // The store arrives in the payload, and every caller — the till,
            // the offline drain, the kiosk, a future API client — could name
            // any store. `sales.create` is a class-level permission and says
            // nothing about WHICH store, so without this a user assigned to
            // one branch could post a sale against another: decrementing its
            // stock, posting its revenue, and landing in its till (SEC #13 /
            // defect #67). Enforced in the action rather than one controller
            // so no entry point can miss it.
            if ($user && ! $user->is_super_admin && ! $user->canAccessStore($storeId)) {
                throw new RuntimeException('You do not have access to the store this sale is for.');
            }

            $company = Company::current() ?? new Company();

            // Overselling (letting stock go negative) is allowed only when the
            // store opts in AND this user carries `sales.oversell`. Resolved
            // once — the per-line guard below reads it. Non-tracked products
            // are never stock-blocked regardless (services / made-to-order).
            $canOversell = (bool) ($company->cashier()['allow_negative_stock'] ?? false)
                && $user?->hasPermission('sales.oversell');

            // ── 1. Resolve products + locked stock levels for every line.
            //
            // Bulk-load every line's product up front (code-review #18): one
            // indexed whereIn instead of a findOrFail per line INSIDE the
            // locked transaction, which lengthened the stock row-lock hold as
            // the basket grew. Relations match what the tax resolver's
            // product→category fallback needs (code-review #11), loaded once.
            $productIds = collect($lines)
                ->pluck('product_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();
            $productsById = Product::query()
                ->with(['taxGroup.components', 'category.taxGroup.components'])
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            $resolvedLines = [];
            foreach (array_values($lines) as $i => $raw) {
                // From the pre-loaded map; the findOrFail fallback preserves
                // the original not-found behaviour for an id that somehow
                // isn't in the batch (a product deleted between build + here).
                $product = $productsById->get((int) $raw['product_id'])
                    ?? Product::query()->findOrFail((int) $raw['product_id']);

                // Lock the stock level row before checking. Two concurrent
                // cashiers ringing up the last unit must serialize here.
                $level = StockLevel::query()
                    ->where('store_id', $storeId)
                    ->where('product_id', $product->id)
                    ->where('variant_id', $raw['variant_id'] ?? null)
                    ->lockForUpdate()
                    ->first();

                $onHand = $level ? (string) $level->quantity : '0';
                $qty    = (string) ($raw['quantity'] ?? '0');

                if (bccomp($qty, '0', 4) <= 0) {
                    throw new RuntimeException("Line {$i}: quantity must be positive.");
                }

                // A batch-tracked line that arrived without a batch gets one
                // resolved here — FEFO, earliest expiry first (which degrades
                // to oldest-received for undated stock).
                //
                // This is a FALLBACK, not a policy: an explicit batch_id from
                // the caller always wins. It lives in the action rather than in
                // each controller because every entry point has to get this
                // right — the till, the offline drain, the kiosk, the API, a
                // future plugin — and a caller that forgets silently corrupts
                // inventory: RecordStockMovement decrements the store level but
                // no batch row, so on-hand and the sum of its batches drift
                // apart with nothing to flag it.
                $raw['batch_id'] = $this->resolveBatchForLine(
                    $raw,
                    $product,
                    $storeId,
                    $company,
                    $user,
                    (bool) ($header['enforce_expired_batch_block'] ?? false),
                    isset($header['batch_reservation_session_uuid'])
                        ? (string) $header['batch_reservation_session_uuid']
                        : null,
                );

                // Block negative stock — unless the product isn't inventory
                // tracked, or the store allows overselling and this user may
                // do it (see $canOversell above). Batch-tracked lines are
                // never oversold here: the FEFO picker needs a real batch, so
                // the block still applies to them (revisit for backorders).
                // Classify by the PRODUCT flag, not by whether the payload
                // happened to carry a batch_id (SEC #76 / defect #305). The
                // old check meant a crafted request could omit batch_id on a
                // batch-tracked product and slip past the never-negative-batch
                // guarantee: aggregate stock went negative while
                // product_batches stayed untouched, so on-hand and the sum of
                // its batches drifted apart with nothing to flag it. The dev
                // guide tests this exact UI bypass (TC-104-044).
                $isBatchLine = (bool) $product->track_batches
                    || (isset($raw['batch_id']) && $raw['batch_id'] !== '');

                // A batch-tracked line must resolve to a real batch. FEFO
                // above already tries; if it still has none there is no row to
                // decrement, so refuse rather than silently corrupt the ledger.
                if ($product->track_batches && $product->track_stock
                    && (! isset($raw['batch_id']) || $raw['batch_id'] === '')) {
                    throw new RuntimeException(
                        "Line {$i}: {$product->name} is batch-tracked and no batch could be resolved."
                    );
                }

                $mayGoNegative = ! $product->track_stock || ($canOversell && ! $isBatchLine);
                $reserved = $level ? (string) $level->reserved_quantity : '0';
                $reserved = bcadd($reserved, $this->kioskReservations->forStock(
                    $storeId,
                    (int) $product->id,
                    isset($raw['variant_id']) && $raw['variant_id'] !== '' ? (int) $raw['variant_id'] : null,
                    isset($header['batch_reservation_session_uuid'])
                        ? (string) $header['batch_reservation_session_uuid']
                        : null,
                ), 4);
                $available = bcsub($onHand, $reserved, 4);
                if (bccomp($available, '0', 4) < 0) {
                    $available = '0.0000';
                }

                if (! $mayGoNegative && bccomp($available, $qty, 4) < 0) {
                    throw new InsufficientStock(
                        productName: (string) $product->name,
                        available:   $available,
                        requested:   $qty,
                    );
                }

                // Slice 3b: block expired-batch sales when the company
                // setting is on AND the cashier lacks the override
                // permission. Fetched as a separate query so we hit the
                // batches row only when the line cares (it's cheap, but
                // batchless lines should pay nothing).
                $batchId = isset($raw['batch_id']) && $raw['batch_id'] !== ''
                    ? (int) $raw['batch_id']
                    : null;
                if ($batchId !== null) {
                    if ((bool) ($company->block_expired_batch_sale ?? false)) {
                        $hasOverride = ! (bool) ($header['enforce_expired_batch_block'] ?? false) && $user
                            ? $user->hasPermission('inventory.sell_expired')
                            : false;
                        if (! $hasOverride) {
                            $batch = ProductBatch::query()->find($batchId);
                            if ($batch && $batch->isExpired()) {
                                throw new ExpiredBatchSale(
                                    productName: (string) $product->name,
                                    batchNumber: (string) $batch->batch_number,
                                    expiryDate:  $batch->expiry_date->toDateString(),
                                );
                            }
                        }
                    }
                }

                // What this unit cost us, captured HERE while the batch is
                // known and the stock row is already locked in memory. Every
                // profit number downstream (report margins, dashboard COGS,
                // the value of a return) reads the snapshot rather than
                // re-deriving cost later, because by then the WAC has moved.
                $resolvedLines[] = [
                    'raw'       => $raw,
                    'product'   => $product,
                    'index'     => $i,
                    'unit_cost' => ($this->resolveLineCost)(
                        product:   $product,
                        storeId:   $storeId,
                        variantId: isset($raw['variant_id']) && $raw['variant_id'] !== '' ? (int) $raw['variant_id'] : null,
                        batchId:   $batchId,
                        level:     $level,
                    ),
                ];
            }

            // ── 2. Price the cart via the shared PriceCart action — the
            // SAME code the QR/gateway charge uses, so the amount charged
            // can never disagree with the amount recorded here. Holds the
            // invariant sum(items.line_total) == subtotal + tax_total ==
            // grand_total exactly (scale-8-then-round-4-dp throughout).
            $priced = $this->priceCart->handle(
                $resolvedLines,
                $store,
                $user,
                honorProvidedUnitPrices: $honorProvidedUnitPrices,
            );

            $subtotal      = $priced->subtotal;
            $discountTotal = $priced->discountTotal;
            $taxTotal      = $priced->taxTotal;
            $grandTotal    = $priced->grandTotal;
            $itemsToInsert = [];

            $customerId = isset($header['customer_id']) && $header['customer_id'] !== ''
                ? (int) $header['customer_id'] : null;

            // ── Loyalty redemption (§9.3). The cashier folds the redeemed
            // value into the line discounts, exactly like the order-level
            // discount — so by the time we get here it is already inside
            // `$discountTotal`. What we do NOT trust is how much of it is
            // loyalty: that we re-resolve from the customer's real balance
            // and the stored rates, then (a) exempt it from the discount
            // governance below, and (b) spend the points after the save.
            $redeemPoints = (int) ($header['loyalty_points'] ?? 0);
            $redeemValue  = '0.0000';

            if ($redeemPoints > 0) {
                if (! $customerId) {
                    throw new LoyaltyRedemptionInvalid('no_customer');
                }
                if (! $this->loyalty->isEnabled()) {
                    throw new LoyaltyRedemptionInvalid('disabled');
                }

                $redeemCustomer = Customer::query()->lockForUpdate()->find($customerId);
                if (! $redeemCustomer) {
                    throw new LoyaltyRedemptionInvalid('no_customer');
                }

                // Base = what's payable before the points come off, i.e.
                // the grand total the cart would have had. The cart already
                // deducted the redemption, so add it back on: the client's
                // claimed value is only used to reconstruct that base, never
                // to decide the outcome.
                $claimed  = $this->round4((string) ($header['loyalty_value'] ?? '0'));
                $base     = bcadd($grandTotal, bccomp($claimed, '0', 4) > 0 ? $claimed : '0', 4);
                $resolved = $this->loyalty->resolveRedemption($redeemPoints, $redeemCustomer, $base);

                if ($resolved['points'] <= 0) {
                    throw new LoyaltyRedemptionInvalid('unavailable', [
                        'balance' => (string) ($redeemCustomer->loyalty_points ?? 0),
                    ]);
                }

                $redeemPoints = $resolved['points'];
                $redeemValue  = $resolved['value'];

                // The cart's discount must actually carry the redemption.
                // If the client asked to spend points but discounted less
                // than they're worth, the customer would lose points for
                // nothing — reject rather than quietly short-change them.
                // The half-paisa hair mirrors the payments tolerance below.
                if (bccomp($discountTotal, bcsub($redeemValue, '0.05', 4), 4) < 0) {
                    throw new LoyaltyRedemptionInvalid('not_applied');
                }
            }

            // ── Discount governance (Checkout discounts, Slices 1 + 2). ──
            // Any discount needs `sales.discount`; a discount above the
            // store's threshold needs `sales.discount_above_threshold` OR a
            // valid manager-approval token (Slice 2). Enforced server-side
            // so the client checks can't be bypassed.
            $discountApprovedBy = null;

            // Loyalty redemption is NOT a cashier discount — the customer
            // is spending something they already earned. Govern only what's
            // left after taking it out, or every redemption would demand a
            // manager PIN.
            $governedDiscount = bcsub($discountTotal, $redeemValue, 4);
            if (bccomp($governedDiscount, '0', 4) < 0) {
                $governedDiscount = '0';
            }

            if (bccomp($governedDiscount, '0', 4) > 0) {
                // Effective discount % against the pre-discount gross.
                $gross = '0';
                foreach ($priced->lines as $pl) {
                    $gross = bcadd(
                        $gross,
                        bcmul((string) ($pl['raw']['quantity'] ?? '0'), (string) ($pl['raw']['unit_price'] ?? '0'), 8),
                        8,
                    );
                }
                $effectivePct = bccomp($gross, '0', 4) > 0
                    ? bcdiv(bcmul($governedDiscount, '100', 8), $gross, 4)
                    : '0';

                // A customer's admin-configured default discount is pre-
                // authorized: a discount within it needs neither `sales.discount`
                // nor manager approval (the cashier just picked the customer).
                // Resolved server-side from the customer AND their group — NOT
                // from a client-sent value — so it can't be spoofed to widen
                // the exemption. The 0.05% hair absorbs 4-dp rounding on the
                // effective-percent computation.
                $customerDefaultPct = $this->customerDiscount->percentForId($customerId);
                $exemptCeiling = bccomp($customerDefaultPct, '0', 4) > 0
                    ? bcadd($customerDefaultPct, '0.05', 4)
                    : '0';

                // Only the portion beyond the pre-authorized default is
                // cashier-initiated → govern it (permission + threshold).
                if (bccomp($effectivePct, $exemptCeiling, 4) > 0) {
                    $actor = $user ?? auth()->user();
                    if (! ($actor?->hasPermission('sales.discount') ?? false)) {
                        throw new DiscountNotAllowed();
                    }

                    $threshold = (string) ($store->discount_threshold_percent ?? '100');
                    if (bccomp($effectivePct, $threshold, 4) > 0
                        && ! ($actor?->hasPermission('sales.discount_above_threshold') ?? false)) {
                        // Fall back to a signed manager-approval token. The
                        // approver must still hold the permission for this store.
                        $approverId = app(DiscountApprovalToken::class)
                            ->verify($header['discount_approval'] ?? null, $storeId, $effectivePct);
                        $approver = $approverId ? User::find($approverId) : null;
                        if (! ($approver?->hasPermission('sales.discount_above_threshold', $storeId) ?? false)) {
                            throw new DiscountAboveThreshold($effectivePct, $threshold);
                        }
                        $discountApprovedBy = $approverId;
                    }
                }
            }

            foreach ($priced->lines as $pl) {
                /** @var Product $product */
                $product = $pl['product'];
                $raw     = $pl['raw'];
                $i       = $pl['index'];

                $itemsToInsert[] = [
                    'product_id'            => (int) $product->id,
                    'variant_id'            => isset($raw['variant_id']) && $raw['variant_id'] !== '' ? (int) $raw['variant_id'] : null,
                    'batch_id'              => isset($raw['batch_id']) && $raw['batch_id'] !== ''     ? (int) $raw['batch_id']   : null,
                    'product_name_snapshot' => (string) $product->name,
                    'sku_snapshot'          => $product->sku,
                    'barcode_snapshot'      => $product->barcode,
                    'hsn_snapshot'          => $product->hsn_code,
                    'quantity'              => $this->round4((string) ($raw['quantity'] ?? '0')),
                    'unit'                  => (string) ($product->unit?->code ?? 'pc'),
                    'unit_price'            => $this->round4((string) ($raw['unit_price'] ?? '0')),
                    // What this unit cost us, frozen at sale time — see
                    // ResolveLineCost for where the figure comes from. Frozen
                    // rather than derived on read because the WAC moves with
                    // every later receipt, and last month's margin must not
                    // change because this month's stock arrived dearer.
                    'unit_cost_snapshot'    => $this->round4((string) ($pl['unit_cost'] ?? '0')),
                    'discount_percent'      => $this->round4((string) ($raw['discount_percent'] ?? '0')),
                    'discount_amount'       => $this->round4($pl['discount']),
                    'tax_group_id'          => $this->taxResolver->effectiveTaxGroupId($product),
                    'tax_breakdown'         => $pl['breakdown']->jsonSerialize(),
                    'tax_amount'            => $pl['tax'],
                    'line_subtotal'         => $pl['net'],
                    'line_total'            => $pl['line_total'],
                    'notes'                 => $raw['notes'] ?? null,
                    'sort_order'            => $i,
                ];
            }

            // ── 3. Payments invariant.
            //
            // Walk-in (no customer attached): sum MUST equal grand_total
            // exactly — over-pay would be unredeemable, under-pay would
            // be unrecoverable.
            //
            // Customer attached: sum may be LESS than grand_total → the
            // shortfall lands on `sales.balance_due` and bumps the
            // customer's `outstanding_balance` (credit sale). Over-pay
            // is still blocked; on-account customers receive change in
            // cash or apply the surplus on a follow-up payment record,
            // not by overpaying the original ring-up.
            $paymentMethods = PaymentMethod::query()
                ->whereIn('id', array_map(fn ($p) => (int) ($p['payment_method_id'] ?? 0), $payments))
                ->get(['id', 'name', 'provider', 'requires_reference'])
                ->keyBy('id');

            // The browser prevents an empty reference for methods that require
            // one, but offline-sync and direct API callers must be held to the
            // same rule before any sale rows are written.
            foreach ($payments as $p) {
                $method = $paymentMethods->get((int) ($p['payment_method_id'] ?? 0));
                if ($method?->requires_reference && trim((string) ($p['reference'] ?? '')) === '') {
                    throw new RuntimeException(__('cashier.pay.reference_required', [
                        'method' => $method->name,
                    ]));
                }
            }

            $sumPay = '0';
            foreach ($payments as $p) {
                $sumPay = bcadd($sumPay, (string) ($p['amount'] ?? '0'), 4);
            }

            $storeCreditAmount = $this->round4((string) ($header['store_credit_amount'] ?? '0'));
            if (bccomp($storeCreditAmount, '0', 4) < 0) {
                throw new RuntimeException(__('cashier.store_credit.errors.positive'));
            }
            if (bccomp($storeCreditAmount, '0', 4) > 0) {
                if (! $customerId) {
                    throw new RuntimeException(__('cashier.store_credit.errors.customer_required'));
                }
                $sumPay = bcadd($sumPay, $storeCreditAmount, 4);
            }

            // Tolerance: the cashier sees prices at 2dp but the server
            // stores at 4dp. The client computes grand_total in JS
            // floats; the server in bcmath (which truncates at scale 4
            // — NOT rounds). The two can disagree by a sub-paisa after
            // compound tax math, so an "exact cash" tender of ₹52.32
            // against a ₹52.3197 server-side grand would have spuriously
            // tripped the walk-in-must-be-fully-paid guard. Anything
            // bigger than a half-paisa is a real discrepancy.
            $EPSILON   = '0.05';
            $diff      = bcsub($sumPay, $grandTotal, 4);
            $isPartial = bccomp($diff, '-'.$EPSILON, 4) < 0;
            $isOver    = bccomp($diff, $EPSILON, 4) > 0;

            if ($isOver) {
                throw new RuntimeException(
                    "Payments total ({$sumPay}) is more than the grand total ({$grandTotal})."
                );
            }
            if ($isPartial && ! $customerId) {
                throw new CreditRequiresCustomer();
            }

            // Within tolerance → call it exact. Re-pin `$sumPay` to
            // the server-side grand so balance_due lands on 0 cleanly
            // instead of ±0.0001 sub-paisa.
            if (! $isPartial && ! $isOver) {
                $sumPay = (string) $grandTotal;
            }

            // Credit-limit guard. customer.credit_limit == 0 means "no
            // limit" — explicit unlimited is the default for retail
            // owners who don't want to bother configuring per-customer.
            // A positive limit blocks if the post-sale balance would
            // exceed it.
            $balanceDue = bcsub($grandTotal, $sumPay, 4);
            $customer = $customerId
                ? Customer::query()->lockForUpdate()->find($customerId)
                : null;

            $legacyCredit = '0.0000';
            if ($customer && bccomp((string) ($customer->outstanding_balance ?? '0'), '0', 4) < 0) {
                $legacyCredit = bcmul((string) $customer->outstanding_balance, '-1', 4);
            }
            $availableStoreCredit = $customer
                ? bcadd((string) ($customer->store_credit_balance ?? '0'), $legacyCredit, 4)
                : '0.0000';

            if (bccomp($storeCreditAmount, $availableStoreCredit, 4) > 0) {
                throw new RuntimeException(__('cashier.store_credit.errors.insufficient', [
                    'available' => format_money($availableStoreCredit),
                ]));
            }

            if ($customer && bccomp($balanceDue, '0', 4) > 0) {
                $limit = (string) ($customer->credit_limit ?? '0');
                if (bccomp($limit, '0', 4) > 0) {
                    $currentBalance = bccomp((string) ($customer->outstanding_balance ?? '0'), '0', 4) > 0
                        ? (string) $customer->outstanding_balance
                        : '0.0000';
                    $newBalance = bcadd($currentBalance, $balanceDue, 4);
                    if (bccomp($newBalance, $limit, 4) > 0) {
                        throw new CreditLimitExceeded(
                            customerName:   (string) $customer->name,
                            currentBalance: $currentBalance,
                            newBalance:     $newBalance,
                            limit:          $limit,
                        );
                    }
                }
            }

            // ── 4. Sale row.
            $number = ($this->generateNumber)($storeId);

            // Build the row in one save. `number`, `status`, and the totals
            // are non-fillable on the model so we use forceFill — but we
            // can't split create+forceFill because `number` is NOT NULL.
            // Auto-bind the active shift, if the cashier has one open for
            // this store. Per-store enforcement (block sale when no open
            // shift) lands with the Settings → Shifts toggle in a later
            // slice — for now, shifts are opt-in and the cashier flow
            // works either way.
            $cashierId = $user?->id ?? auth()->id();
            $activeShift = $cashierId
                ? Shift::openForCashier($storeId, (int) $cashierId)
                : null;

            // Per-store shift enforcement (Slice A). When the store
            // requires shifts, a sale cannot post without an open shift —
            // unless the operator holds the `shifts.bypass_enforcement`
            // override (a manager ringing up without opening a till).
            // Offline replays follow the same rule: a sale that synced
            // after its shift closed surfaces in the sync log as failed,
            // which the owner can see and reconcile.
            // Back-office origins are exempt: see self::SHIFTLESS_ORIGINS.
            $needsShift = ! in_array(($header['origin'] ?? null), self::SHIFTLESS_ORIGINS, true);

            if ($needsShift && $store->enforce_shifts && ! $activeShift) {
                $actor = $user ?? auth()->user();
                if (! ($actor?->hasPermission('shifts.bypass_enforcement') ?? false)) {
                    throw new ShiftRequired();
                }
            }

            $sale = new Sale();
            $sale->fill([
                'store_id'              => $storeId,
                // Stamp the till that rang this up (Slice B). Prefer the
                // open shift's bound terminal so the sale always agrees
                // with its shift; fall back to the request's cookie-bound
                // terminal for enforcement-off / shift-less sales.
                'terminal_id'           => $activeShift?->terminal_id ?? current_terminal_id(),
                'shift_id'              => $activeShift?->id,
                'cashier_id'            => $cashierId,
                'customer_id'           => $customerId,
                'local_uuid'            => $clientUuid,
                'sale_date'             => $header['sale_date'] ?? now()->toDateString(),
                'sale_datetime'         => now(),
                'currency_code'         => (string) ($header['currency_code'] ?? $store->currency_code),
                'exchange_rate_to_base' => '1',
                'notes'                 => $header['notes'] ?? null,
                'created_by'            => $user?->id ?? auth()->id(),
            ]);
            $sale->forceFill([
                'number'          => $number,
                'status'          => Sale::STATUS_COMPLETED,
                // Where the sale came from (cashier | kiosk | …) so reports
                // can tell counter sales from self-service ones. Defaults to
                // the DB default 'cashier' when the caller doesn't pass it.
                'origin'          => in_array(($header['origin'] ?? null), self::ORIGINS, true)
                                        ? $header['origin'] : 'cashier',
                // Set when a kiosk order was pulled into the till for payment,
                // so the completed sale keeps the code the customer was given.
                'pickup_code'     => $header['pickup_code'] ?? null,
                'kiosk_note'      => $header['kiosk_note'] ?? null,
                'subtotal'        => $subtotal,
                'discount_total'  => $discountTotal,
                'discount_approved_by' => $discountApprovedBy,
                // Snapshot of the loyalty slice of that discount, for the
                // receipt and for giving the points back on a void.
                'loyalty_points_redeemed' => $redeemPoints > 0 ? $redeemPoints : 0,
                'loyalty_value_redeemed'  => $redeemValue,
                'tax_total'       => $taxTotal,
                'grand_total'     => $grandTotal,
                'paid_total'      => $sumPay,
                'balance_due'     => $balanceDue,
                'change_returned' => $this->changeReturned($payments),
            ]);
            $sale->save();

            // ── Order-level discount audit (Slice 3). One row per sale
            // when a discount was applied — records what the cashier
            // entered (type/value), the resolved amount, the optional
            // reason + category, and who applied it.
            if (bccomp($discountTotal, '0', 4) > 0) {
                SaleDiscount::create([
                    'sale_id'         => $sale->id,
                    'type'            => in_array(($header['discount_type'] ?? null), ['pct', 'amt'], true)
                                            ? $header['discount_type']
                                            : 'amt',
                    'value'           => $this->round4((string) ($header['discount_value'] ?? $discountTotal)),
                    'amount'          => $discountTotal,
                    'reason'          => $header['discount_reason'] ?? null,
                    'reason_category' => $header['discount_reason_category'] ?? null,
                    'applied_by'      => $cashierId,
                ]);
            }

            // Customer money ledgers are updated together under the same row
            // lock. Historical negative outstanding is converted once into
            // the dedicated store-credit balance before any of it is spent.
            if ($customer) {
                $nextOutstanding = (string) ($customer->outstanding_balance ?? '0');
                $nextStoreCredit = (string) ($customer->store_credit_balance ?? '0');

                if (bccomp($legacyCredit, '0', 4) > 0) {
                    $nextOutstanding = '0.0000';
                    $nextStoreCredit = bcadd($nextStoreCredit, $legacyCredit, 4);

                    $conversion = CustomerCreditTransaction::create([
                        'customer_id'          => $customer->id,
                        'store_id'             => $storeId,
                        'type'                 => CustomerCreditTransaction::TYPE_CREDIT_CONVERT,
                        'amount'               => $legacyCredit,
                        'balance_after_amount' => $nextStoreCredit,
                        'reference_type'       => Sale::class,
                        'reference_id'         => $sale->id,
                        'notes'                => __('cashier.store_credit.legacy_conversion'),
                        'created_by'           => $user?->id ?? auth()->id(),
                    ]);

                    do_action('customer_credit.converted', $conversion);
                }

                if (bccomp($storeCreditAmount, '0', 4) > 0) {
                    $nextStoreCredit = bcsub($nextStoreCredit, $storeCreditAmount, 4);

                    CustomerCreditTransaction::create([
                        'customer_id'          => $customer->id,
                        'store_id'             => $storeId,
                        'type'                 => CustomerCreditTransaction::TYPE_CREDIT_USE,
                        'amount'               => bcmul($storeCreditAmount, '-1', 4),
                        'balance_after_amount' => $nextStoreCredit,
                        'reference_type'       => Sale::class,
                        'reference_id'         => $sale->id,
                        'notes'                => __('cashier.store_credit.sale_use', ['number' => $sale->number]),
                        'created_by'           => $user?->id ?? auth()->id(),
                    ]);
                }

                if (bccomp($balanceDue, '0', 4) > 0) {
                    $nextOutstanding = bcadd($nextOutstanding, $balanceDue, 4);
                }

                $customer->forceFill([
                    'outstanding_balance'   => $nextOutstanding,
                    'store_credit_balance' => $nextStoreCredit,
                ])->save();
            }

            // ── 5. Items. Keep the created models so the stock-movement
            // loop (step 7) can iterate them directly instead of
            // re-SELECTing the rows we just inserted (code-review #20).
            $savedItems = [];
            foreach ($itemsToInsert as $row) {
                $row['sale_id'] = $sale->id;
                $savedItems[]   = SaleItem::create($row);
            }

            // ── 6. Payments.
            // The payment method is the authoritative source of the gateway
            // provider — the cashier doesn't always echo it onto the row.
            $methodProviders = $paymentMethods->pluck('provider', 'id');

            foreach ($payments as $p) {
                $methodId    = (int) $p['payment_method_id'];
                $gwPaymentId = $p['gateway_payment_id'] ?? null;
                $reference   = trim((string) ($p['reference'] ?? ''));
                // Stamp the provider when this is a gateway transaction (it has
                // a gateway reference) — from the payload, else the method.
                $gwProvider  = $p['gateway_provider'] ?? null;
                if (! $gwProvider && $gwPaymentId) {
                    $gwProvider = $methodProviders[$methodId] ?? null;
                }

                SalePayment::create([
                    'sale_id'           => $sale->id,
                    'payment_method_id' => $methodId,
                    'amount'            => $this->round4((string) $p['amount']),
                    'tendered_amount'   => isset($p['tendered_amount']) ? $this->round4((string) $p['tendered_amount']) : null,
                    'change_returned'   => isset($p['change_returned']) ? $this->round4((string) $p['change_returned']) : null,
                    'reference'         => $reference !== '' ? $reference : null,
                    'currency_code'     => (string) ($header['currency_code'] ?? $store->currency_code),
                    'gateway_provider'  => $gwProvider,
                    'gateway_payment_id'=> $gwPaymentId,
                    'gateway_signature' => $p['gateway_signature'] ?? null,
                    'gateway_status'    => $p['gateway_status']    ?? null,
                    'paid_at'           => now(),
                    'created_by'        => $user?->id ?? auth()->id(),
                ]);
            }

            if (bccomp($storeCreditAmount, '0', 4) > 0) {
                // Reserved internal method: kept out of Settings/cashier
                // method tiles and surfaced only through the balance-aware
                // customer-credit panel.
                $storeCreditMethod = PaymentMethod::query()->firstOrCreate(
                    ['code' => 'store_credit'],
                    [
                        'name'               => __('cashier.store_credit.method_name'),
                        'type'               => 'other',
                        'provider'           => 'none',
                        'requires_reference' => false,
                        'opens_cash_drawer'  => false,
                        'sort_order'          => 90,
                        'is_active'           => false,
                    ],
                );

                SalePayment::create([
                    'sale_id'           => $sale->id,
                    'payment_method_id' => $storeCreditMethod->id,
                    'amount'            => $storeCreditAmount,
                    'currency_code'     => (string) ($header['currency_code'] ?? $store->currency_code),
                    'paid_at'           => now(),
                    'created_by'        => $user?->id ?? auth()->id(),
                ]);
            }

            // ── 7. Stock movements — one per line, negative delta. Iterate
            // the models created in step 5 (nothing adds items in between),
            // so we don't re-read rows already in memory (code-review #20).
            foreach ($savedItems as $item) {
                ($this->recordMovement)(
                    storeId:       $storeId,
                    productId:     (int) $item->product_id,
                    variantId:     $item->variant_id,
                    batchId:       $item->batch_id,
                    quantityDelta: bcmul((string) $item->quantity, '-1', 4),
                    type:          'sale',
                    referenceType: Sale::class,
                    referenceId:   (int) $sale->id,
                    unitCost:      null,          // negative movements never touch WAC
                    notes:         "Sale {$sale->number}",
                    createdBy:     $user?->id ?? auth()->id(),
                );
            }

            // ── 8. Loyalty points.
            //
            // Spend first, then earn. A sale that redeems points earns on
            // its own (already-reduced) total — you don't earn points on
            // the part you paid for with points.
            if ($redeemPoints > 0) {
                ($this->redeemLoyalty)($sale, $redeemPoints, $redeemValue, $user ?? auth()->user());
            }

            // Computed server-side from the persisted sale, inside this
            // transaction — an offline sale that drains days later earns on
            // exactly the same rules as a live one. No-ops for walk-ins and
            // when the program is off.
            ($this->earnLoyalty)($sale, $user ?? auth()->user());

            do_action('sale.after_complete', $sale);

            return $sale->load(['items', 'payments']);
        });
    }

    /**
     * Returns the cash-style change-returned figure: per payment row,
     * `tendered_amount - amount` (when tendered > amount). For non-cash
     * flows the row's `tendered_amount` is null and contributes zero.
     *
     * @param array<int, array<string, mixed>> $payments
     */
    private function changeReturned(array $payments): string
    {
        $change = '0';
        foreach ($payments as $p) {
            $tend = isset($p['tendered_amount']) ? (string) $p['tendered_amount'] : null;
            $amt  = (string) ($p['amount'] ?? '0');
            if ($tend !== null && bccomp($tend, $amt, 4) > 0) {
                $change = bcadd($change, bcsub($tend, $amt, 4), 4);
            }
        }
        return $change;
    }

    /**
     * The batch this line draws from: whatever the caller picked, else an
     * automatic FEFO pick for a batch-tracked product.
     *
     * Returns null for a product that isn't batch-tracked, and for one that is
     * but has no live batch left (all quantities at zero) — the caller's
     * existing batchless handling then applies unchanged.
     *
     * The auto-pick honours the same expired-stock policy as the manual path:
     * when the company blocks expired-batch sales and this user holds no
     * `inventory.sell_expired` override, expired batches are skipped rather
     * than picked and then rejected two lines later.
     *
     * @param array<string, mixed> $raw
     */
    private function resolveBatchForLine(
        array $raw,
        Product $product,
        int $storeId,
        Company $company,
        ?User $user,
        bool $forceExpiredBlock = false,
        ?string $exceptSessionUuid = null,
    ): ?int
    {
        $given = isset($raw['batch_id']) && $raw['batch_id'] !== '' ? (int) $raw['batch_id'] : null;
        if ($given !== null) {
            $variantId = isset($raw['variant_id']) && $raw['variant_id'] !== '' ? (int) $raw['variant_id'] : null;
            $batch = ProductBatch::query()
                ->whereKey($given)
                ->where('store_id', $storeId)
                ->where('product_id', $product->id)
                ->where('variant_id', $variantId)
                ->where('quantity', '>=', (string) ($raw['quantity'] ?? '0'))
                ->lockForUpdate()
                ->first();

            if (! $batch || bccomp(
                $this->kioskReservations->availableForBatch($batch, $exceptSessionUuid),
                (string) ($raw['quantity'] ?? '0'),
                4,
            ) < 0) {
                throw new RuntimeException(__('sales.errors.invalid_batch', ['name' => $product->name]));
            }

            return $given;
        }
        if (! $product->track_batches) {
            return null;
        }

        $excludeExpired = (bool) ($company->block_expired_batch_sale ?? false)
            && ($forceExpiredBlock || ! ($user?->hasPermission('inventory.sell_expired') ?? false));

        $batch = ($this->resolveBatch)(
            $storeId,
            (int) $product->id,
            isset($raw['variant_id']) && $raw['variant_id'] !== '' ? (int) $raw['variant_id'] : null,
            $excludeExpired,
            (string) ($raw['quantity'] ?? '0'),
            true,
        );

        return $batch?->id;
    }

    /** Delegates to the ONE shared rounding (code-review #31). */
    private function round4(string $v): string
    {
        return \App\Support\Money::round($v);
    }
}
