<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Suppliers\CreateSupplier;
use App\Actions\Suppliers\DeleteSupplier;
use App\Actions\Suppliers\ExportSuppliers;
use App\Actions\Suppliers\UpdateSupplier;
use App\Exceptions\SupplierHasBalance;
use App\Http\Controllers\Concerns\RendersDataTableRows;
use App\Http\Controllers\Concerns\ResolvesExportFormat;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SupplierRequest;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Services\Suppliers\GenerateSupplierStatement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Suppliers admin — CRUD per `docs/features/suppliers-purchases.md` §3.
 *
 * The Show page is the unified supplier view: one page, four tabs —
 * Overview, Purchases, Statement, Payments — mirroring the customer
 * view. There's no Addresses tab because a supplier's address is flat
 * columns on the row, not a `customer_addresses`-style table, so it
 * belongs in Overview.
 */
class SupplierController extends Controller
{
    use RendersDataTableRows;
    use ResolvesExportFormat;
    use RespondsJsonOrRedirect;

    /**
     * Rows loaded per history tab on the Show page. These tabs paginate
     * client-side (the whole set ships with the page), so the cap keeps a
     * long-standing supplier from rendering thousands of `<tr>`s.
     */
    private const TAB_ROW_LIMIT = 200;

    /**
     * Sortable table columns → their SQL column. Keys match the `sortBy()`
     * arguments on the table headers and the `dt-toolbar-actions` sort menu.
     */
    private const SORT_COLUMNS = [
        'name'        => 'name',
        'code'        => 'code',
        'outstanding' => 'outstanding_balance',
        'status'      => 'is_active',
        'id'          => 'id',
    ];

    /**
     * Suppliers list — server-paginated. Only the first page is rendered inline;
     * search, the status filter, sort and paging round-trip to {@see rows()}.
     * The generic `customersIndexPage` factory boots with filters cleared, so the
     * inline page is unfiltered and default-sorted (newest first).
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Supplier::class);

        $perPage  = $this->dtPerPage($request);
        $defaults = new Request(['per_page' => $perPage]);

        $paginator = $this->listQuery($defaults)->paginate($perPage);
        $summary   = $this->summaryFor($defaults);

        return view('admin.suppliers.index', [
            'suppliers'    => $paginator->getCollection(),
            'perPage'      => $perPage,
            'total'        => $paginator->total(),
            'totalPages'   => max(1, $paginator->lastPage()),
            'summaryCards' => $this->summaryCards($summary),
        ]);
    }

    /**
     * One page of supplier rows as an HTML fragment plus pager + summary meta.
     * See {@see RendersDataTableRows}.
     */
    public function rows(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $paginator = $this->listQuery($request)
            ->paginate($this->dtPerPage($request), ['*'], 'page', $this->dtPage($request));

        return $this->dtRows($paginator, 'admin.suppliers._rows', 'suppliers', [
            'summary' => $this->summaryFor($request),
        ]);
    }

    /**
     * Active search + filter set, no ordering. Shared by the row query and the
     * summary aggregate so the cards describe the exact set on screen.
     *
     * @return Builder<Supplier>
     */
    private function filteredBase(Request $request): Builder
    {
        $query = Supplier::query();

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('code', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhere('business_name', 'like', "%{$q}%")
                ->orWhere('contact_person', 'like', "%{$q}%"));
        }

        $status = (string) $request->query('status', 'all');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        return $query;
    }

    /** @return Builder<Supplier> */
    private function listQuery(Request $request): Builder
    {
        $query = $this->filteredBase($request);

        $this->applySort($query, $request);

        return $query;
    }

    /** @param Builder<Supplier> $query */
    private function applySort(Builder $query, Request $request): void
    {
        $column = (string) $request->query('sort', '');
        $dir    = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (isset(self::SORT_COLUMNS[$column])) {
            $query->orderBy(self::SORT_COLUMNS[$column], $dir);
            $query->orderBy('id', 'desc');

            return;
        }

        // Default (no active sort): newest suppliers first.
        $query->orderByDesc('id');
    }

    /**
     * Summary-card totals honoring the active filters. One aggregate query,
     * values arriving display-ready for the JS to drop into the cards.
     *
     * @return array{total:string, payable:string, active:string}
     */
    private function summaryFor(Request $request): array
    {
        $row = $this->filteredBase($request)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COALESCE(SUM(outstanding_balance), 0) AS payable_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_count')
            ->first();

        return [
            'total'   => number_format((int) ($row->total_count ?? 0)),
            'payable' => format_money($row->payable_sum ?? 0),
            'active'  => number_format((int) ($row->active_count ?? 0)),
        ];
    }

    /**
     * `key` ties each card's value to its DOM node so the index factory can
     * rewrite it from the `summary` every rows() response carries.
     *
     * @param  array{total:string, payable:string, active:string}  $summary
     * @return array<int, array<string, mixed>>
     */
    private function summaryCards(array $summary): array
    {
        return [
            ['label' => __('suppliers.summary.total'),   'value' => $summary['total'],   'key' => 'total'],
            ['label' => __('suppliers.summary.payable'), 'value' => $summary['payable'], 'key' => 'payable', 'tone' => 'warning'],
            ['label' => __('suppliers.summary.active'),  'value' => $summary['active'],  'key' => 'active', 'tone' => 'positive'],
        ];
    }

    public function create(): View
    {
        $this->authorize('create', Supplier::class);

        $supplier = new Supplier([
            'is_active' => true,
        ]);

        return view('admin.suppliers.edit', $this->editorPayload($supplier));
    }

    public function store(SupplierRequest $request, CreateSupplier $create): JsonResponse|RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = $create($request->supplierData(), $request->user());

        return $this->jsonOrRedirect(
            $request,
            __('suppliers.flash.created', ['name' => $supplier->name]),
            route('admin.suppliers.index'),
        );
    }

    /**
     * The unified supplier view. Every tab's data is resolved here and
     * shipped with the page — the tabs are a client-side reveal, not four
     * round-trips.
     */
    public function show(Request $request, Supplier $supplier, GenerateSupplierStatement $statement): View
    {
        $this->authorize('view', $supplier);

        $supplier->load('creator:id,name');

        // Tab visibility (SEC #102). `suppliers.view` used to be the whole
        // gate, after which the page loaded and inlined every purchase,
        // return, payment and the running-balance statement — `purchases.view`
        // is a separate permission that was never consulted, and neither was
        // the store boundary. A tab the user can't see must not have its data
        // loaded at all; hiding the button still leaves the rows in the source.
        $user            = $request->user();
        $canSeePurchases = $user?->can('viewAny', Purchase::class) ?? false;
        $canSeePayments  = $user?->can('viewAny', PurchasePayment::class) ?? false;
        // The statement is the purchases + payments ledger, so it needs both.
        $canSeeStatement = $canSeePurchases && $canSeePayments;

        // Open POs (balance_due > 0, not cancelled) — drives the "Open
        // purchases" drill-down on Overview. Oldest first so the most
        // overdue rises to the top.
        $openPurchases = ! $canSeePurchases ? collect() : $this->scopedPurchases($user)
            ->where('supplier_id', $supplier->id)
            ->whereRaw('balance_due > 0')
            ->where('status', '!=', Purchase::STATUS_CANCELLED)
            ->orderBy('purchase_date')
            ->orderBy('id')
            ->limit(self::TAB_ROW_LIMIT)
            ->get(['id', 'number', 'purchase_date', 'due_date', 'grand_total', 'paid_total', 'balance_due', 'status']);

        // ── Purchases tab ──────────────────────────────────────────
        // Cancelled POs stay in the list (unlike the statement, which
        // pretends they never happened) — "what did we order from them?"
        // wants the cancellation visible, badged as such. Drafts are not
        // commitments yet, so they stay off the supplier's history.
        $purchases = ! $canSeePurchases ? collect() : $this->scopedPurchases($user)
            ->where('supplier_id', $supplier->id)
            ->where('status', '!=', Purchase::STATUS_DRAFT)
            ->with('store:id,name')
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->limit(self::TAB_ROW_LIMIT)
            ->get(['id', 'store_id', 'number', 'supplier_invoice_number', 'purchase_date', 'grand_total', 'paid_total', 'balance_due', 'status']);

        $returns = ! $canSeePurchases ? collect() : PurchaseReturn::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('purchase_id', $this->scopedPurchases($user)->select('id'))
            ->where('status', PurchaseReturn::STATUS_POSTED)
            ->with('purchase:id,number')
            ->orderByDesc('return_date')
            ->orderByDesc('id')
            ->limit(self::TAB_ROW_LIMIT)
            ->get(['id', 'purchase_id', 'number', 'return_date', 'grand_total', 'status']);

        // ── Payments tab ───────────────────────────────────────────
        // Voided rows are kept and badged: the owner needs to see that a
        // payment was entered and reversed, not have it vanish. The
        // statement excludes them; this ledger does not.
        $payments = ! $canSeePayments ? collect() : PurchasePayment::query()
            ->where('supplier_id', $supplier->id)
            ->when(
                $user && ! $user->is_super_admin,
                fn ($q) => $q->whereIn('store_id', $user->accessibleStoreIds()),
            )
            ->with(['purchase:id,number', 'paymentMethod:id,name'])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->limit(self::TAB_ROW_LIMIT)
            ->get();

        // ── Header KPIs ────────────────────────────────────────────
        // Lifetime spend excludes cancelled POs and nets off returns, so
        // it means "what this supplier has actually been worth in trade".
        // Same gate as the Purchases tab — this is that money, totalled.
        $lifetime = ! $canSeePurchases ? null : $this->scopedPurchases($user)
            ->where('supplier_id', $supplier->id)
            ->where('status', '!=', Purchase::STATUS_CANCELLED)
            ->selectRaw('COALESCE(SUM(grand_total), 0) AS gross')
            ->selectRaw('COUNT(*) AS purchase_count')
            ->selectRaw('MAX(purchase_date) AS last_purchase_date')
            ->first();

        $returned = ! $canSeePurchases ? 0 : PurchaseReturn::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('purchase_id', $this->scopedPurchases($user)->select('id'))
            ->where('status', PurchaseReturn::STATUS_POSTED)
            ->sum('grand_total');

        // Default window (last 3 months).
        $ledger = $canSeeStatement ? $statement($supplier) : null;

        return view('admin.suppliers.show', [
            'supplier'         => $supplier,
            'openPurchases'    => $openPurchases,
            'purchases'        => $purchases,
            'returns'          => $returns,
            'payments'         => $payments,
            'ledger'           => $ledger,
            'rowLimit'         => self::TAB_ROW_LIMIT,
            'canSeePurchases'  => $canSeePurchases,
            'canSeePayments'   => $canSeePayments,
            'canSeeStatement'  => $canSeeStatement,
            'lifetimeSpend'    => bcsub((string) ($lifetime->gross ?? '0'), (string) ($returned ?: '0'), 4),
            'purchaseCount'    => (int) ($lifetime->purchase_count ?? 0),
            'lastPurchaseDate' => $lifetime->last_purchase_date ?? null,
        ]);
    }

    /**
     * A Purchase query narrowed to what this user may see (SEC #102) — the
     * query form of {@see \App\Policies\PurchasePolicy::view()}, whose store
     * boundary the supplier page bypassed entirely.
     */
    private function scopedPurchases(?\App\Models\User $user): \Illuminate\Database\Eloquent\Builder
    {
        return Purchase::query()->when(
            $user && ! $user->is_super_admin,
            fn ($q) => $q->whereIn('store_id', $user->accessibleStoreIds()),
        );
    }

    public function edit(Supplier $supplier): View
    {
        $this->authorize('update', $supplier);

        return view('admin.suppliers.edit', $this->editorPayload($supplier));
    }

    public function update(SupplierRequest $request, Supplier $supplier, UpdateSupplier $update): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $supplier);

        $update($supplier, $request->supplierData(), $request->user());

        return $this->jsonOrRedirect(
            $request,
            __('suppliers.flash.updated', ['name' => $supplier->name]),
            route('admin.suppliers.index'),
        );
    }

    /**
     * AJAX flip of `is_active` without opening the edit page. Mirrors
     * the customers + products pattern.
     */
    public function toggleActive(Supplier $supplier, Request $request): JsonResponse
    {
        $this->authorize('update', $supplier);

        $willDeactivate = $supplier->is_active;
        if ($willDeactivate) {
            // Same guard as DeleteSupplier — open purchases keep the
            // supplier "in use", so they can't be silently retired
            // mid-flow. Activating doesn't need this check.
            $openCount = \App\Models\Purchase::query()
                ->where('supplier_id', $supplier->getKey())
                ->whereIn('status', [
                    \App\Models\Purchase::STATUS_DRAFT,
                    \App\Models\Purchase::STATUS_SUBMITTED,
                    \App\Models\Purchase::STATUS_RECEIVED,
                    \App\Models\Purchase::STATUS_PARTIALLY_PAID,
                ])
                ->count();
            if ($openCount > 0) {
                return response()->json([
                    'ok'      => false,
                    'message' => __('suppliers.errors.has_open_purchases', ['name' => $supplier->name, 'count' => $openCount]),
                ], 422);
            }
        }

        $supplier->update([
            'is_active'  => ! $supplier->is_active,
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json([
            'ok'        => true,
            'is_active' => (bool) $supplier->is_active,
            'message'   => __($supplier->is_active ? 'suppliers.flash.activated' : 'suppliers.flash.deactivated', ['name' => $supplier->name]),
        ]);
    }

    public function destroy(Request $request, Supplier $supplier, DeleteSupplier $delete): JsonResponse|RedirectResponse
    {
        $this->authorize('delete', $supplier);

        $name = $supplier->name;
        try {
            $delete($supplier);
        } catch (SupplierHasBalance $e) {
            return $this->jsonOrError(
                $request,
                __('suppliers.errors.has_balance', ['amount' => format_money($e->amount)]),
                route('admin.suppliers.show', $supplier),
            );
        } catch (\App\Exceptions\SupplierHasOpenPurchases $e) {
            return $this->jsonOrError(
                $request,
                __('suppliers.errors.has_open_purchases', ['name' => $name, 'count' => $e->count]),
                route('admin.suppliers.show', $supplier),
            );
        }

        return $this->jsonOrRedirect(
            $request,
            __('suppliers.flash.deleted', ['name' => $name]),
            route('admin.suppliers.index'),
        );
    }

    public function export(Request $request, ExportSuppliers $export): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $format = $this->exportFormat($request);

        return ($export)($format);
    }

    /** @return array<string, mixed> */
    private function editorPayload(Supplier $supplier): array
    {
        return [
            'supplier'       => $supplier,
            'countries'      => \App\Support\Countries::all(),
            'currencies'     => \Illuminate\Support\Facades\DB::table('currencies')
                                    ->orderBy('code')->get(['code', 'name', 'symbol']),
            'defaultCountry' => \App\Models\Company::current()?->country_code
                                ?? \App\Models\Store::query()->value('country_code')
                                ?? 'IN',
            'baseCurrency'   => \App\Models\Company::current()?->base_currency_code ?? 'USD',
        ];
    }
}
