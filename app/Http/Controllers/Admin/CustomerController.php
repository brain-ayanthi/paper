<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\DeleteCustomer;
use App\Actions\Customers\ExportCustomers;
use App\Actions\Customers\UpdateCustomer;
use App\Exceptions\CustomerHasBalance;
use App\Http\Controllers\Concerns\RendersDataTableRows;
use App\Http\Controllers\Concerns\ResolvesExportFormat;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerRequest;
use App\Models\Customer;
use App\Models\CustomerCreditTransaction;
use App\Models\CustomerGroup;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\Store;
use App\Services\Customers\GenerateCustomerStatement;
use App\Services\Loyalty\LoyaltyCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customers admin — full CRUD per `docs/features/customers.md` §6–7.
 *
 * The Show page is the unified customer view (`docs/features/customers.md`
 * §7.2): one page, five tabs — Overview, Purchases, Statement, Payments,
 * Addresses. The remaining spec'd tabs (Store credit ledger, Loyalty
 * ledger, Activity) stay out until something actually writes to
 * `customer_credit_transactions` / `audit_logs`; a tab that can only ever
 * render an empty state is worse than no tab. The credit and loyalty
 * *balances* still surface as KPIs in the header.
 */
class CustomerController extends Controller
{
    use RendersDataTableRows;
    use ResolvesExportFormat;
    use RespondsJsonOrRedirect;

    /**
     * Rows loaded per history tab on the Show page. These tabs paginate
     * client-side (the whole set ships with the page), so the cap keeps a
     * ten-year customer from rendering thousands of `<tr>`s. Each tab links
     * out to its own fully-paginated screen when the cap bites.
     */
    private const TAB_ROW_LIMIT = 200;

    /**
     * Sortable table columns → their SQL column. Keys match the `sortBy()`
     * arguments on the table headers and the `dt-toolbar-actions` sort menu.
     * Anything else falls back to the default (newest first) in {@see applySort()}.
     */
    private const SORT_COLUMNS = [
        'name'        => 'name',
        'code'        => 'code',
        'outstanding' => 'outstanding_balance',
        'credit'      => 'store_credit_balance',
        'status'      => 'is_active',
        'id'          => 'id',
    ];

    /**
     * Typeahead source for the `remoteSelect` customer picker (e.g. the
     * Sales History customer filter). Returns `[{value, label}]`, matching
     * the products.search contract. Searches name / phone / code.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $q = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->when($q !== '', fn ($qb) => $qb->where(fn ($w) =>
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('phone', 'like', "%{$q}%")
                  ->orWhere('code', 'like', "%{$q}%")
            ))
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'phone', 'code']);

        return response()->json(
            $customers->map(fn (Customer $c) => [
                'value' => (string) $c->id,
                'label' => $c->name.($c->phone ? ' · '.$c->phone : ($c->code ? ' · '.$c->code : '')),
            ])->all()
        );
    }

    /**
     * Customers list — server-paginated.
     *
     * Only the first page is rendered inline; every search, filter, sort and
     * page round-trips to {@see rows()}. `customersIndexPage` boots with the
     * filters cleared, so the inline page is deliberately unfiltered and
     * default-sorted (newest first) — only `per_page` is read off the request.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $perPage  = $this->dtPerPage($request);
        $defaults = new Request(['per_page' => $perPage]);

        $paginator = $this->listQuery($defaults)->paginate($perPage);
        $summary   = $this->summaryFor($defaults);

        return view('admin.customers.index', [
            'customers'    => $paginator->getCollection(),
            'perPage'      => $perPage,
            // Unfiltered here, so it doubles as the "N of GRAND" toolbar total
            // and the "you have no customers at all" empty-state check.
            'total'        => $paginator->total(),
            'totalPages'   => max(1, $paginator->lastPage()),
            'summary'      => $summary,
            'summaryCards' => $this->summaryCards($summary),
            'groups'       => CustomerGroup::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * One page of customer rows as an HTML fragment, plus the pager + summary
     * metadata the `dataTableServer` mixin needs. See {@see RendersDataTableRows}.
     */
    public function rows(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $paginator = $this->listQuery($request)
            ->paginate($this->dtPerPage($request), ['*'], 'page', $this->dtPage($request));

        return $this->dtRows($paginator, 'admin.customers._rows', 'customers', [
            'summary' => $this->summaryFor($request),
        ]);
    }

    /**
     * Active search + filter set, with no eager loads and no ordering. Shared by
     * the row query and the summary aggregate so the cards always describe the
     * exact set on screen.
     *
     * @return Builder<Customer>
     */
    private function filteredBase(Request $request): Builder
    {
        $query = Customer::query();

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('code', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhere('business_name', 'like', "%{$q}%"));
        }

        $status = (string) $request->query('status', 'all');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $groupId = (int) $request->query('group_id', 0);
        if ($groupId > 0) {
            $query->where('customer_group_id', $groupId);
        }

        return $query;
    }

    /** @return Builder<Customer> */
    private function listQuery(Request $request): Builder
    {
        $query = $this->filteredBase($request)->with('group:id,name');

        $this->applySort($query, $request);

        return $query;
    }

    /** @param Builder<Customer> $query */
    private function applySort(Builder $query, Request $request): void
    {
        $column = (string) $request->query('sort', '');
        $dir    = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (isset(self::SORT_COLUMNS[$column])) {
            $query->orderBy(self::SORT_COLUMNS[$column], $dir);
            // Deterministic tiebreak so rows sharing a sort key can't reshuffle
            // between pages (a customer appearing twice, or never).
            $query->orderBy('id', 'desc');

            return;
        }

        // Default (no active sort): newest first — system-wide DESC default for
        // tables without a manual `sort_order` column (see memory
        // table-and-form-conventions).
        $query->orderByDesc('id');
    }

    /**
     * Summary-card totals honoring the active filters. One aggregate query —
     * never a count() over a hydrated collection. Values arrive display-ready so
     * the JS can drop them straight into the cards on every rows() response.
     *
     * @return array{total:string, with_balance:string, outstanding:string}
     */
    private function summaryFor(Request $request): array
    {
        $row = $this->filteredBase($request)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN outstanding_balance > 0 THEN 1 ELSE 0 END), 0) AS with_balance_count')
            ->selectRaw('COALESCE(SUM(outstanding_balance), 0) AS outstanding_sum')
            ->first();

        return [
            'total'        => number_format((int) ($row->total_count ?? 0)),
            'with_balance' => number_format((int) ($row->with_balance_count ?? 0)),
            'outstanding'  => format_money($row->outstanding_sum ?? 0),
        ];
    }

    /**
     * `key` ties each card's value to its DOM node so `customersIndexPage` can
     * rewrite it from the `summary` every rows() response carries.
     *
     * @param  array{total:string, with_balance:string, outstanding:string}  $summary
     * @return array<int, array<string, mixed>>
     */
    private function summaryCards(array $summary): array
    {
        return [
            ['label' => __('customers.summary.total'),        'value' => $summary['total'],        'key' => 'total'],
            ['label' => __('customers.summary.with_balance'), 'value' => $summary['with_balance'], 'key' => 'with_balance'],
            ['label' => __('customers.summary.outstanding'),  'value' => $summary['outstanding'],  'key' => 'outstanding', 'tone' => 'warning'],
        ];
    }

    public function create(): View
    {
        $this->authorize('create', Customer::class);

        $customer = new Customer([
            'is_active'   => true,
            'is_business' => false,
        ]);
        $customer->setRelation('addresses', collect());

        return view('admin.customers.edit', $this->editorPayload($customer));
    }

    public function store(CustomerRequest $request, CreateCustomer $create): JsonResponse|RedirectResponse
    {
        $this->authorize('create', Customer::class);

        $customer = $create($request->customerData(), $request->addressData(), $request->user());

        return $this->jsonOrRedirect(
            $request,
            __('customers.flash.created', ['name' => $customer->name]),
            route('admin.customers.index'),
        );
    }

    /**
     * The unified customer view. Every tab's data is resolved here and
     * shipped with the page — the tabs are a client-side reveal, not five
     * round-trips, so switching between them is instant on the shared
     * hosting this product targets.
     */
    public function show(Request $request, Customer $customer, GenerateCustomerStatement $statement): View
    {
        $this->authorize('view', $customer);

        $customer->load(['group', 'firstStore', 'addresses', 'creator:id,name']);

        // Tab visibility (SEC #102). `customers.view` used to be the whole
        // gate: the page then loaded and inlined this customer's sales,
        // returns, payments and running-balance statement regardless of
        // whether the role could open any of those screens. A tab the user
        // can't see must not have its data loaded at all — hiding the button
        // alone still ships the rows in the page source.
        $user       = $request->user();
        $canSeeSales    = $user?->can('viewAny', Sale::class) ?? false;
        $canSeePayments = $user?->can('viewAny', SalePayment::class) ?? false;
        // The statement is the sales + payments ledger, so it needs both.
        $canSeeStatement = $canSeeSales && $canSeePayments;
        $quotationAllStoreIds = collect($user?->accessibleStoreIds() ?? [])
            ->filter(fn (int $storeId) => $user->hasPermission('quotations.view_all', $storeId))
            ->values()
            ->all();
        $quotationOwnStoreIds = collect($user?->accessibleStoreIds() ?? [])
            ->reject(fn (int $storeId) => in_array($storeId, $quotationAllStoreIds, true))
            ->filter(fn (int $storeId) => $user->hasPermission('quotations.view_own', $storeId))
            ->values()
            ->all();
        $canSeeQuotations = $quotationAllStoreIds !== [] || $quotationOwnStoreIds !== [];

        $quotations = ! $canSeeQuotations ? collect() : Quotation::query()
            ->where('customer_id', $customer->id)
            ->where(function (Builder $query) use ($quotationAllStoreIds, $quotationOwnStoreIds, $user) {
                $query->whereIn('store_id', $quotationAllStoreIds)
                    ->orWhere(fn (Builder $own) => $own
                        ->whereIn('store_id', $quotationOwnStoreIds)
                        ->where('created_by', $user->id));
            })
            ->with('store:id,name')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(self::TAB_ROW_LIMIT)
            ->get();

        // Open sales (balance_due > 0, not voided) — drives the
        // "Open sales" drill-down card on the Overview tab. Sorted
        // oldest first so the most-overdue rises to the top.
        $openSales = ! $canSeeSales ? collect() : $this->scopedSales($user)
            ->where('customer_id', $customer->id)
            ->whereRaw('balance_due > 0')
            ->where('status', '!=', Sale::STATUS_VOIDED)
            ->orderBy('sale_date')
            ->orderBy('id')
            ->limit(self::TAB_ROW_LIMIT)
            ->get(['id', 'number', 'sale_date', 'currency_code', 'grand_total', 'paid_total', 'balance_due', 'status']);

        // ── Purchases tab ──────────────────────────────────────────
        // Voided sales are kept in the list (unlike the statement, which
        // pretends they never happened) — the owner asking "what did this
        // customer buy?" wants to see the void too, badged as such.
        $sales = ! $canSeeSales ? collect() : $this->scopedSales($user)
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [Sale::STATUS_DRAFT, Sale::STATUS_HELD])
            ->with('store:id,name')
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->limit(self::TAB_ROW_LIMIT)
            ->get(['id', 'store_id', 'number', 'sale_date', 'grand_total', 'paid_total', 'balance_due', 'status']);

        $returns = ! $canSeeSales ? collect() : SaleReturn::query()
            ->whereHas('sale', fn (Builder $q) => $q->where('customer_id', $customer->id))
            ->whereIn('sale_id', $this->scopedSales($user)->select('id'))
            ->where('status', '!=', SaleReturn::STATUS_DRAFT)
            ->with('sale:id,number')
            ->orderByDesc('return_date')
            ->orderByDesc('id')
            ->limit(self::TAB_ROW_LIMIT)
            ->get(['id', 'sale_id', 'number', 'return_date', 'grand_total', 'status']);

        // ── Payments tab ───────────────────────────────────────────
        // Two shapes live in `sale_payments`: tenders taken at the till
        // (sale_id set) and standalone settlements against the customer's
        // balance (sale_id NULL, customer_id set). Both belong here.
        $payments = ! $canSeePayments ? collect() : SalePayment::query()
            ->where(fn (Builder $q) => $q
                ->where('customer_id', $customer->id)
                ->orWhereHas('sale', fn (Builder $s) => $s->where('customer_id', $customer->id)))
            ->with(['sale:id,number', 'paymentMethod:id,name'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit(self::TAB_ROW_LIMIT)
            ->get();

        // ── Header KPIs ────────────────────────────────────────────
        // Lifetime value excludes voids (a reversed bill was never spend)
        // and nets off refunds, so the number means "what this customer is
        // actually worth to the store", not "what we once invoiced".
        // Same gate as the Purchases tab — this is the same money, totalled.
        $lifetime = ! $canSeeSales ? null : $this->scopedSales($user)
            ->where('customer_id', $customer->id)
            ->where('status', '!=', Sale::STATUS_VOIDED)
            ->selectRaw('COALESCE(SUM(grand_total), 0) AS gross')
            ->selectRaw('COUNT(*) AS sale_count')
            ->selectRaw('MAX(sale_date) AS last_sale_date')
            ->first();

        $refunded = ! $canSeeSales ? 0 : SaleReturn::query()
            ->whereHas('sale', fn (Builder $q) => $q->where('customer_id', $customer->id))
            ->whereIn('sale_id', $this->scopedSales($user)->select('id'))
            ->where('status', '!=', SaleReturn::STATUS_DRAFT)
            ->sum('grand_total');

        // ── Loyalty tab ────────────────────────────────────────────
        // Shown when the programme is on OR the customer has history —
        // turning loyalty off must not hide points they already hold.
        $loyaltyRows = CustomerCreditTransaction::query()
            ->where('customer_id', $customer->id)
            ->points()
            ->with('creator:id,name')
            ->orderByDesc('id')
            ->limit(self::TAB_ROW_LIMIT)
            ->get();

        // ── Statement tab ──────────────────────────────────────────
        // Default window (last 3 months). The dedicated statement screen
        // owns date filtering, print and email; this tab is the at-a-glance
        // copy with a link across to it.
        $ledger = $canSeeStatement ? $statement($customer) : null;

        return view('admin.customers.show', [
            'customer'      => $customer,
            'openSales'     => $openSales,
            'sales'         => $sales,
            'returns'       => $returns,
            'payments'      => $payments,
            'quotations'    => $quotations,
            'loyaltyRows'   => $loyaltyRows,
            'loyaltyOn'     => app(LoyaltyCalculator::class)->isEnabled(),
            'ledger'        => $ledger,
            'rowLimit'      => self::TAB_ROW_LIMIT,
            'canSeeSales'     => $canSeeSales,
            'canSeePayments'  => $canSeePayments,
            'canSeeStatement' => $canSeeStatement,
            'canSeeQuotations' => $canSeeQuotations,
            'lifetimeSpend' => bcsub((string) ($lifetime->gross ?? '0'), (string) ($refunded ?: '0'), 4),
            'saleCount'     => (int) ($lifetime->sale_count ?? 0),
            'lastSaleDate'  => $lifetime->last_sale_date ?? null,
        ]);
    }

    /**
     * A Sale query already narrowed to what this user may see — the query
     * equivalent of {@see \App\Policies\SalePolicy::view()} (SEC #102).
     *
     * `view_own` means "your own tickets"; `cross_store_view` is what lifts
     * the branch boundary. Both were bypassed by the customer page, which
     * queried sales unfiltered once `customers.view` let the caller in.
     */
    private function scopedSales(?\App\Models\User $user): Builder
    {
        return Sale::query()
            ->when(
                $user && ! $user->is_super_admin && ! $user->can('sales.view_all'),
                fn (Builder $q) => $q->where('cashier_id', $user->id),
            )
            ->when(
                $user && ! $user->is_super_admin && ! $user->can('sales.cross_store_view'),
                fn (Builder $q) => $q->whereIn('store_id', $user->accessibleStoreIds()),
            );
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        $customer->load('addresses');

        return view('admin.customers.edit', $this->editorPayload($customer));
    }

    public function update(CustomerRequest $request, Customer $customer, UpdateCustomer $update): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $customer);

        $update($customer, $request->customerData(), $request->addressData(), $request->user());

        return $this->jsonOrRedirect(
            $request,
            __('customers.flash.updated', ['name' => $customer->name]),
            route('admin.customers.index'),
        );
    }

    /**
     * AJAX flip of `is_active` without opening the edit page. Returns
     * JSON so the row-level toggle on the index can swap state without
     * a full reload. Mirrors Products' `toggleField` pattern.
     */
    public function toggleActive(Customer $customer, Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('update', $customer);

        $customer->update([
            'is_active'  => ! $customer->is_active,
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json([
            'ok'        => true,
            'is_active' => (bool) $customer->is_active,
            'message'   => __($customer->is_active ? 'customers.flash.activated' : 'customers.flash.deactivated', ['name' => $customer->name]),
        ]);
    }

    public function destroy(Request $request, Customer $customer, DeleteCustomer $delete): JsonResponse|RedirectResponse
    {
        $this->authorize('delete', $customer);

        $name = $customer->name;
        try {
            $delete($customer);
        } catch (CustomerHasBalance $e) {
            return $this->jsonOrError(
                $request,
                __('customers.errors.has_balance', ['kind' => $e->kind, 'amount' => number_format($e->amount, 2)]),
                route('admin.customers.show', $customer),
            );
        }

        return $this->jsonOrRedirect(
            $request,
            __('customers.flash.deleted', ['name' => $name]),
            route('admin.customers.index'),
        );
    }

    public function export(Request $request, ExportCustomers $export): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewAny', Customer::class);

        $format = $this->exportFormat($request);

        return ($export)($format);
    }

    /** @return array<string, mixed> */
    private function editorPayload(Customer $customer): array
    {
        return [
            'customer'      => $customer,
            // Eager-load `default_discount_percent` so the JS form can
            // auto-apply a group's default when the user picks one.
            'groups'        => CustomerGroup::query()->active()->orderBy('name')
                                            ->get(['id', 'name', 'default_discount_percent']),
            'stores'        => Store::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'genders'       => \App\Http\Requests\Admin\CustomerRequest::GENDERS,
            'countries'     => \App\Support\Countries::all(),
            // Default country for new addresses — the company's
            // configured country, falling back to the store's, then 'IN'.
            'defaultCountry' => \App\Models\Company::current()?->country_code
                                ?? Store::query()->value('country_code')
                                ?? 'IN',
        ];
    }
}
