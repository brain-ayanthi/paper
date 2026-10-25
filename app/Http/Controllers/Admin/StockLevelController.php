<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Inventory\ExportStockLevels;
use App\Http\Controllers\Concerns\RendersDataTableRows;
use App\Http\Controllers\Concerns\ResolvesExportFormat;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\StockLevel;
use App\Support\Inventory\StockLevelBatchDataset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stock levels — per-store list of every product's on-hand quantity.
 * Read-only here, except for the reorder-level override which is the
 * one field the user can edit inline. Real stock changes happen via
 * stock adjustments.
 *
 * Gated by `products.view` (read) / `products.adjust_stock` (the
 * reorder edit). Honors the active store from the store switcher when
 * no explicit `store_id` filter is set.
 */
class StockLevelController extends Controller
{
    use RendersDataTableRows;
    use ResolvesExportFormat;
    use RespondsJsonOrRedirect;

    /**
     * Stock levels — server-paginated. Filters (store / search) + paging
     * round-trip to {@see rows()} via the generic `stockLevelsPage` (aliased
     * salesIndexPage) factory. The per-row reorder edit is an independent
     * `data-ajax-form` and is unaffected.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', StockLevel::class);

        $perPage = $this->dtPerPage($request);
        $paginator = $this->listQuery($request)->paginate($perPage);
        $this->attachBatchBreakdowns($paginator->getCollection());
        $summary = $this->summaryFor($request);

        return view('admin.inventory.levels.index', [
            'levels' => $paginator->getCollection(),
            'total' => $paginator->total(),
            'perPage' => $perPage,
            'totalPages' => max(1, $paginator->lastPage()),
            'summaryCards' => $this->summaryCards($summary),
            'stores' => accessible_stores(),
            'storeId' => resolved_store_id($request),
            'q' => trim((string) $request->query('q', '')),
            'batchPricing' => Company::current()?->usesBatchPricing() ?? false,
        ]);
    }

    /** One page of stock-level rows as an HTML fragment plus summary meta. */
    public function rows(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockLevel::class);

        $paginator = $this->listQuery($request)
            ->paginate($this->dtPerPage($request), ['*'], 'page', $this->dtPage($request));
        $this->attachBatchBreakdowns($paginator->getCollection());

        return $this->dtRows($paginator, 'admin.inventory.levels._rows', 'levels', [
            'summary' => $this->summaryFor($request),
        ], [
            'batchPricing' => Company::current()?->usesBatchPricing() ?? false,
        ]);
    }

    /** @return Builder<StockLevel> */
    private function filteredBase(Request $request): Builder
    {
        $storeId = resolved_store_id($request);
        $q = trim((string) $request->query('q', ''));

        return StockLevel::query()
            ->when($storeId, fn ($qb) => $qb->where('store_id', $storeId))
            ->when($q !== '', function ($qb) use ($q) {
                $qb->whereHas('product', function ($pq) use ($q) {
                    $pq->where('name', 'like', "%{$q}%")
                        ->orWhere('sku', 'like', "%{$q}%")
                        ->orWhere('barcode', 'like', "%{$q}%");
                });
            });
    }

    /** @return Builder<StockLevel> */
    private function listQuery(Request $request): Builder
    {
        return $this->filteredBase($request)
            ->with([
                'product:id,sku,name,unit_id,reorder_level,track_batches',
                'product.unit:id,code,name',
                'variant:id,sku,attributes',
                'store:id,code,name',
                // Product-level locations for EVERY store, not just the
                // filtered one — the list can span stores, and each row
                // picks its own store's pick face out of the loaded set
                // (Product::primaryLocation). A few rows per product, so
                // this stays one query instead of one per line.
                'product.locations' => fn ($q) => $q->productLevel()->inWalkOrder(),
            ])
            ->orderByDesc('last_movement_at');
    }

    /**
     * Attach active, on-hand batches to each paginated stock-level row without
     * creating an N+1 query. ProductBatch is store-specific, so a normal
     * product_id-only eager load would mix batches from different stores.
     *
     * @param  Collection<int, StockLevel>  $levels
     */
    private function attachBatchBreakdowns(Collection $levels): void
    {
        $dataset = app(StockLevelBatchDataset::class);
        $groups = $dataset->load($levels);

        foreach ($levels->filter(fn (StockLevel $level) => (bool) $level->product?->track_batches) as $level) {
            $level->setRelation('batches', $dataset->batchesFor($level, $groups));
        }
    }

    /**
     * @return array{items:string, units:string, value:string, out:string}
     */
    private function summaryFor(Request $request): array
    {
        $agg = $this->filteredBase($request)
            ->selectRaw('COUNT(*) as items')
            ->selectRaw('COALESCE(SUM(quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(quantity * weighted_average_cost), 0) as value')
            // `out_count`, not `out` — `out` is a reserved word in MariaDB and
            // fails as a bare column alias (SQLSTATE 42000).
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END), 0) as out_count')
            ->first();

        $trimQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') ?: '0';

        $value = $agg->value ?? 0;
        if (Company::current()?->usesBatchPricing()) {
            $levels = $this->filteredBase($request)
                ->with('product:id,track_batches')
                ->get();
            $dataset = app(StockLevelBatchDataset::class);
            $groups = $dataset->load($levels);
            $value = $levels->reduce(function (string $total, StockLevel $level) use ($dataset, $groups) {
                $levelValue = $level->product?->track_batches
                    ? $dataset->value($level, $dataset->batchesFor($level, $groups))
                    : bcmul((string) $level->quantity, (string) ($level->weighted_average_cost ?? '0'), 4);

                return bcadd($total, $levelValue, 4);
            }, '0.0000');
        }

        return [
            'items' => number_format((int) ($agg->items ?? 0)),
            'units' => $trimQty($agg->units ?? 0),
            'value' => format_money($value),
            'out' => number_format((int) ($agg->out_count ?? 0)),
        ];
    }

    /**
     * @param  array{items:string, units:string, value:string, out:string}  $summary
     * @return array<int, array<string, mixed>>
     */
    private function summaryCards(array $summary): array
    {
        return [
            ['label' => __('inventory.levels.summary.items'), 'value' => $summary['items'], 'key' => 'items'],
            ['label' => __('inventory.levels.summary.units'), 'value' => $summary['units'], 'key' => 'units'],
            ['label' => __('inventory.levels.summary.value'), 'value' => $summary['value'], 'key' => 'value'],
            ['label' => __('inventory.levels.summary.out'),   'value' => $summary['out'],   'key' => 'out', 'tone' => 'danger'],
        ];
    }

    public function updateReorderOverride(Request $request, StockLevel $stockLevel): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $stockLevel);

        $data = $request->validate([
            'reorder_level_override' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
        ]);

        $stockLevel->update([
            'reorder_level_override' => $data['reorder_level_override'] === null || $data['reorder_level_override'] === ''
                ? null
                : $data['reorder_level_override'],
        ]);

        return $this->jsonOrRedirect(
            $request,
            __('inventory.levels.flash.reorder_updated'),
            url()->previous() ?: route('admin.inventory.levels.index'),
        );
    }

    public function export(Request $request, ExportStockLevels $export): StreamedResponse
    {
        $this->authorize('viewAny', StockLevel::class);

        $format = $this->exportFormat($request);

        // Same store scoping as the list (see index) so an export can never
        // hand back stock from a store the user can't open on screen (SEC #85).
        return ($export)(
            $format,
            resolved_store_id($request),
            trim((string) $request->query('q', '')),
        );
    }
}
