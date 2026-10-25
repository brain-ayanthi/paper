<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\HoldSale;
use App\Actions\Sales\RecordCustomerPayment;
use App\Actions\Sales\RecordSaleReturn;
use App\Actions\Sales\ReleaseHeldReservation;
use App\Actions\Sales\VoidSale;
use App\Exceptions\DiscountAboveThreshold;
use App\Exceptions\DiscountNotAllowed;
use App\Exceptions\ExpiredBatchSale;
use App\Exceptions\InsufficientStock;
use App\Exceptions\SaleNotVoidable;
use App\Exceptions\ShiftRequired;
use App\Http\Controllers\Concerns\RendersDataTableRows;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompleteSaleRequest;
use App\Http\Requests\Admin\RefundSaleRequest;
use App\Models\Category;
use App\Models\ChannelOrderAllocation;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\PaymentMethod as PaymentMethodModel;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductLocation;
use App\Models\ProductVariant;
use App\Models\ReturnReason;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\Terminal;
use App\Services\Customers\CustomerDiscountResolver;
use App\Services\Tax\TaxResolver;
use App\Support\Denominations;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Sales — admin index/show + the cashier surfaces.
 *
 * The cashier flow is at `/cashier` (its own URL, not nested under
 * /admin) so the topbar's "Open POS" link goes somewhere recognisable.
 * Search + complete are POST/GET against /cashier/* JSON endpoints
 * consumed by the Alpine cashier factory.
 *
 * Slice 1 deliberately does NOT yet wire: customer assign, hold/resume,
 * void, return, shift gate, gateway tiles. Each lands with its own
 * slice — the controller surface stays thin so adding them doesn't
 * require refactoring.
 */
class SaleController extends Controller
{
    use RendersDataTableRows;
    use RespondsJsonOrRedirect;

    /**
     * Catalog pagination. The cashier no longer ships the whole catalog
     * in one response — large stores (1000s of SKUs) would hammer the
     * server and bloat the HTML. Instead:
     *   - the inline page render seeds a small first page for instant paint;
     *   - `/cashier/sync` returns keyset pages (`?after=<lastId>&limit=N`)
     *     the client loops in the background into IndexedDB until done.
     * Offline still works: the background loop caches the FULL catalog.
     */
    private const CATALOG_FIRST_PAGE = 60;   // inline seed for fast first paint

    private const CATALOG_PAGE_MAX = 500;  // clamp for the sync `limit` param

    /* ── Cashier surface ────────────────────────────────────────── */

    public function cashier(Request $request): View
    {
        $this->authorize('create', Sale::class);

        $payload = $this->buildCashierPayload($request);

        // Shift-gate state (Slice A). When the store enforces shifts, the
        // cashier screen blocks selling behind an open-shift modal until
        // the operator opens a shift. Users with the bypass permission can
        // dismiss it and sell without a till session.
        $user = $request->user();
        $store = $payload['store'];
        $activeShift = $user ? Shift::openForCashier((int) $store->id, (int) $user->id) : null;

        // Terminal binding (Slice B). When the store has terminals and
        // this device isn't bound to one, the gate asks the cashier to
        // pick before opening a shift. Stores with no terminals defined
        // skip this entirely (terminal_id stays null end-to-end).
        $terminals = Terminal::query()->active()->forStore((int) $store->id)->ordered()->get(['id', 'name', 'code']);
        $currentTerminal = current_terminal();
        $canBypass = $user?->hasPermission('shifts.bypass_enforcement') ?? false;

        $payload['shiftGate'] = [
            'enforce' => (bool) $store->enforce_shifts,
            'hasOpen' => $activeShift !== null,
            'canBypass' => $canBypass,
            'openUrl' => route('cashier.shift.open'),
            // The same privileged users who may sell without opening a shift
            // may also enter the POS without binding this browser to a
            // terminal. Ordinary cashiers still have to choose one.
            'needsTerminal' => ! $canBypass && $terminals->isNotEmpty() && $currentTerminal === null,
            'terminalUrl' => route('cashier.terminal.select'),
            'terminals' => $terminals->filter(fn ($t) => app(\App\Services\TerminalReservation::class)->available((int) $t->id, $request))->map(fn ($t) => [
                'id' => (int) $t->id,
                'name' => $t->name,
                'code' => $t->code,
            ])->values(),
            'currentTerminalName' => $currentTerminal?->name,
            'currentTerminalId' => $currentTerminal?->id,
            // Denomination helper (Slice C) — face values for the open step.
            'denominations' => Denominations::forActiveCurrency(),
        ];
        $payload['activeShiftId'] = $activeShift?->id;

        // Cash in/out at the till. Until now the only cash-drawer UI lived on
        // the admin shift page, so a cashier paying for a delivery out of the
        // drawer had to leave the till — and in practice just didn't, leaving
        // the shift short at close. Categories feed the pay-out's optional
        // expense booking (cash-drawer-shifts.md §4.3).
        $payload['cashDrawer'] = [
            'shiftId' => $activeShift?->id,
            'recordUrl' => $activeShift
                ? route('admin.shifts.cash-drawer.record', $activeShift)
                : null,
            // Live "cash in drawer", re-read each time the modal opens: this
            // screen doesn't reload between sales, so a figure baked in here
            // would be stale by the first cash sale. Caps the pay-out amount.
            'availableUrl' => route('admin.cash-drawer.available'),
            'canPayIn' => $user?->hasPermission('cash_drawer.pay_in') ?? false,
            'canPayOut' => $user?->hasPermission('cash_drawer.pay_out') ?? false,
            'categories' => ExpenseCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])
                ->values(),
        ];

        return view('cashier.index', $payload);
    }

    /**
     * Offline-first catalog snapshot (Slice 1).
     *
     * Returns the exact same catalog blob `cashier()` injects inline
     * but as JSON, so the client-side `catalog-refresher` can persist
     * it in IndexedDB. The client then boots from IDB on subsequent
     * loads — including offline ones — and only falls back to the
     * inline payload when IDB is cold.
     *
     * `?since=<iso>` is accepted for the future delta-sync path;
     * Slice 1 always returns the full catalog (the difference is small
     * for 500-product stores and saves a whole class of "what
     * changed?" bugs). Delta lands in a polish slice.
     */
    public function sync(Request $request): JsonResponse
    {
        $this->authorize('create', Sale::class);

        // Keyset cursor: `after` is the last product id the client already
        // has; null/absent means "first page". `limit` is clamped so a
        // crafted request can't ask for the whole catalog in one go.
        $after = $request->query('after');
        $after = ($after === null || $after === '') ? null : (int) $after;
        $limit = (int) $request->query('limit', self::CATALOG_FIRST_PAGE);
        $limit = max(1, min($limit, self::CATALOG_PAGE_MAX));

        $isFirstPage = $after === null;
        $payload = $this->buildCashierPayload($request, $after, $limit, $isFirstPage);
        $store = $payload['store'];

        $data = [
            'products' => $payload['products'],
            'next_after' => $payload['catalogCursor'],
            'has_more' => $payload['catalogHasMore'],
        ];

        // Bounded sets (categories, payment methods, settings, customers,
        // batches) ride along ONLY on the first page — they don't paginate,
        // so re-sending them on every product page would be pure waste.
        if ($isFirstPage) {
            // Recent + active customers — capped at 500 per docs §9.4. The
            // IDB-backed customer picker uses these for name/phone/code
            // search while offline; quick-add of brand-new customers is
            // online-only until Slice 4 lands the local-id remapping.
            $customers = Customer::query()
                ->where('is_active', true)
                ->orderByDesc('updated_at')
                ->limit(500)
                ->get(['id', 'code', 'name', 'phone', 'email', 'business_name', 'outstanding_balance', 'store_credit_balance', 'loyalty_points']);

            // Demo: mask contact details before they're cached in the client's
            // IndexedDB and shown in the cashier customer picker /
            // selected-customer chip. Masked here rather than on render — once
            // the payload reaches the browser it's harvestable from DevTools.
            if (pos_is_demo()) {
                $customers->each(function ($c) {
                    $c->phone = demo_mask_phone($c->phone);
                    $c->email = demo_mask_email($c->email);
                });
            }

            // Live batches for THIS store. Filter to qty > 0 because the
            // batch picker hides empties anyway, and shipping the empty
            // historical batches would balloon the payload.
            $batches = ProductBatch::query()
                ->where('store_id', $store->id)
                ->whereRaw('quantity > 0')
                ->orderBy('expiry_date')
                ->limit(2000)
                ->get(['id', 'store_id', 'product_id', 'variant_id', 'batch_number',
                    'manufacture_date', 'expiry_date', 'cost_price', 'selling_price', 'mrp', 'quantity']);

            $data['categories'] = $payload['categories'];
            $data['payment_methods'] = $payload['paymentMethods'];
            $data['settings'] = $payload['cashierSettings'];
            $data['customers'] = $customers;
            $data['batches'] = $batches->map(fn ($b) => [
                'id' => (int) $b->id,
                'store_id' => (int) $b->store_id,
                'product_id' => (int) $b->product_id,
                'variant_id' => $b->variant_id ? (int) $b->variant_id : null,
                'batch_number' => (string) $b->batch_number,
                'manufacture_date' => optional($b->manufacture_date)->toDateString(),
                'expiry_date' => optional($b->expiry_date)->toDateString(),
                'cost_price' => $b->cost_price !== null ? (string) $b->cost_price : null,
                'selling_price' => $b->selling_price !== null ? (string) $b->selling_price : null,
                'mrp' => $b->mrp !== null ? (string) $b->mrp : null,
                'on_hand' => (string) $b->quantity,
            ])->values();
        }

        return response()->json([
            'synced_at' => now()->toIso8601String(),
            'store_id' => $store->id,
            'data' => $data,
        ]);
    }

    /**
     * Lightweight ping for connectivity detection. Sub-200-byte
     * response, no auth concerns beyond an authenticated session, no
     * DB writes. Used by the offline indicator's 30s poll.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Build the cashier catalog payload — extracted from `cashier()`
     * so both the HTML render path AND the JSON `/sync` endpoint use
     * exactly the same shape. If a tile renders correctly on the
     * online page, it renders correctly from IDB; if it breaks one,
     * it breaks both.
     *
     * @return array{store: Store, cashierSettings: array, products: Collection, categories: Collection, paymentMethods: Collection}
     */
    /**
     * Quantity as a stable 4dp decimal string.
     *
     * The cashier blob is stored verbatim in IndexedDB and read back
     * offline, so every field has to look the same regardless of which
     * driver produced it — MySQL renders a DECIMAL subtraction as
     * "25.0000", SQLite as "25".
     */
    private function qty(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    private function buildCashierPayload(Request $request, ?int $after = null, int $limit = self::CATALOG_FIRST_PAGE, bool $withMeta = true): array
    {
        // Honour the session-active store (set by the topbar's store
        // switcher — same as every other admin screen). Falls back to
        // the default store, then the first active one, so a fresh
        // session still finds a valid store to ring up against.
        $storeId = active_store_id();
        $store = $storeId
            ? Store::query()->where('is_active', true)->find($storeId)
            : null;
        if (! $store) {
            $store = Store::query()->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')->firstOrFail();
        }

        // Keyset page of the active catalog, ordered by `id` so the
        // cursor is stable + index-friendly even for 10k-SKU stores. We
        // pull one extra row to detect "has more" without a count query,
        // then trim back to `$limit`. The client sorts by name for
        // display; transport order is irrelevant to the grid.
        $products = Product::query()
            ->with([
                'unit:id,code',
                'category:id,name,tax_group_id',
                'taxGroup:id,name,classification,is_inclusive,is_reverse_charge',
                'taxGroup.components:id,name,rate',
                // Category tax fallback (code-review #11): a product whose own
                // tax rule is "Use store default" inherits its category's group.
                'category.taxGroup:id,name,classification,is_inclusive,is_reverse_charge',
                'category.taxGroup.components:id,name,rate',
                // Kit components — only loaded when type=kit. Each row
                // resolves to a small `{name, sku, qty, variant_label}`
                // payload below so the tile + cart line can list what's
                // inside the bundle without an extra fetch.
                'kitItems.component:id,name,sku,unit_id',
                'kitItems.component.unit:id,code',
                'kitItems.variant:id,sku,attributes',
            ])
            // `variants_count` powers the "Sizes" tile badge — we don't
            // need the rows themselves, just whether any exist.
            ->withCount('variants')
            ->where('is_active', true)
            ->when($after !== null, fn ($q) => $q->where('id', '>', $after))
            ->orderBy('id')
            ->limit($limit + 1)
            // `reorder_level` and `scale_plu` are read by the payload mapper
            // below but were missing from this select, so both shipped as
            // permanent nulls: the tile's low-stock badge never fired at the
            // product's real threshold, and scale-printed barcodes never
            // matched a PLU (the client compares `p.scale_plu`).
            ->get(['id', 'type', 'sku', 'name', 'barcode', 'unit_id', 'category_id', 'tax_group_id', 'selling_price', 'cost_price', 'mrp', 'image_path', 'track_stock', 'track_batches', 'sold_by_weight', 'reorder_level', 'scale_plu']);

        // Shelf location at THIS store, pick face only — one bulk lookup
        // rather than an eager-load, since we only need a label per product.
        // "Where is the ibuprofen?" is the single most common question a
        // pharmacy counter gets, and the answer is now on the tile.
        $locations = ProductLocation::query()
            ->where('store_id', $store->id)
            ->whereNull('variant_id')
            ->whereIn('product_id', $products->pluck('id'))
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['product_id', 'kind', 'is_primary', 'aisle', 'rack', 'shelf', 'bin', 'note'])
            // Grouped, pick face first (the query orders by is_primary).
            // The tile shows the first; the details modal shows them all,
            // because a pharmacy SKU can be on the rack, in the fridge AND
            // in the locked cabinet at the same time.
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->map(fn (ProductLocation $l) => [
                'label' => ProductLocation::formatCoordinates($l->only(ProductLocation::COORDINATES)),
                'kind' => __('products.locations.kinds.'.$l->kind),
                'note' => $l->note ?: null,
                'is_primary' => (bool) $l->is_primary,
            ])->values()->all());

        $hasMore = $products->count() > $limit;
        if ($hasMore) {
            $products = $products->take($limit);
        }
        $catalogCursor = $products->last()?->id;

        // Available per product = on-hand minus held-ticket reservations.
        // Without subtracting `reserved_quantity`, a fully-reserved-by-hold
        // product still looks sellable in the cashier grid and the next
        // cart overcommits stock another cashier already promised.
        // Clamped at 0 so a phantom over-reservation doesn't paint a
        // negative number on the tile. Written as a CASE rather than
        // GREATEST(): SQLite has no GREATEST, so every test that touched
        // the cashier payload 500'd and the whole endpoint was effectively
        // untested. Identical semantics on MySQL.
        //
        // We track "row exists" separately from "available" so we can
        // distinguish "stock is 0" from "we've never tracked stock yet"
        // — a fresh install with no purchases shouldn't paint every tile
        // as "Out of stock".
        $onHand = StockLevel::query()
            ->where('store_id', $store->id)
            ->whereIn('product_id', $products->pluck('id'))
            ->whereNull('variant_id')
            ->selectRaw('product_id, CASE WHEN quantity - reserved_quantity > 0 THEN quantity - reserved_quantity ELSE 0 END AS available')
            ->pluck('available', 'product_id')
            // Normalise to 4dp in PHP rather than trusting the driver's
            // string formatting — this blob is cached verbatim in IndexedDB,
            // so its shape must not depend on which database produced it.
            ->map(fn ($v) => $this->qty($v));

        // Variants for products that have them. We pre-load these so the
        // variant picker can open instantly when a "Sizes" tile is tapped
        // — no extra round-trip on the hot ring-up path. Bounded by the
        // 500-product catalog cap.
        $variantParentIds = $products->where('variants_count', '>', 0)->pluck('id');
        $allVariants = $variantParentIds->isEmpty()
            ? collect()
            : ProductVariant::query()
                ->whereIn('product_id', $variantParentIds)
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id', 'product_id', 'sku', 'barcode', 'attributes', 'selling_price', 'mrp', 'image_path']);

        $variantStock = $allVariants->isEmpty()
            ? collect()
            : StockLevel::query()
                ->where('store_id', $store->id)
                ->whereIn('variant_id', $allVariants->pluck('id'))
                ->selectRaw('variant_id, CASE WHEN quantity - reserved_quantity > 0 THEN quantity - reserved_quantity ELSE 0 END AS available')
                ->pluck('available', 'variant_id')
                ->map(fn ($v) => $this->qty($v));

        $variantsByParent = $allVariants->groupBy('product_id');

        // Per-store price overrides. Same resolution rules as
        // ResolveProductPrice — variant-level overrides win over
        // product-level, which win over the base price on the product
        // row. Bulk-loaded into two keyed collections so the per-product
        // mapper below stays O(1) (vs N queries to ResolveProductPrice).
        // Empty when no overrides exist; the base prices flow through.
        $allOverrides = DB::table('product_store_prices')
            ->where('store_id', $store->id)
            ->whereIn('product_id', $products->pluck('id'))
            ->get(['product_id', 'variant_id', 'cost_price', 'selling_price', 'mrp']);
        $productOverride = $allOverrides->whereNull('variant_id')->keyBy('product_id');
        $variantOverride = $allOverrides->whereNotNull('variant_id')->keyBy('variant_id');

        // Bounded metadata — categories, payment methods, settings — is
        // sent only with the FIRST page (`$withMeta`). Subsequent keyset
        // pages are products-only, so we don't recompute it per page.
        //
        // Categories cover the WHOLE catalog (every active product's
        // category), NOT just this page — otherwise the filter chips would
        // be incomplete until the background sync finishes. The subquery
        // keeps it to one bounded lookup regardless of catalog size.
        $categories = $withMeta
            ? Category::query()
                ->where('is_active', true)
                ->whereIn('id', Product::query()
                    ->where('is_active', true)
                    ->whereNotNull('category_id')
                    ->distinct()
                    ->pluck('category_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'color'])
            : collect();

        // Cashier UI preferences — layout, tile size, tax-line visibility,
        // etc. The Blade template + Alpine factory both read these so a
        // setting tweak in /admin/settings/cashier flips the UI instantly
        // (no rebuild). Falls back to defaults when no company row exists.
        $cashierSettings = [];
        if ($withMeta) {
            $cashierSettings = (Company::current() ?? new Company)->cashier();

            // Slice 3b batch-policy flags. Bundled in with cashier settings
            // so the Alpine factory reads them from the same payload.
            $company = Company::current() ?? new Company;
            $cashierSettings['block_expired_batch_sale'] = (bool) ($company->block_expired_batch_sale ?? false);
            $cashierSettings['pricing_method'] = $company->usesBatchPricing() ? 'batch_wise' : 'product_based';
            $cashierSettings['can_sell_expired'] = (bool) ($request->user()?->hasPermission('inventory.sell_expired') ?? false);

            // Negative-stock (overselling) policy: the UI soft-gates on the
            // combined flag; CompleteSale enforces it server-side. On only
            // when the store allows it AND this user holds `sales.oversell`.
            $cashierSettings['can_oversell'] = (bool) ($cashierSettings['allow_negative_stock'] ?? false)
                && (bool) ($request->user()?->hasPermission('sales.oversell') ?? false);

            // Discount governance (Checkout discounts, Slice 1). The UI
            // soft-gates on these; CompleteSale enforces them server-side.
            $cashierSettings['can_discount'] = (bool) ($request->user()?->hasPermission('sales.discount') ?? false);
            $cashierSettings['can_discount_above_threshold'] = (bool) ($request->user()?->hasPermission('sales.discount_above_threshold') ?? false);
            $cashierSettings['discount_threshold_percent'] = (float) ($store->discount_threshold_percent ?? 100);
            $cashierSettings['discount_approval_url'] = route('cashier.discount.approve');

            // Weighing-scale barcode template — the cashier decodes scanned
            // scale barcodes into (product, weight) offline, so the config
            // rides along in the same blob that lands in IndexedDB.
            $cashierSettings['scale'] = $company->scale();

            // Loyalty program (§9.3). The cashier mirrors these rates to
            // preview a redemption; CompleteSale re-resolves it server-side
            // against the customer's real balance before spending anything.
            $cashierSettings['loyalty'] = $company->loyalty();
        }

        // In batch-wise mode the catalogue (shared by cashier and kiosk)
        // previews the same FEFO batch price that an automatic pick will use.
        // The selected batch remains authoritative and can change the line
        // price when staff deliberately choose another lot.
        $batchPrices = collect();
        $company = Company::current() ?? new Company;
        if ($company->usesBatchPricing()) {
            $excludeExpired = (bool) ($company->block_expired_batch_sale ?? false)
                && ! ($request->user()?->hasPermission('inventory.sell_expired') ?? false);
            $batchPrices = ProductBatch::query()
                ->where('store_id', $store->id)
                ->whereIn('product_id', $products->pluck('id'))
                ->whereRaw('quantity > 0')
                ->whereNotNull('selling_price')
                ->where('selling_price', '>', 0)
                ->when($excludeExpired, fn ($q) => $q->where(fn ($w) => $w
                    ->whereNull('expiry_date')
                    ->orWhereDate('expiry_date', '>=', now()->toDateString())))
                ->orderByRaw('expiry_date IS NULL')
                ->orderBy('expiry_date')
                ->orderBy('id')
                ->get(['product_id', 'variant_id', 'selling_price', 'mrp'])
                ->groupBy(fn ($b) => $b->product_id.':'.($b->variant_id ?? 0))
                ->map(fn ($rows) => $rows->first());
        }

        $productPayload = $products->map(function (Product $p) use ($onHand, $variantsByParent, $variantStock, $store, $productOverride, $variantOverride, $locations, $batchPrices) {
            // Variants for this product (already pre-grouped).
            $variants = $variantsByParent->get($p->id, collect());

            // Build the variant payload + price range for the tile.
            $variantPayload = $variants->map(function (ProductVariant $v) use ($variantStock, $p, $variantOverride, $batchPrices) {
                // Effective selling price — store-variant override
                // wins, then variant base, then parent default.
                $vo = $variantOverride->get($v->id);
                $price = $vo?->selling_price !== null
                    ? (string) $vo->selling_price
                    : ($v->selling_price !== null ? (string) $v->selling_price : (string) $p->selling_price);
                $mrpEffective = $vo?->mrp !== null
                    ? $vo->mrp
                    : ($v->mrp ?? $p->mrp);
                $mrp = $mrpEffective !== null ? (string) $mrpEffective : null;
                if ($batch = $batchPrices->get($p->id.':'.$v->id)) {
                    $price = (string) $batch->selling_price;
                    $mrp = $batch->mrp !== null ? (string) $batch->mrp : $mrp;
                }

                return [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'label' => $v->label,
                    'selling_price' => $price,
                    'mrp' => $mrp,
                    'on_hand' => $variantStock->has($v->id) ? (string) $variantStock[$v->id] : null,
                    'image_url' => $v->image_path ? '/storage/'.$v->image_path : null,
                ];
            })->values();

            // Store-level override for the product itself (variant_id IS NULL).
            $po = $productOverride->get($p->id);
            $effectiveSellingPrice = $po?->selling_price !== null
                ? (string) $po->selling_price
                : (string) $p->selling_price;
            $effectiveMrp = $po?->mrp !== null
                ? (string) $po->mrp
                : ($p->mrp !== null ? (string) $p->mrp : null);
            if ($batch = $batchPrices->get($p->id.':0')) {
                $effectiveSellingPrice = (string) $batch->selling_price;
                $effectiveMrp = $batch->mrp !== null ? (string) $batch->mrp : $effectiveMrp;
            }

            // Price range — when variants exist with explicit prices,
            // these tell the cashier "from ₹X" or "₹X – ₹Y" without
            // them having to open the picker just to see the range.
            $variantPrices = $variantPayload->pluck('selling_price')->map(fn ($s) => (float) $s);
            $priceMin = $variantPrices->isEmpty() ? null : (string) $variantPrices->min();
            $priceMax = $variantPrices->isEmpty() ? null : (string) $variantPrices->max();

            // Kit components — only populated when type=kit. Each
            // entry is `{name, sku, qty, variant_label}` so the
            // tile + cart line can render "Includes: A × 2, B × 1"
            // without hitting the API again per click.
            $kitItems = $p->type === 'kit'
                ? $p->kitItems->map(fn ($k) => [
                    'name' => (string) ($k->component?->name ?? '—'),
                    'sku' => $k->variant?->sku ?: $k->component?->sku,
                    'quantity' => (string) $k->quantity,
                    'unit' => (string) ($k->component?->unit?->code ?: 'pc'),
                    'variant_label' => $k->variant?->label,
                ])->values()
                : collect();

            // Tax preview math — let the cashier show the running
            // tax + grand total without round-tripping to the server.
            // Authoritative tax is still computed by TaxResolver in
            // CompleteSale; this just powers the display.
            // Product's own group wins; else inherit the category's
            // (code-review #11) — must mirror TaxResolver::effectiveGroup
            // so the preview matches the authoritative sale.
            $taxGroup = $p->tax_group_id !== null ? $p->taxGroup : $p->category?->taxGroup;
            $taxRateTotal = 0;
            $isInclusive = false;
            $taxable = true;
            if ($taxGroup) {
                // Mirror TaxResolver's resolveInclusive() — group's
                // `is_inclusive=true` overrides the store; otherwise
                // the store setting wins.
                $isInclusive = (bool) $taxGroup->is_inclusive
                    || (bool) ($store?->tax_inclusive_pricing ?? false);
                $taxable = ! in_array($taxGroup->classification, ['exempt', 'zero_rated', 'nil_rated'], true)
                    && ! (bool) $taxGroup->is_reverse_charge;
                if ($taxable) {
                    // Mirror TaxResolver's validity-window filter
                    // (code-review #66) so the preview rate matches
                    // what checkout actually charges.
                    $today = now()->toDateString();
                    $taxRateTotal = (float) $taxGroup->components
                        ->filter(fn ($c) => (! $c->pivot->valid_from || (string) $c->pivot->valid_from <= $today)
                            && (! $c->pivot->valid_to || (string) $c->pivot->valid_to >= $today))
                        ->sum('rate');
                }
            }

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'barcode' => $p->barcode,
                'unit' => $p->unit?->code ?: 'pc',
                'category_id' => $p->category_id,
                'tax_group_id' => $p->tax_group_id,
                'tax_rate_total' => $taxRateTotal,
                'tax_inclusive' => $isInclusive,
                'tax_taxable' => $taxable,
                'selling_price' => $effectiveSellingPrice,
                'on_hand' => $onHand->has($p->id) ? (string) $onHand[$p->id] : null,
                // Per-product reorder threshold so the cashier
                // low-stock badge fires at the right level —
                // not the hard-coded `<= 5` the UI used to default to.
                'reorder_level' => $p->reorder_level !== null ? (string) $p->reorder_level : null,
                'track_stock' => (bool) $p->track_stock,
                'track_batches' => (bool) $p->track_batches,
                'sold_by_weight' => (bool) $p->sold_by_weight,
                // Item code for weighing-scale barcodes (Settings → Scale).
                // Null for non-weighed SKUs; the scan path matches on it.
                'scale_plu' => $p->scale_plu !== null ? (int) $p->scale_plu : null,
                'mrp' => $effectiveMrp,
                'has_variants' => $variants->isNotEmpty(),
                'variant_count' => $variants->count(),
                'variants' => $variantPayload,
                'price_min' => $priceMin,
                'price_max' => $priceMax,
                'is_kit' => $p->type === 'kit',
                'kit_items' => $kitItems,
                'image_url' => $p->image_path ? '/storage/'.$p->image_path : null,
                // Shelf locations at this store. `location` is the pick
                // face (what the tile shows); `locations` is the full
                // list behind the tile's tap-through.
                //
                // Plain extra fields on the row: IndexedDB stores whole
                // objects, so these need no Dexie version bump. Terminals
                // on an older cached catalog simply lack the keys and
                // render no badge until their next sync.
                'location' => $locations->get($p->id, [])[0]['label'] ?? '',
                'locations' => $locations->get($p->id, []),
            ];
        })->values();

        $paymentMethods = $withMeta
            ? PaymentMethod::query()
                ->where('is_active', true)
                // Hide gateway-backed methods from the cashier's tile list —
                // those are reached only via the "Charge via QR" flow, where
                // the customer picks the gateway on /pay/pos/{uuid}. Showing
                // them here would let the cashier ring up a Stripe payment
                // without actually contacting Stripe.
                ->where(function ($q) {
                    $q->whereNull('provider')
                        ->orWhere('provider', '')
                        ->orWhere('provider', 'none');
                })
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'code', 'name', 'type', 'provider', 'requires_reference', 'opens_cash_drawer', 'provider_credentials'])
                ->map(function (PaymentMethod $m) use ($store) {
                    // Pull UPI VPA + payee name out of the JSON blob so the
                    // cashier JS can render the `upi://pay?…` QR code
                    // without re-parsing credentials client-side.
                    $creds = $m->provider_credentials ?? [];

                    return [
                        'id' => $m->id,
                        'code' => $m->code,
                        'name' => $m->name,
                        'type' => $m->type,
                        'provider' => $m->provider,
                        'requires_reference' => (bool) $m->requires_reference,
                        'opens_cash_drawer' => (bool) $m->opens_cash_drawer,
                        'vpa' => $creds['vpa'] ?? null,
                        // Payee name shown in the customer's UPI app.
                        // Prefer an explicit per-method override; fall
                        // back to the active store name so a fresh
                        // install reads as the local outlet without
                        // forcing the admin to fill anything in.
                        'payee_name' => $creds['payee_name']
                            ?? ($store?->name ?? config('app.name')),
                    ];
                })
            : collect();

        // Quick picks — the store's most-sold products, surfaced as tappable
        // chips above the grid. Only computed when the cashier setting is on
        // (the page ships the ids; the client maps them to loaded catalog
        // tiles, so a chip click reuses the normal add-to-cart path).
        $quickPickIds = [];
        if ($withMeta && ($cashierSettings['show_quick_picks'] ?? false)) {
            $quickPickIds = $this->quickPickIds($store->id);
        }

        return [
            'store' => $store,
            'cashierSettings' => $cashierSettings,
            'products' => $productPayload,
            'categories' => $categories,
            'paymentMethods' => $paymentMethods,
            'catalogCursor' => $catalogCursor,
            'catalogHasMore' => $hasMore,
            'quickPickIds' => $quickPickIds,
            // Everything the client-side offline receipt renderer needs to
            // compose a receipt without a server round-trip (offline-sync
            // doc §13). Only built for the full page render — the paging /
            // sync JSON responses don't need it.
            'receiptConfig' => $withMeta ? $this->buildReceiptConfig($store, $request) : null,
        ];
    }

    /**
     * The settings + labels the offline receipt renderer needs to compose a
     * receipt entirely client-side (offline-sync doc §13.1). Mirrors what
     * the server-side `sales.receipt` Blade reads off `Company` + `Store`,
     * plus a bag of pre-translated labels so the offline receipt honours the
     * active locale without an `__()` equivalent in JS.
     *
     * Bootstrapped into the cashier page on load — present for the whole
     * offline session; a cold PWA reload serves the last-cached copy, which
     * is fine since these settings change rarely.
     */
    private function buildReceiptConfig(Store $store, Request $request): array
    {
        $company = Company::current() ?? new Company;

        // Normalise to one of the three paper classes receipt.css ships
        // (`receipt-paper-58mm|80mm|a4`); anything else falls back to 80mm.
        $paper = $company->receipt_paper_size ?: '80mm';
        if (! in_array($paper, ['58mm', '80mm', 'a4'], true)) {
            $paper = '80mm';
        }

        return [
            'paper' => $paper,
            'store' => [
                'name' => $store->name,
                'address_line1' => $store->address_line1,
                'address_line2' => $store->address_line2,
                'city' => $store->city,
                'state' => $store->state,
                'postal_code' => $store->postal_code,
                'phone' => $store->phone,
            ],
            'company' => [
                'tax_registration_number' => $company->tax_registration_number,
                'display_app_name' => $company->display_app_name,
                'logo_url' => $company->receipt_show_logo ? $company->logo_url : null,
                'receipt_header' => trim((string) $company->receipt_header),
                'receipt_footer' => trim((string) $company->receipt_footer),
                'receipt_return_policy' => trim((string) $company->receipt_return_policy),
            ],
            // Show/hide toggles — same flags the Blade honours.
            'show' => [
                'logo' => (bool) $company->receipt_show_logo,
                'customer' => (bool) $company->receipt_show_customer,
                'cashier' => (bool) $company->receipt_show_cashier,
                'sku' => (bool) $company->receipt_show_sku,
                'hsn' => (bool) $company->receipt_show_hsn,
            ],
            'cashier_name' => $request->user()?->name,
            // Pre-translated so the offline receipt keeps locale + RTL parity.
            'labels' => [
                'gstin' => __('sales.receipt.gstin'),
                'customer' => __('sales.receipt.customer'),
                'cashier' => __('sales.receipt.cashier'),
                'sku' => __('sales.receipt.sku'),
                'hsn' => __('sales.receipt.hsn'),
                'tax' => __('sales.receipt.tax'),
                'tax_incl' => __('sales.totals.tax_incl_suffix'),
                'subtotal' => __('sales.receipt.subtotal'),
                'discount' => __('sales.receipt.discount'),
                // Kept as the raw :points placeholder — the renderer fills
                // it in with the count from the offline snapshot.
                'points_redeemed' => __('sales.receipt.points_redeemed', ['points' => ':points']),
                'tax_included' => __('sales.receipt.tax_already_included'),
                'grand_total' => __('sales.receipt.grand_total'),
                'tendered' => __('sales.receipt.tendered'),
                'change' => __('sales.receipt.change'),
                'provisional' => __('sales.receipt.provisional_note'),
            ],
        ];
    }

    /**
     * The store's most-sold product ids over the last 90 days (completed
     * sales only), top first — drives the cashier "Quick picks" chip row.
     *
     * @return list<int>
     */
    private function quickPickIds(int $storeId, int $limit = 12): array
    {
        try {
            return SaleItem::query()
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->join('products', 'products.id', '=', 'sale_items.product_id')
                ->where('sales.store_id', $storeId)
                ->where('sales.status', Sale::STATUS_COMPLETED)
                ->where('sales.sale_date', '>=', now()->subDays(90)->toDateString())
                ->where('products.is_active', true)
                ->whereNull('products.deleted_at')
                ->groupBy('sale_items.product_id')
                ->orderByRaw('SUM(sale_items.quantity) DESC')
                ->limit($limit)
                ->pluck('sale_items.product_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * JSON product picker for the scan/search bar.
     * Resolves in this order (per docs §4.2):
     *   1. Exact barcode match (products.barcode)
     *   2. Exact SKU match
     *   3. Fuzzy name match
     * Returns up to 25 rows with the columns the cashier UI consumes.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('create', Sale::class);

        $q = trim((string) $request->query('q', ''));

        // Same store resolution as buildCashierPayload (code-review #49):
        // the session's active store via the request-cached current_store()
        // — no extra SELECT per keystroke — falling back to the default-ish
        // first active store. The old code always used the fallback, so a
        // multi-store cashier's search results were priced/stocked against
        // a DIFFERENT store than the catalog tiles they sat next to.
        $store = current_store();
        if (! $store || ! $store->is_active) {
            $store = Store::query()->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')->firstOrFail();
        }

        $query = Product::query()
            ->with(['unit:id,code', 'taxGroup:id,name,classification'])
            ->where('is_active', true);

        if ($q === '') {
            // Empty query → recent / popular catalog (Slice 1: just the
            // first 25 active products. Recents drawer is Slice 2 polish.)
            $rows = $query->orderBy('name')->limit(25)->get();
        } else {
            $rows = $query
                ->where(function ($w) use ($q) {
                    $w->where('barcode', $q)
                        ->orWhere('sku', $q)
                        ->orWhere('name', 'like', "%{$q}%");
                })
                ->orderByRaw(
                    // Exact-match rows surface first.
                    'CASE WHEN barcode = ? THEN 0 WHEN sku = ? THEN 1 ELSE 2 END',
                    [$q, $q]
                )
                ->orderBy('name')
                ->limit(25)
                ->get();
        }

        // Decorate with available stock for the active store so the cart
        // can disable adds when the negative-stock policy blocks them.
        // Available = on-hand minus held-ticket reservations — see the
        // same subtraction in the boot payload above.
        $levels = StockLevel::query()
            ->where('store_id', $store->id)
            ->whereIn('product_id', $rows->pluck('id'))
            ->whereNull('variant_id')
            ->selectRaw('product_id, CASE WHEN quantity - reserved_quantity > 0 THEN quantity - reserved_quantity ELSE 0 END AS available')
            ->pluck('available', 'product_id');

        // Honour per-store selling-price overrides — same pattern as
        // buildCashierPayload above. Without this, the search-bar add
        // path would silently fall back to the product's base price
        // and ring up a different number than the catalog tile.
        $overrides = DB::table('product_store_prices')
            ->where('store_id', $store->id)
            ->whereIn('product_id', $rows->pluck('id'))
            ->whereNull('variant_id')
            ->get(['product_id', 'selling_price'])
            ->keyBy('product_id');

        // Shelf location for the same `$store` this endpoint already prices
        // and stocks against, so every field on a result row describes one
        // store — the same one buildCashierPayload resolves (code-review #49).
        $locations = ProductLocation::query()
            ->where('store_id', $store->id)
            ->whereNull('variant_id')
            ->whereIn('product_id', $rows->pluck('id'))
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['product_id', 'kind', 'is_primary', 'aisle', 'rack', 'shelf', 'bin', 'note'])
            ->groupBy('product_id')
            ->map(fn ($r) => $r->map(fn (ProductLocation $l) => [
                'label' => ProductLocation::formatCoordinates($l->only(ProductLocation::COORDINATES)),
                'kind' => __('products.locations.kinds.'.$l->kind),
                'note' => $l->note ?: null,
                'is_primary' => (bool) $l->is_primary,
            ])->values()->all());

        return response()->json($rows->map(fn (Product $p) => [
            'location' => $locations->get($p->id, [])[0]['label'] ?? '',
            'locations' => $locations->get($p->id, []),
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'barcode' => $p->barcode,
            'unit' => $p->unit?->code,
            'selling_price' => $overrides->get($p->id)?->selling_price !== null
                ? (string) $overrides->get($p->id)->selling_price
                : (string) $p->selling_price,
            'tax_group_id' => $p->tax_group_id,
            'on_hand' => $this->qty($levels[$p->id] ?? 0),
            // Same fields the boot payload exposes so search-added
            // tiles also paint the low/out badge correctly and route to
            // the batch / weight pickers.
            'track_stock' => (bool) $p->track_stock,
            'track_batches' => (bool) $p->track_batches,
            'sold_by_weight' => (bool) $p->sold_by_weight,
            'reorder_level' => $p->reorder_level !== null ? (string) $p->reorder_level : null,
        ])->values());
    }

    /**
     * Batch picker — return live batches for a given (product, variant)
     * in the active store. Sorted FEFO (first-expiring first), with
     * never-expiring batches at the end so the cashier picks
     * date-sensitive stock first. Only batches with quantity > 0 are
     * returned; the picker never offers an empty batch.
     */
    public function batches(Request $request): JsonResponse
    {
        $this->authorize('create', Sale::class);

        $productId = (int) $request->query('product_id', 0);
        $variantId = $request->query('variant_id');
        $variantId = ($variantId === null || $variantId === '') ? null : (int) $variantId;

        if ($productId <= 0) {
            return response()->json([]);
        }

        $storeId = (int) $request->session()->get('active_store_id', 0);
        if ($storeId <= 0) {
            $store = Store::query()->where('is_active', true)->orderBy('id')->first();
            $storeId = (int) ($store?->id ?? 0);
        }

        $rows = ProductBatch::query()
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
            ->when($variantId === null, fn ($q) => $q->whereNull('variant_id'))
            ->whereRaw('quantity > 0')
            ->orderByRaw('expiry_date IS NULL')   // nulls last
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'batch_number', 'manufacture_date', 'expiry_date',
                'cost_price', 'selling_price', 'mrp', 'quantity']);

        return response()->json($rows->map(fn ($b) => [
            'id' => (int) $b->id,
            'batch_number' => (string) $b->batch_number,
            'manufacture_date' => optional($b->manufacture_date)->toDateString(),
            'expiry_date' => optional($b->expiry_date)->toDateString(),
            'on_hand' => (string) $b->quantity,
            'cost_price' => $b->cost_price !== null ? (string) $b->cost_price : null,
            'selling_price' => $b->selling_price !== null ? (string) $b->selling_price : null,
            'mrp' => $b->mrp !== null ? (string) $b->mrp : null,
        ])->values());
    }

    /**
     * Customer picker — search by name / code / phone / business name.
     * Returns up to 25 rows in the shape the cashier-page Alpine factory
     * expects. Open to anyone who can ring up a sale (no separate
     * Customer::viewAny permission needed — the cashier inherently sees
     * customers when assigning them to a sale).
     */
    public function customerSearch(Request $request): JsonResponse
    {
        $this->authorize('create', Sale::class);

        $q = trim((string) $request->query('q', ''));

        $rows = Customer::query()
            ->where('is_active', true)
            ->when($q !== '', function ($qb) use ($q) {
                $qb->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('business_name', 'like', "%{$q}%");
                });
            })
            ->with('group:id,name,default_discount_percent')
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'code', 'name', 'phone', 'business_name', 'outstanding_balance', 'store_credit_balance', 'default_discount_percent', 'customer_group_id', 'loyalty_points']);

        $discounts = app(CustomerDiscountResolver::class);

        return response()->json($rows->map(function (Customer $c) use ($discounts) {
            // RESOLVED, not raw: a customer with no percent of their own
            // inherits their group's. Resolving here (and again in
            // CompleteSale) is what makes a change to a group's rate reach
            // every member without re-saving them.
            $discount = $discounts->resolve($c);

            return [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'phone' => demo_mask_phone($c->phone),
                'business_name' => $c->business_name,
                'outstanding_balance' => (string) $c->outstanding_balance,
                'store_credit_balance' => (string) $c->store_credit_balance,
                // Auto-applied as the order discount when this customer is picked
                // in the cashier (sales-checkout doc §"Assigning a customer").
                'default_discount_percent' => $discount['percent'],
                'discount_source' => $discount['source'],
                'group_name' => $discount['group_name'],
                // Drives the "Redeem points" panel in the payment modal.
                'loyalty_points' => (int) ($c->loyalty_points ?? 0),
            ];
        })->values());
    }

    /**
     * Quick-add a customer from the cashier modal. Only `name` is
     * required — phone is optional but recommended. The full
     * /admin/customers form is still the place for B2B / GST / addresses;
     * this endpoint is intentionally minimal so the cashier never has to
     * leave the ring-up flow.
     *
     * Permission: gated by Sale::create (same as the cashier itself), not
     * Customer::create — the cashier role typically has the former but
     * not necessarily the latter, and we don't want a missing permission
     * to block a sale.
     */
    public function quickAddCustomer(Request $request, CreateCustomer $create): JsonResponse
    {
        $this->authorize('create', Sale::class);

        // Idempotency by the offline queue's local_uuid (code-review #65):
        // a replay whose first attempt landed but whose response was lost
        // must get the SAME customer back — not a 422 on the unique
        // phone/email, which parked the queue entry as failed forever and
        // blocked its dependent sale ("waiting for customer to sync").
        $localUuid = trim((string) $request->input('local_uuid', '')) ?: null;
        if ($localUuid !== null) {
            $existing = Customer::query()->where('local_uuid', $localUuid)->first();
            if ($existing) {
                return $this->quickAddCustomerResponse($existing);
            }
        }

        // Normalise BEFORE validation so the uniqueness check compares the
        // same digits-with-`+` / lower-cased forms the action stores
        // (mirrors the admin customer form). Soft-deleted rows are excluded
        // — CreateCustomer reclaims a trashed identifier before inserting.
        $email = trim((string) $request->input('email', ''));
        $request->merge([
            'phone' => PhoneNormalizer::normalize($request->input('phone')),
            'email' => $email === '' ? null : strtolower($email),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('customers', 'phone')->whereNull('deleted_at')],
            'email' => ['nullable', 'email:rfc', 'max:191', Rule::unique('customers', 'email')->whereNull('deleted_at')],
        ], [], [
            'phone' => __('customers.fields.phone'),
            'email' => __('customers.fields.email'),
        ]);

        try {
            $customer = $create([
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'local_uuid' => $localUuid,
                'is_active' => true,
                'is_business' => false,
            ], [], $request->user());
        } catch (UniqueConstraintViolationException $e) {
            // Two replays of the same uuid raced past the lookup — the
            // unique index stopped the duplicate; return the winner.
            $existing = $localUuid !== null
                ? Customer::query()->where('local_uuid', $localUuid)->first()
                : null;
            if (! $existing) {
                throw $e;
            }
            $customer = $existing;
        }

        return $this->quickAddCustomerResponse($customer);
    }

    /**
     * Same shape as the search endpoint so the cashier can treat a
     * just-created (or replay-deduped) customer exactly like a picked
     * one. A quick-add carries no group today, so the discount resolves
     * to 0 — but it goes through the resolver anyway, so it keeps
     * working the day new customers start landing in a default group.
     */
    private function quickAddCustomerResponse(Customer $customer): JsonResponse
    {
        $discount = app(CustomerDiscountResolver::class)->resolve($customer);

        return response()->json([
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'phone' => demo_mask_phone($customer->phone),
            'business_name' => $customer->business_name,
            'outstanding_balance' => (string) $customer->outstanding_balance,
            'store_credit_balance' => (string) $customer->store_credit_balance,
            'default_discount_percent' => $discount['percent'],
            'discount_source' => $discount['source'],
            'group_name' => $discount['group_name'],
            'loyalty_points' => (int) ($customer->loyalty_points ?? 0),
        ]);
    }

    /**
     * Park the current cart as a held sale. The cashier UI clears its
     * local state on success — the server is now the source of truth
     * for the held ticket until it's resumed or voided.
     */
    public function holdCart(Request $request, HoldSale $hold): JsonResponse|RedirectResponse
    {
        $this->authorize('create', Sale::class);

        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'held_label' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'discount' => ['nullable', 'array'],
            'discount.type' => ['nullable', 'string', 'in:pct,amt'],
            'discount.value' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $sale = $hold($data, $request->user());
        } catch (RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect(
            $request,
            __('sales.flash_held.parked', ['number' => $sale->number]),
            route('cashier.index'),
            extra: ['sale' => ['id' => $sale->id, 'number' => $sale->number]],
        );
    }

    /**
     * List currently-held sales for the active store. Newest first.
     * Used by the cashier "Held tickets" drawer.
     */
    public function heldList(Request $request): JsonResponse
    {
        $this->authorize('create', Sale::class);

        $storeId = active_store_id();
        if (! $storeId) {
            return response()->json([]);
        }

        $rows = Sale::query()
            ->with(['customer:id,name', 'cashier:id,name', 'items:id,sale_id'])
            ->where('store_id', $storeId)
            ->where('status', Sale::STATUS_HELD)
            ->orderByDesc('held_at')
            ->limit(50)
            ->get(['id', 'number', 'customer_id', 'cashier_id', 'held_label', 'held_at', 'subtotal', 'grand_total']);

        return response()->json($rows->map(fn (Sale $s) => [
            'id' => $s->id,
            'number' => $s->number,
            'label' => $s->held_label,
            'customer' => $s->customer?->name,
            'cashier' => $s->cashier?->name,
            'item_count' => $s->items->count(),
            'grand_total' => (string) $s->grand_total,
            'held_at' => $s->held_at?->toIso8601String(),
        ])->values());
    }

    /**
     * Resume a held sale — returns its items + customer so the cashier
     * UI can load them into the local cart, then deletes the held row
     * (the new completed sale will get a fresh SALE- number at checkout).
     *
     * Returning + deleting in one round-trip avoids a half-resumed state
     * where the cashier sees the items locally but the server still
     * thinks they're held (an open invitation to double-billing).
     */
    public function resumeHeld(
        Request $request,
        Sale $sale,
        ReleaseHeldReservation $release,
        TaxResolver $taxResolver,
    ): JsonResponse
    {
        $this->authorize('create', Sale::class);
        // `create` is a class-level ability, so it carries no record scope —
        // without this a cashier could resume any store's held cart by id
        // (SEC #96).
        abort_unless_store_access($sale->store_id);

        if ($sale->status !== Sale::STATUS_HELD) {
            return response()->json(['message' => 'Not a held sale.'], 422);
        }

        $sale->load([
            'store',
            'items.product:id,sku,name,unit_id,tax_group_id,category_id',
            'items.product.unit:id,code',
            'items.product.taxGroup.components',
            'items.product.category.taxGroup.components',
            'items.variant:id,sku,attributes',
            'customer:id,name,phone,business_name,outstanding_balance,store_credit_balance,loyalty_points',
        ]);

        $payload = [
            'id' => $sale->id,
            'number' => $sale->number,
            'customer' => $sale->customer ? [
                'id' => $sale->customer->id,
                'name' => $sale->customer->name,
                'phone' => $sale->customer->phone,
                'outstanding_balance' => (string) $sale->customer->outstanding_balance,
                'store_credit_balance' => (string) $sale->customer->store_credit_balance,
                'loyalty_points' => (int) $sale->customer->loyalty_points,
            ] : null,
            // Order-level discount, exactly as the cashier had it at hold
            // time. Null if no discount was set. The JS hydrates it into
            // `this.discount` so the resumed cart matches what was held.
            'discount' => $sale->held_discount,
            'items' => $sale->items->map(function ($item) use ($sale, $taxResolver) {
                $product = $item->product;
                $tax = $product
                    ? $taxResolver->resolveForLine(
                        $product,
                        (string) $item->unit_price,
                        (string) $item->quantity,
                        store: $sale->store,
                    )
                    : null;

                return [
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'name' => $product?->name,
                    'sku' => $item->variant?->sku ?: $product?->sku,
                    'unit' => $product?->unit?->code,
                    'unit_price' => (string) $item->unit_price,
                    'quantity' => (string) $item->quantity,
                    'tax_group_id' => $product ? $taxResolver->effectiveTaxGroupId($product) : null,
                    'tax_rate_total' => $tax?->rateTotal ?? '0.0000',
                    'tax_inclusive' => $tax?->isInclusive ?? false,
                    'tax_taxable' => $tax !== null
                        && ! in_array($tax->classification, ['exempt', 'zero_rated', 'nil_rated', 'reverse_charge'], true),
                    'variant_label' => $item->variant?->label,
                ];
            })->values(),
        ];

        // Release the stock reservation BEFORE force-deleting — once
        // the sale row is gone, the action can't find the items to
        // know what to decrement.
        $release($sale);

        // Delete the held row + its items so the same hold can't be
        // resumed twice. Force-delete (not soft) — held sales never
        // need an audit trail; if it wasn't completed, it never
        // happened.
        $sale->items()->delete();
        $sale->forceDelete();

        return response()->json($payload);
    }

    /**
     * Void a held sale — same as resuming except we don't return the
     * items (cashier wants to discard, not load back).
     */
    public function voidHeld(Request $request, Sale $sale, ReleaseHeldReservation $release): JsonResponse|RedirectResponse
    {
        $this->authorize('create', Sale::class);
        // Same class-level-ability gap as `resumeHeld` (SEC #96) — this one
        // force-deletes the cart, so it destroys another store's held sale.
        abort_unless_store_access($sale->store_id);

        if ($sale->status !== Sale::STATUS_HELD) {
            return $this->jsonOrError($request, 'Not a held sale.');
        }

        $number = $sale->number;

        // Release reservations BEFORE force-deleting — same ordering
        // reason as `resumeHeld`.
        $sale->load('items');
        $release($sale);

        // Snapshot the freed lines before deletion so the cashier UI can
        // bump local `on_hand` back without a page refresh. Each entry
        // is the minimum the JS needs to find the matching tile/variant.
        $freed = $sale->items->map(fn ($item) => [
            'product_id' => (int) $item->product_id,
            'variant_id' => $item->variant_id ? (int) $item->variant_id : null,
            'quantity' => (string) $item->quantity,
        ])->values();

        $sale->items()->delete();
        $sale->forceDelete();

        return $this->jsonOrRedirect(
            $request,
            __('sales.flash_held.voided', ['number' => $number]),
            route('cashier.index'),
            extra: ['freed_items' => $freed],
        );
    }

    /**
     * Complete a sale — the POST endpoint the cashier hits when the
     * cashier finalises payment. The Action does the heavy lifting; the
     * controller just translates the form payload + exceptions.
     */
    public function complete(CompleteSaleRequest $request, CompleteSale $complete): JsonResponse|RedirectResponse
    {
        $this->authorize('create', Sale::class);

        // Slice 4 — capture every cashier completion attempt for the
        // admin sync log. Payload + result + server-assigned id all
        // recorded against the client-generated `local_uuid` so:
        //   - online sales still show up (the cashier always sends
        //     local_uuid, even online — it's the idempotency key)
        //   - offline-then-synced sales are visible without the owner
        //     having to grep the server log
        //   - retries `updateOrCreate` onto the same row, no fan-out
        $localUuid = (string) $request->input('local_uuid', '');

        try {
            $sale = $complete(
                $request->headerData(),
                $request->lineData(),
                $request->paymentData(),
                $request->user(),
            );
        } catch (InsufficientStock $e) {
            $this->writeSyncLog($request, $localUuid, SyncLog::RESULT_CONFLICT,
                'Insufficient stock for "'.$e->productName.'".');

            return $this->jsonOrError(
                $request,
                __('sales.errors.insufficient_stock', [
                    'name' => $e->productName,
                    'available' => $e->available,
                    'requested' => $e->requested,
                ]),
            );
        } catch (ExpiredBatchSale $e) {
            $this->writeSyncLog($request, $localUuid, SyncLog::RESULT_CONFLICT, $e->getMessage());

            return $this->jsonOrError(
                $request,
                __('sales.errors.expired_batch_sale', [
                    'name' => $e->productName,
                    'batch' => $e->batchNumber,
                    'expiry' => $e->expiryDate,
                ]),
            );
        } catch (ShiftRequired $e) {
            $this->writeSyncLog($request, $localUuid, SyncLog::RESULT_FAILED, $e->getMessage());

            return $this->jsonOrError($request, $e->getMessage());
        } catch (DiscountNotAllowed|DiscountAboveThreshold $e) {
            $this->writeSyncLog($request, $localUuid, SyncLog::RESULT_FAILED, $e->getMessage());

            return $this->jsonOrError($request, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->writeSyncLog($request, $localUuid, SyncLog::RESULT_FAILED, $e->getMessage());

            return $this->jsonOrError($request, $e->getMessage());
        }

        $this->writeSyncLog($request, $localUuid, SyncLog::RESULT_SUCCESS, null, (int) $sale->id);

        return $this->jsonOrRedirect(
            $request,
            __('sales.flash.completed', ['number' => $sale->number]),
            route('admin.sales.show', $sale),
            extra: [
                'sale' => [
                    'id' => $sale->id,
                    'number' => $sale->number,
                    'grand_total' => (string) $sale->grand_total,
                    'change_returned' => (string) $sale->change_returned,
                    // No-login receipt link for the customer display's
                    // thank-you QR (and later WhatsApp / email receipts).
                    'public_receipt_url' => $sale->publicReceiptUrl(),
                ],
            ],
        );
    }

    /**
     * Persist one row to `sync_logs` for the cashier-complete endpoint.
     * `updateOrCreate` by `local_uuid` so a retry from the sync engine
     * collapses onto the same row instead of duplicating. Silently
     * no-ops when the request didn't carry a local_uuid (defensive —
     * the cashier always sends one in practice).
     */
    private function writeSyncLog(
        CompleteSaleRequest $request,
        string $localUuid,
        string $result,
        ?string $message,
        ?int $syncedEntityId = null,
    ): void {
        if ($localUuid === '') {
            return;
        }

        SyncLog::query()->updateOrCreate(
            ['local_uuid' => $localUuid],
            [
                'user_id' => $request->user()?->id,
                'entity' => 'sale',
                'payload' => $request->all(),
                'result' => $result,
                'result_message' => $message,
                'synced_entity_id' => $syncedEntityId,
                'synced_at' => now(),
            ],
        );
    }

    /* ── Admin surface ──────────────────────────────────────────── */

    /**
     * Sales list — server-paginated. Only the first page renders inline; the
     * filters (status / method / customer / date range / search) and paging all
     * round-trip to {@see rows()}. The filter form is Alpine self-managed
     * (`salesIndexPage`), so it drives the mixin instead of inv-filter-ajax.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Sale::class);

        $perPage = $this->dtPerPage($request);
        $paginator = $this->listQuery($request)->paginate($perPage);
        $summary = $this->summaryFor($request);

        $customerId = $request->integer('customer_id') ?: null;
        // Label for the currently-filtered customer so the remoteSelect can
        // render the chip without a fetch on initial load.
        $customer = $customerId ? Customer::query()->find($customerId, ['id', 'name', 'phone', 'code']) : null;
        $customerLabel = $customer
            ? $customer->name.($customer->phone ? ' · '.$customer->phone : ($customer->code ? ' · '.$customer->code : ''))
            : '';

        return view('admin.sales.index', [
            'sales' => $paginator->getCollection(),
            'total' => $paginator->total(),
            'perPage' => $perPage,
            'totalPages' => max(1, $paginator->lastPage()),
            'summaryCards' => $this->summaryCards($summary),
            'methods' => PaymentMethodModel::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'status' => (string) $request->query('status', 'all'),
                'paymentMethodId' => $request->integer('payment_method_id') ?: null,
                'customerId' => $customerId,
                'customerLabel' => $customerLabel,
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'q' => trim((string) $request->query('q', '')),
            ],
        ]);
    }

    /** One page of sale rows as an HTML fragment plus summary meta. */
    public function rows(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        $paginator = $this->listQuery($request)
            ->paginate($this->dtPerPage($request), ['*'], 'page', $this->dtPage($request));

        return $this->dtRows($paginator, 'admin.sales._rows', 'sales', [
            'summary' => $this->summaryFor($request),
        ]);
    }

    /**
     * The active filter set. Store scoping follows the Purchases / Supplier
     * Payments convention — the topbar switcher picks which store's sales show.
     * Shared by the row query and the summary aggregate.
     *
     * @return Builder<Sale>
     */
    private function filteredBase(Request $request): Builder
    {
        $status = (string) $request->query('status', 'all');
        $paymentMethodId = $request->integer('payment_method_id') ?: null;
        $customerId = $request->integer('customer_id') ?: null;
        $storeId = resolved_store_id($request);
        $from = $request->query('from');
        $to = $request->query('to');
        $q = trim((string) $request->query('q', ''));

        return Sale::query()
            ->when($storeId, fn ($x) => $x->where('store_id', $storeId))
            ->when($status !== 'all', fn ($x) => $x->where('status', $status))
            ->when($from, fn ($x) => $x->whereDate('sale_datetime', '>=', $from))
            ->when($to, fn ($x) => $x->whereDate('sale_datetime', '<=', $to))
            ->when($paymentMethodId, fn ($x) => $x->whereHas('payments', fn ($p) => $p->where('payment_method_id', $paymentMethodId)))
            ->when($customerId, fn ($x) => $x->where('customer_id', $customerId))
            ->when($q !== '', function ($x) use ($q) {
                $x->where(function ($w) use ($q) {
                    $w->where('number', 'like', "%{$q}%")
                      // A kiosk customer knows one thing: the pickup code printed
                      // big on the kiosk screen. Staff must be able to type it here.
                        ->orWhere('pickup_code', 'like', "%{$q}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%"))
                        ->orWhereHas('cashier', fn ($c) => $c->where('name', 'like', "%{$q}%"))
                      // Match on products sold — the sale_items snapshot columns,
                      // so it works even if the product was renamed/deleted.
                        ->orWhereHas('items', fn ($i) => $i->where('product_name_snapshot', 'like', "%{$q}%")
                            ->orWhere('sku_snapshot', 'like', "%{$q}%"));
                });
            });
    }

    /** @return Builder<Sale> */
    private function listQuery(Request $request): Builder
    {
        return $this->filteredBase($request)
            ->with([
                'store:id,name,code',
                'customer:id,name',
                'cashier:id,name',
                'payments.paymentMethod:id,name,type',
                'returns:id,sale_id,gateway_refund_status',
            ])
            ->orderByDesc('sale_datetime')
            ->orderByDesc('id');
    }

    /**
     * Summary-card totals over the FULL filtered set (not just the page shown),
     * as display-ready strings for the JS to drop into the cards.
     *
     * @return array{total:string, transactions:string, items:string, avg:string}
     */
    private function summaryFor(Request $request): array
    {
        $agg = $this->filteredBase($request)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as total')
            ->first();
        $cnt = (int) ($agg->cnt ?? 0);
        $total = (string) ($agg->total ?? '0');
        $items = (string) (SaleItem::query()
            ->whereIn('sale_id', $this->filteredBase($request)->select('sales.id'))
            ->sum('quantity') ?: '0');
        $avg = $cnt > 0 ? bcdiv($total, (string) $cnt, 4) : '0';
        $trimQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') ?: '0';

        return [
            'total' => format_money($total),
            'transactions' => number_format($cnt),
            'items' => $trimQty($items),
            'avg' => format_money($avg),
        ];
    }

    /**
     * @param  array{total:string, transactions:string, items:string, avg:string}  $summary
     * @return array<int, array<string, mixed>>
     */
    private function summaryCards(array $summary): array
    {
        return [
            ['label' => __('sales.summary.total'),        'value' => $summary['total'],        'key' => 'total'],
            ['label' => __('sales.summary.transactions'), 'value' => $summary['transactions'], 'key' => 'transactions'],
            ['label' => __('sales.summary.items'),        'value' => $summary['items'],        'key' => 'items'],
            ['label' => __('sales.summary.avg'),          'value' => $summary['avg'],          'key' => 'avg'],
        ];
    }

    public function show(Sale $sale): View
    {
        $this->authorize('view', $sale);

        $sale->load([
            'store:id,name,code',
            'customer:id,name,phone,email,outstanding_balance',
            'cashier:id,name',
            // `type` so the sale show view can render the kit components
            // panel under any kit line. The kitItems chain pulls the
            // component product + optional variant in one query each.
            'items.product:id,sku,name,hsn_code,unit_id,type',
            'items.product.unit:id,code',
            'items.product.kitItems.component:id,sku,name,unit_id',
            'items.product.kitItems.component.unit:id,code',
            'items.product.kitItems.variant:id,sku,attributes',
            'items.variant:id,sku',
            'items.batch:id,batch_number,expiry_date',
            'payments.paymentMethod:id,name,type',
            'returns:id,sale_id,number,return_date,grand_total,status',
            'returns.reason:id,name',
            'saleDiscount.appliedBy:id,name',
            'discountApprover:id,name',
        ]);

        return view('admin.sales.show', [
            'sale' => $sale,
            'channelManaged' => ChannelOrderAllocation::query()->where('sale_id', $sale->getKey())->exists(),
            'paymentMethods' => PaymentMethodModel::query()
                ->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'type']),
        ]);
    }

    /**
     * Show the "process refund" form for a completed (or partially
     * refunded) sale. Lists each line with a remaining-qty input the
     * cashier can clamp; reason + refund-to + restock all default to
     * sensible values so a one-click full refund needs no edits.
     */
    public function refundForm(Sale $sale): View
    {
        $this->authorize('refund', $sale);

        abort_if(
            ChannelOrderAllocation::query()->where('sale_id', $sale->getKey())->exists(),
            409,
            __('sales.channel_managed'),
        );

        if (! in_array($sale->status, [Sale::STATUS_COMPLETED, Sale::STATUS_PARTIALLY_REFUNDED], true)) {
            abort(403, __('sales.refund.not_refundable'));
        }

        $sale->load([
            'store:id,name,code',
            'cashier:id,name',
            'items.product:id,sku,name',
            'items.variant:id,sku,attributes',
            'payments.paymentMethod:id,name,type,code',
        ]);

        // Refund tenders are limited to the methods actually used on the
        // sale — you can't refund a Stripe charge via Razorpay, or hand back
        // cash for a card sale (gateway refunds reverse the original charge).
        // An unpaid credit sale has no tenders, so fall back to the full
        // active list for its refund-as-credit case.
        $methods = $sale->refundMethods();
        if ($methods->isEmpty()) {
            $methods = PaymentMethodModel::query()->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name', 'type']);
        }

        // Default refund method: the original tender if there was one, else cash.
        $originalMethodId = $sale->payments->first()?->payment_method_id;
        $cashMethodId = PaymentMethodModel::query()->where('type', 'cash')->where('is_active', true)->value('id');

        return view('admin.sales.refund', [
            'sale' => $sale,
            'reasons' => ReturnReason::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'methods' => $methods,
            'defaultMethodId' => $originalMethodId ?: $cashMethodId,
        ]);
    }

    /**
     * Process the refund — delegates to RecordSaleReturn for all the
     * stock + tax + status mechanics. Surfaces the action's
     * RuntimeException as a 422 toast for the AJAX client; non-AJAX
     * falls back to a `back()->with('error')`.
     */
    public function refundStore(RefundSaleRequest $request, Sale $sale, RecordSaleReturn $record): JsonResponse|RedirectResponse
    {
        $this->authorize('refund', $sale);

        if (ChannelOrderAllocation::query()->where('sale_id', $sale->getKey())->exists()) {
            return $this->jsonOrError($request, __('sales.channel_managed'), route('admin.sales.show', $sale));
        }

        try {
            $refund = $record(
                array_merge($request->refundData($sale->id), [
                    'client_uuid' => $request->input('client_uuid'),
                ]),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage(), route('admin.sales.refund.form', $sale));
        }

        return $this->jsonOrRedirect(
            $request,
            __('sales.refund.flash.created', ['number' => $refund->number]),
            route('admin.sales.show', $sale),
            extra: ['refund' => ['id' => $refund->id, 'number' => $refund->number]],
        );
    }

    /**
     * Record a customer payment against the sale's outstanding balance.
     * Validates against the sale's balance_due (no over-pay) and bumps
     * the customer's outstanding_balance down. The action handles the
     * lock + invariants; we just shape the response.
     */
    public function recordPayment(Request $request, Sale $sale, RecordCustomerPayment $record): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $sale);

        $data = $request->validate([
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => [
                Rule::requiredIf(fn () => PaymentMethod::query()
                    ->whereKey($request->integer('payment_method_id'))
                    ->where('requires_reference', true)
                    ->exists()),
                'nullable', 'string', 'max:255',
            ],
        ]);

        try {
            $record($sale, $data, $request->user());
        } catch (RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage(), route('admin.sales.show', $sale));
        }

        return $this->jsonOrRedirect(
            $request,
            __('sales.flash.payment_recorded'),
            route('admin.sales.show', $sale),
        );
    }

    /**
     * Void a completed sale — reverses stock + customer balance + shift
     * cash math. The action's eligibility guards translate to a 422
     * with the localised reason; success redirects back to the sale
     * show page so the cashier sees the voided badge.
     */
    public function void(Request $request, Sale $sale, VoidSale $void): JsonResponse|RedirectResponse
    {
        $this->authorize('void', $sale);

        if (ChannelOrderAllocation::query()->where('sale_id', $sale->getKey())->exists()) {
            return $this->jsonOrError($request, __('sales.channel_managed'), route('admin.sales.show', $sale));
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $void($sale, $data['reason'] ?? null, $request->user());
        } catch (SaleNotVoidable $e) {
            return $this->jsonOrError($request, $e->getMessage(), route('admin.sales.show', $sale));
        }

        return $this->jsonOrRedirect(
            $request,
            __('sales.flash.voided', ['number' => $sale->number]),
            route('admin.sales.show', $sale),
        );
    }

    /**
     * Customer-facing receipt. Three CSS modes (58mm / 80mm / A4)
     * driven by `company.receipt_paper_size`; what's actually printed
     * is controlled by the `receipt_show_*` toggles configured at
     * /admin/settings/receipt. The view stands alone (no admin shell)
     * so `?print=1` can window.print() immediately on load.
     */
    public function receipt(Request $request, Sale $sale): View
    {
        $this->authorize('view', $sale);

        $sale->load([
            // `tax_registration_number` lives on `company`, not `stores`
            // — the receipt blade reads it from `$company` below.
            'store:id,name,code,address_line1,address_line2,city,state,postal_code,phone',
            'customer:id,name,phone,email',
            'cashier:id,name',
            'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            // Kit `type` + `kitItems` so the receipt prints the bundle
            // contents inline. Matches what the show view also loads.
            'items.product:id,sku,name,hsn_code,unit_id,type',
            'items.product.unit:id,code',
            'items.product.kitItems.component:id,sku,name,unit_id',
            'items.product.kitItems.component.unit:id,code',
            'items.product.kitItems.variant:id,sku,attributes',
            'items.variant:id,sku,attributes',
            'items.batch:id,batch_number,expiry_date',
            'payments.paymentMethod:id,name,type',
        ]);

        $company = Company::current() ?? new Company;

        return view('sales.receipt', [
            'sale' => $sale,
            'company' => $company,
            // Honour the configured paper size; default to 80mm thermal
            // since that's the most common thermal-printer width.
            'paper' => $company->receipt_paper_size ?: '80mm',
            // Auto-print when `?print=1` is in the URL (success overlay's
            // Print button uses this; "View receipt" omits it).
            'auto' => $request->boolean('print'),
            // Mint (or reuse) the sale's public-receipt URL only when the QR
            // is switched on — no point creating a link the receipt won't show.
            'receiptUrl' => $company->receipt_show_qr ? $sale->publicReceiptUrl() : null,
        ]);
    }

    /**
     * "Reprint last receipt" — finds the most recent completed sale by
     * the current cashier (or any cashier in the current store if the
     * current user has no recent one), then redirects to its receipt.
     * Wired to the overflow menu's stub on the cashier screen.
     */
    public function reprintLast(Request $request): RedirectResponse
    {
        $this->authorize('create', Sale::class);

        $storeId = active_store_id();
        $sale = Sale::query()
            ->where('status', Sale::STATUS_COMPLETED)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->where('cashier_id', $request->user()->id)
            ->orderByDesc('id')
            ->first()
            ?? Sale::query()
                ->where('status', Sale::STATUS_COMPLETED)
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->orderByDesc('id')
                ->first();

        if (! $sale) {
            return redirect()->route('cashier.index')->with('error', __('cashier.overflow.no_recent'));
        }

        return redirect()->route('admin.sales.receipt', ['sale' => $sale, 'print' => 1]);
    }

    /**
     * Recent completed/refunded sales for the cashier's "Recent sales"
     * drawer — 20 newest in the active store, no filtering UI (browse-
     * only; refund/return has its own dedicated lookup endpoint that
     * walks the same data but is permission-gated to `sales.refund`).
     */
    public function recentsList(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        $storeId = active_store_id();

        $rows = Sale::query()
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->whereIn('status', [Sale::STATUS_COMPLETED, Sale::STATUS_PARTIALLY_REFUNDED, Sale::STATUS_REFUNDED])
            ->with('customer:id,name')
            ->orderByDesc('sale_datetime')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'number', 'sale_datetime', 'grand_total', 'status', 'customer_id']);

        return response()->json($rows->map(fn (Sale $s) => [
            'id' => $s->id,
            'number' => $s->number,
            'sale_datetime' => optional($s->sale_datetime)->toIso8601String(),
            'grand_total' => (string) $s->grand_total,
            'status' => $s->status,
            'customer' => $s->customer?->name,
        ])->values());
    }
}
