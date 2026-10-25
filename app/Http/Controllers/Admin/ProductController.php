<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Products\BulkAssignProductLocation;
use App\Actions\Products\BulkDeleteProducts;
use App\Actions\Products\CreateProduct;
use App\Actions\Products\DeleteProduct;
use App\Actions\Products\ExportProducts;
use App\Actions\Products\GenerateProductBarcode;
use App\Actions\Products\GenerateProductSku;
use App\Actions\Products\SyncProductKitItems;
use App\Actions\Products\SyncProductLocations;
use App\Actions\Products\SyncProductPrices;
use App\Actions\Products\SyncProductVariants;
use App\Actions\Products\ToggleProductFlag;
use App\Actions\Products\UpdateProduct;
use App\Exceptions\ProductHasSales;
use App\Exceptions\ProductVariantHasSales;
use App\Exceptions\ProductVariantHasStock;
use App\Http\Controllers\Concerns\FetchesImageFromUrl;
use App\Http\Controllers\Concerns\ResolvesExportFormat;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\TaxGroup;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Products module — full-page CRUD.
 *
 * Unlike the master-detail flows used by Categories / Brands / Units,
 * Products has too many fields to fit a side-editor, so create + edit
 * each get their own dedicated page. The list view is plain table-mode
 * data-table with sortable columns + search + pagination.
 *
 * Image upload follows the same shape as Brand:
 *   - `image` (file) → store and overwrite path
 *   - `image_remove=1` → drop the stored file, blank the column
 *   - field absent → leave existing image alone
 */
class ProductController extends Controller
{
    use FetchesImageFromUrl;
    use ResolvesExportFormat;
    use RespondsJsonOrRedirect;

    /**
     * Columns the table headers may sort on, mapped to their SQL column.
     * `category` and `margin` need expressions and are handled separately
     * in {@see applySort()}. Anything else falls back to `ordered()`.
     */
    private const SORT_COLUMNS = [
        'name'   => 'products.name',
        'sku'    => 'products.sku',
        'type'   => 'products.type',
        'price'  => 'products.selling_price',
        'cost'   => 'products.cost_price',
        'active' => 'products.is_active',
    ];

    private const PRODUCT_TYPES = ['simple', 'variant', 'kit'];

    /** Cards per grid page (the infinite-scroll batch) vs rows per table page. */
    private const GRID_PAGE_SIZE  = 30;
    private const TABLE_PAGE_SIZE = 25;
    private const MAX_PAGE_SIZE   = 100;

    /**
     * Products list — server-paginated.
     *
     * Only the first page of the *active view* is rendered inline; every
     * subsequent page, search, filter and sort round-trips to {@see rows()}.
     * A 7k-SKU catalog previously rendered each product twice (once as a table
     * row, once as a grid card) — ~17 Alpine directives per product in the DOM,
     * which timed the page out.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Product::class);

        $view    = $this->resolveView($request);
        $perPage = $this->resolvePerPage($request, $view);

        // The inline first page is deliberately unfiltered and default-sorted:
        // `productsPage` boots with every filter at 'all', and its no-op guard
        // assumes the rows on screen match that state. Search, filters and sort
        // all round-trip through rows(). Only `view` and `per_page` are read
        // off the request here.
        $defaults = new Request(['view' => $view, 'per_page' => $perPage]);

        $products = $this->listQuery($defaults)->paginate($perPage)->getCollection();
        $summary  = $this->summaryFor($defaults);

        return view('admin.products.index', [
            'products'         => $products,
            'view'             => $view,
            'perPage'          => $perPage,
            // The two views page at different sizes. The JS needs both, so
            // toggling grid→table doesn't carry the grid's batch size across.
            'tablePageSize'    => self::TABLE_PAGE_SIZE,
            'gridPageSize'     => self::GRID_PAGE_SIZE,
            // Unfiltered here, so it doubles as the "N of GRAND" toolbar total
            // and the "you have no products at all" empty-state check.
            'grandTotal'       => $summary['total'],
            'summary'          => $summary,
            'summaryCards'     => $this->summaryCards($summary),
            'filterCategories' => Category::query()->active()->ordered()->get(['id', 'name']),
            // Drives which coordinate boxes the bulk "Set location" panel
            // shows — same config the product editor reads.
            'locationFields'   => (Company::current() ?? new Company())->locationFields(),
        ]);
    }

    /**
     * One page of product rows (table) or cards (grid) as an HTML fragment,
     * plus the pager + summary metadata the `dataTableServer` mixin needs.
     *
     * Returning rendered Blade rather than JSON records keeps the row markup in
     * exactly one place (`_rows.blade.php` / `_cards.blade.php`) instead of
     * duplicating it as a JS template.
     */
    public function rows(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $view      = $this->resolveView($request);
        $perPage   = $this->resolvePerPage($request, $view);
        $page      = max(1, (int) $request->query('page', 1));
        $paginator = $this->listQuery($request)->paginate($perPage, ['*'], 'page', $page);

        $html = view(
            $view === 'table' ? 'admin.products._rows' : 'admin.products._cards',
            ['products' => $paginator->getCollection()],
        )->render();

        return response()->json([
            'html'        => $html,
            'total'       => $paginator->total(),
            'page'        => $paginator->currentPage(),
            'per_page'    => $paginator->perPage(),
            'total_pages' => max(1, $paginator->lastPage()),
            'summary'     => $this->summaryFor($request),
        ]);
    }

    /** 'table' | 'grid' — grid is the default view (matches productsPage). */
    private function resolveView(Request $request): string
    {
        return $request->query('view') === 'table' ? 'table' : 'grid';
    }

    private function resolvePerPage(Request $request, string $view): int
    {
        $default = $view === 'table' ? self::TABLE_PAGE_SIZE : self::GRID_PAGE_SIZE;
        $perPage = (int) $request->query('per_page', $default);

        return max(1, min($perPage, self::MAX_PAGE_SIZE));
    }

    /**
     * The active search + filter set, with no eager loads and no ordering.
     * Shared by the row query and the summary aggregate, so the cards always
     * describe exactly the set the user is looking at.
     *
     * @return Builder<Product>
     */
    private function filteredBase(Request $request): Builder
    {
        $query = Product::query();

        $storeId = current_store_id();

        // `input()`, not `query()`: the bulk-location endpoint is a POST and
        // carries the same filter set in its body so it can act on exactly
        // the set the user is looking at. `input()` still reads the query
        // string for the GET list/rows callers.
        $term = trim((string) $request->input('q', ''));
        if ($term !== '') {
            $query->where(function (Builder $w) use ($term, $storeId) {
                $w->where('products.name', 'like', "%{$term}%")
                    ->orWhere('products.sku', 'like', "%{$term}%")
                    ->orWhere('products.barcode', 'like', "%{$term}%");

                // "Where is A3?" — searching a shelf coordinate should find
                // what's on it. Scoped to the active store, since the same
                // rack number means something different at another branch.
                if ($storeId !== null) {
                    $w->orWhereHas('locations', fn ($l) => $l
                        ->forStore($storeId)
                        ->where(fn ($c) => $c
                            ->where('aisle', 'like', "%{$term}%")
                            ->orWhere('rack', 'like', "%{$term}%")
                            ->orWhere('shelf', 'like', "%{$term}%")
                            ->orWhere('bin', 'like', "%{$term}%")));
                }
            });
        }

        // "Which products still have no shelf location?" — the question
        // that drives the initial data-entry pass after enabling this.
        $located = (string) $request->input('located', 'all');
        if ($storeId !== null && in_array($located, ['yes', 'no'], true)) {
            $has = fn ($l) => $l->forStore($storeId)->productLevel();

            if ($located === 'yes') {
                $query->whereHas('locations', $has);
            } else {
                $query->whereDoesntHave('locations', $has);
            }
        }

        $category = (string) $request->input('category', 'all');
        if ($category !== 'all' && ctype_digit($category)) {
            $query->where('products.category_id', (int) $category);
        }

        $type = (string) $request->input('type', 'all');
        if (in_array($type, self::PRODUCT_TYPES, true)) {
            $query->where('products.type', $type);
        }

        $status = (string) $request->input('status', 'all');
        if ($status === 'active') {
            $query->where('products.is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('products.is_active', false);
        }

        if ($request->input('featured') === 'featured') {
            $query->where('products.is_featured', true);
        }

        return $query;
    }

    /** @return Builder<Product> */
    private function listQuery(Request $request): Builder
    {
        $query = $this->filteredBase($request)
            ->with([
                'category:id,name',
                'brand:id,name,logo_path',
                'unit:id,code,name',
                'taxGroup:id,name',
                // Shelf locations for the ACTIVE store only — the list shows
                // one column, and a product's rack at another branch is
                // meaningless here. Eager-loaded so primaryLocation() never
                // fires a query per row.
                'locations' => fn ($q) => $q
                    ->forStore(current_store_id())
                    ->productLevel()
                    ->inWalkOrder(),
            ])
            // Counts drive the "Variant · N" / "Kit · N" type badges.
            ->withCount(['variants', 'kitItems'])
            // A variant parent carries no money of its own — price and cost
            // live on each child SKU, so the parent's own columns are 0.
            // These aggregates let the list show a real "from – to" range
            // instead of a misleading 0.00. See Product::priceBounds().
            ->withMin('variants as variants_min_price', 'selling_price')
            ->withMax('variants as variants_max_price', 'selling_price')
            ->withMin('variants as variants_min_cost', 'cost_price')
            ->withMax('variants as variants_max_cost', 'cost_price');

        $this->applySort($query, $request);

        return $query;
    }

    /** @param Builder<Product> $query */
    private function applySort(Builder $query, Request $request): void
    {
        $column = (string) $request->query('sort', '');
        $dir    = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($column === 'category') {
            // Correlated subquery rather than a join — avoids ambiguous columns
            // and leaves the withCount subqueries intact.
            $query->orderBy(
                Category::query()->select('name')->whereColumn('categories.id', 'products.category_id'),
                $dir,
            );
        } elseif ($column === 'margin') {
            // Margin isn't a column; it's (price - cost) / price. NULLIF guards
            // the zero-price divide. `$dir` is whitelisted to asc|desc above.
            $price = $this->effectivePriceSql('selling_price');
            $cost  = $this->effectivePriceSql('cost_price');
            $query->orderByRaw("({$price} - {$cost}) / NULLIF({$price}, 0) {$dir}");
        } elseif ($column === 'location') {
            // Walk order for the active store. `sort_key` is zero-padded on
            // write, so plain string ordering gives A3 before A10 — sorting
            // on the raw coordinates would not. Products with no location
            // sort last in both directions (NULL subquery result).
            $storeId = current_store_id();
            $query->orderBy(
                ProductLocation::query()
                    ->select('sort_key')
                    ->whereColumn('product_locations.product_id', 'products.id')
                    ->whereNull('product_locations.variant_id')
                    ->when($storeId !== null, fn ($q) => $q->where('product_locations.store_id', $storeId))
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_key')
                    ->limit(1),
                $dir,
            );
        } elseif ($column === 'price' || $column === 'cost') {
            // Sort on the same number the list DISPLAYS — for a variant parent
            // that's its cheapest child SKU, not its own (always 0) column.
            $query->orderByRaw(
                $this->effectivePriceSql($column === 'price' ? 'selling_price' : 'cost_price')." {$dir}"
            );
        } elseif (isset(self::SORT_COLUMNS[$column])) {
            $query->orderBy(self::SORT_COLUMNS[$column], $dir);
        } else {
            $query->ordered();

            return;
        }

        // Deterministic tiebreak. Without it, rows sharing a sort key can
        // reshuffle between pages, so a product appears twice — or never.
        $query->orderBy('products.id', 'desc');
    }

    /**
     * SQL for the money a product actually shows in the list: the cheapest
     * live variant for a variant parent, the product's own column otherwise.
     * `$column` is never user input — the two call sites pass a literal.
     */
    private function effectivePriceSql(string $column): string
    {
        return "COALESCE("
            ."(SELECT MIN(pv.{$column}) FROM product_variants pv"
            ." WHERE pv.product_id = products.id AND pv.deleted_at IS NULL),"
            ." products.{$column})";
    }

    /**
     * Totals for the summary cards, honoring the active filters. One aggregate
     * query — never a `count()` over a hydrated collection.
     *
     * @return array{total:int, active:int, featured:int}
     */
    private function summaryFor(Request $request): array
    {
        $row = $this->filteredBase($request)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COALESCE(SUM(products.is_active), 0) AS active_count')
            ->selectRaw('COALESCE(SUM(products.is_featured), 0) AS featured_count')
            ->first();

        return [
            'total'    => (int) ($row->total_count ?? 0),
            'active'   => (int) ($row->active_count ?? 0),
            'featured' => (int) ($row->featured_count ?? 0),
        ];
    }

    /**
     * `key` ties each card's value to its DOM node so `productsPage` can rewrite
     * it from the `summary` every rows() response carries.
     *
     * @param  array{total:int, active:int, featured:int}  $summary
     */
    private function summaryCards(array $summary): array
    {
        return [
            ['label' => __('products.summary.total'),    'value' => number_format($summary['total']),    'key' => 'total'],
            ['label' => __('products.summary.active'),   'value' => number_format($summary['active']),   'key' => 'active', 'tone' => 'positive'],
            ['label' => __('products.summary.featured'), 'value' => number_format($summary['featured']), 'key' => 'featured'],
        ];
    }

    /**
     * JSON search for the remote product picker (kit components, and any
     * future product-select). Returns at most 25 active products matching
     * the query on name / SKU / barcode, each with its variants so a
     * picker can offer "lock to variant" without a second round-trip.
     *
     * Shape: `[{ value, label, variants: [{id, label}] }]`.
     */
    /**
     * The next auto-generated SKU, for the form's "Generate" button.
     *
     * Purely a preview — nothing is reserved. Whatever comes back is a
     * normal typed value from the server's point of view, and the real
     * assignment still happens in CreateProduct (which regenerates on a
     * collision), so two admins clicking Generate at once is harmless.
     */
    public function nextSku(GenerateProductSku $generate): JsonResponse
    {
        $this->authorize('create', Product::class);

        return response()->json(['sku' => $generate()]);
    }

    /**
     * The next auto-generated in-store barcode (a valid EAN-13), for the
     * form's "Generate" button on non-standard items. Preview only —
     * nothing is reserved; the value is saved as a normal barcode, and a
     * rare save-time race surfaces as an ordinary "barcode taken" error
     * the admin resolves by generating again.
     */
    public function nextBarcode(Request $request, GenerateProductBarcode $generate): JsonResponse
    {
        $this->authorize('create', Product::class);

        // `exclude` carries the barcodes already sitting unsaved in the form
        // (parent + sibling variants) so generating for several variants in
        // a row hands out distinct codes instead of the same DB-derived one.
        $exclude = array_filter(array_map(
            fn ($v) => trim((string) $v),
            (array) $request->query('exclude', []),
        ));

        return response()->json(['barcode' => $generate($exclude)]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $q       = trim((string) $request->query('q', ''));
        $exclude = (int) $request->query('exclude', 0);

        $products = Product::query()
            ->active()
            ->when($exclude > 0, fn ($qb) => $qb->where('id', '!=', $exclude))
            ->when($q !== '', fn ($qb) => $qb->where(fn ($w) =>
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('sku', 'like', "%{$q}%")
                  ->orWhere('barcode', 'like', "%{$q}%")
            ))
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'sku', 'track_batches', 'track_expiry', 'cost_price', 'selling_price']);

        $variantsByProduct = ProductVariant::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'product_id', 'sku', 'attributes', 'cost_price', 'selling_price'])
            ->groupBy('product_id');

        return response()->json(
            $products->map(fn (Product $p) => [
                'value'         => (string) $p->id,
                'label'         => $p->name.' ('.$p->sku.')',
                'track_batches' => (bool) $p->track_batches,
                'track_expiry'  => (bool) $p->track_expiry,
                // Prices power the kit editor's auto-pricing (sum of components).
                'cost_price'    => (string) $p->cost_price,
                'selling_price' => (string) $p->selling_price,
                'variants'      => $variantsByProduct->get($p->id, collect())
                    // A variant's own price wins; null falls back to the parent's.
                    ->map(fn (ProductVariant $v) => [
                        'id'            => (string) $v->id,
                        'label'         => (string) $v->label,
                        'cost_price'    => (string) ($v->cost_price    ?? $p->cost_price),
                        'selling_price' => (string) ($v->selling_price ?? $p->selling_price),
                    ])
                    ->values(),
            ])->all()
        );
    }

    public function create(): View
    {
        $this->authorize('create', Product::class);

        return view('admin.products.create', $this->formContext());
    }

    public function store(ProductRequest $request, CreateProduct $create, SyncProductVariants $syncVariants, SyncProductKitItems $syncKitItems, SyncProductPrices $syncPrices, SyncProductLocations $syncLocations): JsonResponse|RedirectResponse
    {
        $this->authorize('create', Product::class);

        $data = $request->persistedAttributes();
        $data['created_by'] = $request->user()?->id;
        $data['updated_by'] = $request->user()?->id;

        // A chosen file wins over a pasted link — checking the file first is
        // the whole rule, so the customer never has to be asked which they
        // meant. The link path also records WHERE the image came from, the
        // same way a bulk URL fetch does.
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('products', 'public');
        } elseif ($path = $this->imagePathFromUrl($request, 'image_url', 'products')) {
            $data['image_path']         = $path;
            $data['image_src_url']      = trim((string) $request->input('image_url'));
            $data['image_fetch_status'] = 'done';
            $data['image_fetch_error']  = null;
        }

        $product = ($create)($data);

        // Sync variants. For `simple` / `kit`, the payload is empty
        // — sync becomes a no-op (nothing to delete, nothing to add).
        // For `variant`, we persist whatever the user provided.
        ($syncVariants)(
            $product,
            $product->type === 'variant' ? $request->variantsPayload() : []
        );

        // Sync kit components — mirror shape for `type=kit`.
        ($syncKitItems)(
            $product,
            $product->type === 'kit' ? $request->kitItemsPayload() : []
        );

        // Product-level per-store overrides apply only to simple / kit
        // products. Variant products price per-child, so their store
        // overrides ride along inside SyncProductVariants above.
        if ($product->type !== 'variant') {
            ($syncPrices)($product, $request->storePricesPayload());
        }

        // Shelf locations apply to every product type — a variant parent
        // still occupies one rack; only its PRICE lives per child.
        ($syncLocations)($product, $request->locationsPayload(), $this->manageableStoreIds());

        return $this->jsonOrRedirect(
            $request,
            __('products.flash.created', ['name' => $product->name]),
            route('admin.products.edit', $product),
        );
    }

    public function edit(Product $product): View
    {
        $this->authorize('update', $product);

        // Eager-load variants + kit items so the editor renders existing
        // rows without an N+1 problem on first paint. Kit items pull in
        // their component product / variant so the picker can render the
        // current selection by name without a follow-up query.
        $product->load([
            'variants' => fn ($q) => $q->orderBy('id'),
            'kitItems' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'kitItems.component:id,name,sku',
            'kitItems.variant:id,product_id,sku,attributes',
            'prices',
            // Product-level shelf locations, in the order the editor
            // should render them (pick face first, then walk order).
            'locations' => fn ($q) => $q->productLevel()->inWalkOrder(),
        ]);

        return view('admin.products.edit', array_merge($this->formContext(), [
            'product' => $product,
        ]));
    }

    public function update(ProductRequest $request, Product $product, UpdateProduct $update, SyncProductVariants $syncVariants, SyncProductKitItems $syncKitItems, SyncProductPrices $syncPrices, SyncProductLocations $syncLocations): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $product);

        $data = $request->persistedAttributes();
        $data['updated_by'] = $request->user()?->id;

        // Four image lifecycles now — same as BrandController::update().
        // The pasted link sits between "new file" and "remove": a file the
        // customer picked always wins, and a link beats Remove.
        //
        // Link-over-Remove is deliberate. The link box stays visible when a
        // photo is already attached (otherwise "replace this with one from a
        // link" would be impossible), and the Remove button clears the
        // preview — so a customer replacing a photo by link can easily have
        // both flags set. A URL in the box is something they typed; Remove on
        // its own is what "I just want it gone" looks like.
        if ($request->hasFile('image')) {
            $this->deleteStoredImage($product);
            $data['image_path'] = $request->file('image')->store('products', 'public');
        } elseif ($path = $this->imagePathFromUrl($request, 'image_url', 'products')) {
            $this->deleteStoredImage($product);
            $data['image_path']         = $path;
            $data['image_src_url']      = trim((string) $request->input('image_url'));
            $data['image_fetch_status'] = 'done';
            $data['image_fetch_error']  = null;
        } elseif ($request->boolean('image_remove')) {
            $this->deleteStoredImage($product);
            $data['image_path'] = null;
        }

        ($update)($product, $data);

        // Always run sync — payload is the user's variants[] when the
        // product is `type=variant`, empty otherwise. The empty case
        // matters on type transitions away from variant: it wipes any
        // orphaned children left behind. Sales-guard inside sync may
        // refuse if a child variant has historical sale lines.
        $fresh = $product->fresh();
        try {
            ($syncVariants)(
                $fresh,
                $fresh->type === 'variant' ? $request->variantsPayload() : []
            );
        } catch (ProductVariantHasSales $e) {
            return $this->jsonOrError(
                $request,
                __('products.errors.variant_has_sales', [
                    'label' => $e->label,
                    'count' => $e->count,
                ]),
                route('admin.products.edit', $product),
            );
        } catch (ProductVariantHasStock $e) {
            return $this->jsonOrError(
                $request,
                __('products.errors.variant_has_stock', [
                    'label'    => $e->label,
                    'quantity' => $e->quantity,
                ]),
                route('admin.products.edit', $product),
            );
        }

        // Same shape for kit components — empty payload on non-kit
        // types wipes any orphans from a prior `type=kit` state.
        ($syncKitItems)(
            $fresh,
            $fresh->type === 'kit' ? $request->kitItemsPayload() : []
        );

        // Product-level per-store overrides — simple / kit only. Variant
        // products' overrides are written per-child inside the variant
        // sync. On a type-down-switch away from variant, the stale
        // variant rows cascade-delete with their variants.
        if ($fresh->type !== 'variant') {
            ($syncPrices)($fresh, $request->storePricesPayload());
        }

        // Locations are product-level for every type. Scoped to the stores
        // the editor actually rendered, so saving here can never wipe a
        // location belonging to a store this form didn't show.
        ($syncLocations)($fresh, $request->locationsPayload(), $this->manageableStoreIds());

        // Redirect to the list instead of back to the edit page — the
        // user can immediately scan / pick another product to edit.
        // Successful saves should feel "done", not "still here".
        return $this->jsonOrRedirect(
            $request,
            __('products.flash.updated', ['name' => $product->name]),
            route('admin.products.index'),
        );
    }

    public function destroy(Request $request, Product $product, DeleteProduct $delete): JsonResponse|RedirectResponse
    {
        $this->authorize('delete', $product);

        $name = $product->name;

        try {
            ($delete)($product);
        } catch (ProductHasSales $e) {
            return $this->jsonOrError(
                $request,
                __('products.errors.has_sales', ['count' => $e->count, 'name' => $name]),
                route('admin.products.edit', $product),
            );
        }

        return $this->jsonOrRedirect(
            $request,
            __('products.flash.deleted', ['name' => $name]),
            route('admin.products.index'),
        );
    }

    /**
     * Row-level quick-action: flip a single boolean flag (is_active or
     * is_featured) without running through the full validation
     * pipeline. Returns JSON so the products list page can update
     * optimistically without a full reload.
     */
    public function toggle(Request $request, Product $product, ToggleProductFlag $toggle): JsonResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'field' => ['required', Rule::in(['is_active', 'is_featured'])],
            'value' => ['required', 'boolean'],
        ]);

        ($toggle)($product, $data['field'], (bool) $data['value']);

        return response()->json([
            'ok'          => true,
            'id'          => $product->id,
            'is_active'   => (bool) $product->is_active,
            'is_featured' => (bool) $product->is_featured,
            'message'     => __('products.flash.updated', ['name' => $product->name]),
        ]);
    }

    /**
     * Bulk-assign (or clear) the pick-face shelf location for many
     * products at the active store.
     *
     * `scope=filtered` deliberately acts on EVERY product matching the
     * current search + filters, not the visible page. With server-side
     * paging, "select all" would mean "these 25", which is useless for the
     * job this exists to do — entering locations across a whole catalog.
     * The filter params are re-read here rather than trusted from the
     * client's row ids, so the set the server acts on is the same set the
     * user was looking at.
     */
    public function bulkLocation(Request $request, BulkAssignProductLocation $bulk): JsonResponse
    {
        $this->authorize('products.update');

        $data = $request->validate([
            'mode'  => ['required', Rule::in(['set', 'clear'])],
            'scope' => ['required', Rule::in(['selected', 'filtered'])],
            'ids'   => ['required_if:scope,selected', 'array'],
            'ids.*' => ['integer'],
            'aisle' => ['nullable', 'string', 'max:16'],
            'rack'  => ['nullable', 'string', 'max:16'],
            'shelf' => ['nullable', 'string', 'max:16'],
            'bin'   => ['nullable', 'string', 'max:16'],
            'note'  => ['nullable', 'string', 'max:64'],
        ]);

        // Locations are per store, so one has to be active. Without this
        // the operation has no meaning — and silently picking a default
        // would write hundreds of rows to the wrong branch.
        $storeId = current_store_id();
        if ($storeId === null) {
            return response()->json(['message' => __('products.locations.bulk.no_store')], 422);
        }

        $query = $this->filteredBase($request);

        if ($data['scope'] === 'selected') {
            $ids = array_values(array_unique(array_map('intval', $data['ids'] ?? [])));
            if ($ids === []) {
                return response()->json(['message' => __('products.locations.bulk.nothing_selected')], 422);
            }
            $query->whereIn('products.id', $ids);
        }

        $result = ($bulk)(
            $query,
            $storeId,
            $request->only(['aisle', 'rack', 'shelf', 'bin', 'note']),
            $data['mode'],
        );

        // A capped run is reported, never silently truncated — the user
        // needs to know some products were left untouched.
        $message = $result['capped']
            ? __('products.locations.bulk.capped', [
                'count' => number_format($result['affected']),
                'max'   => number_format(BulkAssignProductLocation::MAX_PRODUCTS),
            ])
            : __('products.locations.bulk.'.($data['mode'] === 'clear' ? 'cleared' : 'applied'), [
                'count' => number_format($result['affected']),
            ]);

        return response()->json([
            'ok'       => true,
            'affected' => $result['affected'],
            'capped'   => $result['capped'],
            'message'  => $message,
        ]);
    }

    /** Soft-delete the explicitly checked products on the current page. */
    public function bulkDelete(Request $request, BulkDeleteProducts $bulk): JsonResponse
    {
        $this->authorize('products.delete');

        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:'.self::MAX_PAGE_SIZE],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $products = Product::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        $result = ($bulk)($products);

        if ($result['blocked'] > 0 && $result['deleted'] === 0) {
            $message = __('products.locations.bulk.delete_none', [
                'blocked' => number_format($result['blocked']),
            ]);
        } elseif ($result['blocked'] > 0) {
            $message = __('products.locations.bulk.delete_partial', [
                'deleted' => number_format($result['deleted']),
                'blocked' => number_format($result['blocked']),
            ]);
        } else {
            $message = __('products.locations.bulk.deleted', [
                'count' => number_format($result['deleted']),
            ]);
        }

        return response()->json([
            'ok'        => true,
            'processed' => $result['processed'],
            'deleted'   => $result['deleted'],
            'blocked'   => $result['blocked'],
            'message'   => $message,
        ]);
    }

    public function export(Request $request, ExportProducts $export): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewAny', Product::class);

        $format = $this->exportFormat($request);

        return ($export)($format);
    }

    /* ── Helpers ────────────────────────────────────────────────── */

    /**
     * Shared dropdown data for the create + edit forms. Eager-load just
     * what the option lists need so the form payload stays light.
     *
     * @return array<string, mixed>
     */
    private function formContext(): array
    {
        // Include `tax_group_id` on categories — the form uses this to
        // pre-fill the Tax rule field when the user picks a category
        // that has a default rule configured.
        $categories = Category::query()->active()->ordered()->get(['id', 'name', 'tax_group_id']);
        $company = Company::current() ?? new Company();

        return [
            'categories'     => $categories,
            'brands'         => Brand::query()->active()->ordered()->get(['id', 'name']),
            'units'          => Unit::query()->active()->ordered()->get(['id', 'code', 'name', 'category']),
            'taxGroups'      => TaxGroup::query()->active()->ordered()->get(['id', 'name']),
            // Per-store price-override UI uses this list. Hidden when
            // empty (single-store installs that haven't yet seeded a
            // store row).
            'stores'         => Store::query()->active()->ordered()->get(['id', 'name', 'code', 'currency_code']),
            // Plain map { category_id (string) → tax_group_id (string|null) }
            // for the editor's JS — categoryId / taxGroupId watcher.
            'categoryTaxMap' => $categories
                ->mapWithKeys(fn ($c) => [(string) $c->id => $c->tax_group_id ? (string) $c->tax_group_id : ''])
                ->all(),
            // Dynamic lookup — managed via /admin/drug-schedules.
            'pharmacySchedules' => \App\Models\DrugSchedule::query()
                ->active()
                ->ordered()
                ->get(['code', 'name', 'country_code']),
            // Which of aisle / rack / shelf / bin this company uses, and
            // what it calls them — Settings → Stock locations.
            'locationFields' => $company->locationFields(),
            'locationKinds'  => ProductLocation::KINDS,
            'batchPricing'   => $company->usesBatchPricing(),
        ];
    }

    /**
     * Stores whose shelf locations this form is allowed to rewrite.
     *
     * Deliberately the SAME list `formContext()` renders: the sync action
     * deletes rows the payload no longer mentions, so its delete scope has
     * to match exactly what the user could see. An inactive store's
     * locations fall outside the scope and are preserved untouched.
     *
     * @return array<int, int>
     */
    private function manageableStoreIds(): array
    {
        return Store::query()->active()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function deleteStoredImage(Product $product): void
    {
        if (! $product->image_path) return;
        if (Storage::disk('public')->exists($product->image_path)) {
            Storage::disk('public')->delete($product->image_path);
        }
    }
}
