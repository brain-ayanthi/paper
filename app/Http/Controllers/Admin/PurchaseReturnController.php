<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Purchases\CreatePurchaseReturn;
use App\Http\Controllers\Concerns\RendersDataTableRows;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PurchaseReturnRequest;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchaseReturnController extends Controller
{
    use RendersDataTableRows;
    use RespondsJsonOrRedirect;

    private const RETURNABLE = [
        Purchase::STATUS_RECEIVED,
        Purchase::STATUS_PARTIALLY_PAID,
        Purchase::STATUS_PAID,
    ];

    /**
     * Purchase returns — server-paginated. Filters (status / date range /
     * search) + paging round-trip to {@see rows()} via the generic
     * `purchaseReturnsPage` (aliased salesIndexPage) factory.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Purchase::class);

        $perPage   = $this->dtPerPage($request);
        $paginator = $this->listQuery($request)->paginate($perPage);

        return view('admin.purchases.returns.index', [
            'returns'    => $paginator->getCollection(),
            'total'      => $paginator->total(),
            'perPage'    => $perPage,
            'totalPages' => max(1, $paginator->lastPage()),
            'filters'    => [
                'status' => (string) $request->query('status', 'all'),
                'from'   => $request->query('from'),
                'to'     => $request->query('to'),
                'q'      => trim((string) $request->query('q', '')),
            ],
        ]);
    }

    /** One page of return rows as an HTML fragment. See {@see RendersDataTableRows}. */
    public function rows(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Purchase::class);

        $paginator = $this->listQuery($request)
            ->paginate($this->dtPerPage($request), ['*'], 'page', $this->dtPage($request));

        return $this->dtRows($paginator, 'admin.purchases.returns._rows', 'returns');
    }

    /** @return Builder<PurchaseReturn> */
    private function listQuery(Request $request): Builder
    {
        $status = (string) $request->query('status', 'all');
        $from   = $request->query('from');
        $to     = $request->query('to');
        $q      = trim((string) $request->query('q', ''));
        // Exact supplier scope, matching purchases.index and
        // supplier-payments.index. The supplier page's "All returns" link
        // needs this: `q` only fuzzy-matches the supplier NAME, so two
        // similarly-named suppliers would show each other's returns.
        $supplierId = $request->integer('supplier_id') ?: null;

        // Store scope (SEC #84). This list had none at all, so every branch's
        // returns showed to any viewer. Unlike purchases.index there is no
        // store picker here, so clamp to everything the user may reach rather
        // than to a single active store.
        $user      = auth()->user();
        $storeIds  = ($user && ! $user->is_super_admin) ? $user->accessibleStoreIds() : null;

        return PurchaseReturn::query()
            ->with(['purchase:id,number', 'supplier:id,name', 'store:id,name'])
            ->when($storeIds !== null, fn ($qb) => $qb->whereIn('store_id', $storeIds))
            ->when($supplierId, fn ($qb) => $qb->where('supplier_id', $supplierId))
            ->when($status !== 'all', fn ($qb) => $qb->where('status', $status))
            ->when($from, fn ($qb) => $qb->whereDate('return_date', '>=', $from))
            ->when($to,   fn ($qb) => $qb->whereDate('return_date', '<=', $to))
            ->when($q !== '', fn ($qb) => $qb->where(function ($w) use ($q) {
                $w->where('number', 'like', "%{$q}%")
                  ->orWhereHas('purchase', fn ($p) => $p->where('number', 'like', "%{$q}%"))
                  ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$q}%"));
            }))
            ->orderByDesc('id');
    }

    public function create(Purchase $purchase): View
    {
        $this->authorize('update', $purchase);

        abort_unless(in_array($purchase->status, self::RETURNABLE, true), 403);

        $purchase->load(['items.product:id,name,sku']);

        return view('admin.purchases.returns.create', compact('purchase'));
    }

    public function store(
        Purchase $purchase,
        PurchaseReturnRequest $request,
        CreatePurchaseReturn $action,
    ): JsonResponse|RedirectResponse {
        $this->authorize('update', $purchase);

        abort_unless(in_array($purchase->status, self::RETURNABLE, true), 403);

        try {
            $return = $action($purchase, $request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect(
            $request,
            __('purchases.returns.flash.created', ['number' => $return->number]),
            route('admin.purchase-returns.show', $return),
        );
    }

    public function show(PurchaseReturn $purchaseReturn): View
    {
        // `viewAny` is class-level, so the bound record was never scoped —
        // any return was openable by id (SEC #84).
        $this->authorize('viewAny', Purchase::class);
        abort_unless_store_access($purchaseReturn->store_id);

        $purchaseReturn->load([
            'purchase:id,number',
            'supplier:id,name',
            'store:id,name',
            'items.purchaseItem.product:id,name,sku',
        ]);

        return view('admin.purchases.returns.show', ['return' => $purchaseReturn]);
    }
}
