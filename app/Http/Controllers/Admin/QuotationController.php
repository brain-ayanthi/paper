<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Quotations\BuildQuotationPricing;
use App\Actions\Quotations\ChangeQuotationStatus;
use App\Actions\Quotations\ConvertQuotationToSale;
use App\Actions\Quotations\ReviseQuotation;
use App\Actions\Quotations\SaveQuotation;
use App\Http\Controllers\Concerns\RendersDataTableRows;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvertQuotationRequest;
use App\Http\Requests\Admin\QuotationRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\Store;
use App\Services\Barcodes\ScannedProductResolver;
use App\Services\Quotations\QuotationPdfRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuotationController extends Controller
{
    use RendersDataTableRows;
    use RespondsJsonOrRedirect;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Quotation::class);
        $perPage = $this->dtPerPage($request);
        $paginator = $this->listQuery($request)->paginate($perPage);

        return view('admin.quotations.index', [
            'quotations' => $paginator->getCollection(),
            'total' => $paginator->total(),
            'perPage' => $perPage,
            'totalPages' => max(1, $paginator->lastPage()),
            'filters' => [
                'status' => (string) $request->query('status', 'all'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'q' => trim((string) $request->query('q', '')),
            ],
        ]);
    }

    public function rows(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Quotation::class);
        $paginator = $this->listQuery($request)
            ->paginate($this->dtPerPage($request), ['*'], 'page', $this->dtPage($request));

        return $this->dtRows($paginator, 'admin.quotations._rows', 'quotations');
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Quotation::class);

        $customer = $request->integer('customer_id')
            ? Customer::query()->where('is_active', true)->find($request->integer('customer_id'), ['id', 'name', 'phone', 'code'])
            : null;

        return view('admin.quotations.form', $this->editorPayload(selectedCustomer: $customer));
    }

    public function store(QuotationRequest $request, SaveQuotation $save): JsonResponse|RedirectResponse
    {
        $this->authorize('create', Quotation::class);
        abort_unless_store_access((int) $request->input('store_id'));
        abort_unless($request->user()->hasPermission('quotations.create', (int) $request->input('store_id')), 403);

        try {
            $quotation = $save->create($request->quotationData(), $request->user());
        } catch (RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect(
            $request,
            __('quotations.flash.created', ['number' => $quotation->displayNumber()]),
            route('admin.quotations.show', $quotation),
        );
    }

    public function edit(Quotation $quotation): View
    {
        $this->authorize('update', $quotation);
        $quotation->load(['items', 'customer:id,name,phone,code']);

        return view('admin.quotations.form', array_merge($this->editorPayload($quotation), [
            'quotation' => $quotation,
        ]));
    }

    public function update(QuotationRequest $request, Quotation $quotation, SaveQuotation $save): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $quotation);
        abort_unless_store_access((int) $request->input('store_id'));

        try {
            $quotation = $save->update($quotation, $request->quotationData(), $request->user());
        } catch (RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect(
            $request,
            __('quotations.flash.updated', ['number' => $quotation->displayNumber()]),
            route('admin.quotations.show', $quotation),
        );
    }

    public function preview(Request $request, BuildQuotationPricing $pricing): JsonResponse
    {
        $store = Store::query()->where('is_active', true)->find($request->integer('store_id'));
        if (! $store) {
            return response()->json(['message' => __('quotations.errors.store_required')], 422);
        }
        abort_unless_store_access((int) $store->id);
        abort_unless(
            $request->user()->hasPermission('quotations.create', (int) $store->id)
                || $request->user()->hasPermission('quotations.update', (int) $store->id),
            403,
        );

        try {
            $result = $pricing(
                array_values($request->input('items', [])),
                $store,
                $request->integer('customer_id') ?: null,
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'currency_code' => $store->currency_code,
            'lines' => array_map(fn ($item) => [
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
            ], $result['items']),
            'totals' => $result['totals'],
        ]);
    }

    public function scan(Request $request, ScannedProductResolver $resolver): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('quotations.create')
                || $request->user()->hasPermission('quotations.update'),
            403,
        );
        $barcode = trim((string) $request->query('barcode', ''));
        if ($barcode === '') {
            return response()->json(['message' => __('quotations.scan.empty')], 422);
        }
        $row = $resolver->resolve($barcode);

        return $row
            ? response()->json($row)
            : response()->json(['message' => __('quotations.scan.not_found', ['barcode' => $barcode])], 404);
    }

    public function show(Quotation $quotation): View
    {
        $this->authorize('view', $quotation);
        $quotation->load([
            'store', 'customer', 'creator:id,name', 'converter:id,name', 'convertedSale:id,number',
            'items', 'activities.actor:id,name', 'revisionOf:id,number,revision',
        ]);

        $latestRevision = Quotation::query()
            ->where('store_id', $quotation->store_id)
            ->where('number', $quotation->number)
            ->where('revision', '>', $quotation->revision)
            ->orderByDesc('revision')
            ->first(['id', 'number', 'revision', 'status']);
        if ($latestRevision && request()->user()->cannot('view', $latestRevision)) {
            $latestRevision = null;
        }

        return view('admin.quotations.show', [
            'quotation' => $quotation,
            'latestRevision' => $latestRevision,
        ]);
    }

    public function document(Quotation $quotation): View
    {
        $this->authorize('view', $quotation);
        $quotation->load(['store', 'items']);

        return view('quotations.document', [
            'quotation' => $quotation,
            'company' => Company::current() ?? new Company,
            'public' => false,
        ]);
    }

    public function pdf(Quotation $quotation, QuotationPdfRenderer $renderer): StreamedResponse
    {
        $this->authorize('view', $quotation);

        return $renderer->stream($quotation);
    }

    public function send(Request $request, Quotation $quotation, ChangeQuotationStatus $status): JsonResponse|RedirectResponse
    {
        $this->authorize('send', $quotation);
        try {
            $quotation = $status->send($quotation, $request->user());
            $quotation->ensurePublicLink();
        } catch (RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect($request, __('quotations.flash.sent'), route('admin.quotations.show', $quotation));
    }

    public function accept(Request $request, Quotation $quotation, ChangeQuotationStatus $status): JsonResponse|RedirectResponse
    {
        $this->authorize('send', $quotation);
        try {
            $quotation = $status->accept($quotation, $request->user());
        } catch (RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect($request, __('quotations.flash.accepted'), route('admin.quotations.show', $quotation));
    }

    public function decline(Request $request, Quotation $quotation, ChangeQuotationStatus $status): JsonResponse|RedirectResponse
    {
        $this->authorize('send', $quotation);
        try {
            $quotation = $status->decline($quotation, $request->user());
        } catch (RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect($request, __('quotations.flash.declined'), route('admin.quotations.show', $quotation));
    }

    public function cancel(Request $request, Quotation $quotation, ChangeQuotationStatus $status): JsonResponse|RedirectResponse
    {
        $this->authorize('cancel', $quotation);
        try {
            $quotation = $status->cancel($quotation, $request->user());
        } catch (RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect($request, __('quotations.flash.cancelled'), route('admin.quotations.show', $quotation));
    }

    public function revise(Request $request, Quotation $quotation, ReviseQuotation $revise): JsonResponse|RedirectResponse
    {
        $this->authorize('revise', $quotation);
        try {
            $revision = $revise($quotation, $request->user());
        } catch (RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect($request, __('quotations.flash.revised'), route('admin.quotations.edit', $revision));
    }

    public function convertForm(Quotation $quotation, ConvertQuotationToSale $convert): View|RedirectResponse
    {
        $this->authorize('convert', $quotation);
        $quotation->load(['store', 'items', 'customer']);

        try {
            $conversionTotals = $convert->preview($quotation, request()->user());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.quotations.show', $quotation)->with('error', $e->getMessage());
        }

        return view('admin.quotations.convert', [
            'quotation' => $quotation,
            'conversionTotals' => $conversionTotals,
            'paymentMethods' => PaymentMethod::query()
                ->where('is_active', true)
                ->where(fn (Builder $query) => $query
                    ->whereNull('provider')
                    ->orWhere('provider', '')
                    ->orWhere('provider', 'none'))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function convert(ConvertQuotationRequest $request, Quotation $quotation, ConvertQuotationToSale $convert): JsonResponse|RedirectResponse
    {
        $this->authorize('convert', $quotation);
        try {
            $sale = $convert($quotation, $request->paymentData(), $request->user());
        } catch (RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect(
            $request,
            __('quotations.flash.converted', ['number' => $sale->number]),
            route('admin.sales.show', $sale),
        );
    }

    /** @return Builder<Quotation> */
    private function listQuery(Request $request): Builder
    {
        $status = (string) $request->query('status', 'all');
        $storeId = resolved_store_id($request);
        $from = $request->query('from');
        $to = $request->query('to');
        $q = trim((string) $request->query('q', ''));
        $user = $request->user();
        $canViewAll = $user->hasPermission('quotations.view_all', $storeId);
        $canViewOwn = $user->hasPermission('quotations.view_own', $storeId);
        abort_unless($canViewAll || $canViewOwn, 403);

        return Quotation::query()
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->when(! $canViewAll, fn ($query) => $query->where('created_by', $user->id))
            ->when($status === 'expired', fn ($query) => $query
                ->whereIn('status', [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT])
                ->whereDate('valid_until', '<', today()))
            ->when($status !== 'all' && $status !== 'expired', function ($query) use ($status) {
                $query->where('status', $status);
                if (in_array($status, [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT], true)) {
                    $query->where(fn ($dates) => $dates->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()));
                }
            })
            ->when($from, fn ($query) => $query->whereDate('issue_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('issue_date', '<=', $to))
            ->when($q !== '', fn ($query) => $query->where(function ($search) use ($q) {
                $search->where('number', 'like', "%{$q}%")
                    ->orWhere('customer_snapshot', 'like', "%{$q}%")
                    ->orWhereHas('items', fn ($items) => $items
                        ->where('product_name_snapshot', 'like', "%{$q}%")
                        ->orWhere('sku_snapshot', 'like', "%{$q}%"));
            }))
            ->with(['store:id,name,code', 'customer:id,name', 'creator:id,name'])
            ->orderByDesc('issue_date')
            ->orderByDesc('id');
    }

    /** @return array<string, mixed> */
    private function editorPayload(?Quotation $quotation = null, ?Customer $selectedCustomer = null): array
    {
        $user = request()->user();
        $stores = accessible_stores()
            ->filter(fn (Store $store) => $quotation
                ? (int) $store->id === (int) $quotation->store_id
                : $user->hasPermission('quotations.create', (int) $store->id))
            ->values();

        return [
            'stores' => $stores,
            'defaultStoreId' => $quotation?->store_id ?? active_store_id(),
            'selectedCustomer' => $quotation?->customer ?? $selectedCustomer,
            'initialItems' => $quotation?->items?->map(fn ($item) => [
                'product_id' => (string) $item->product_id,
                'variant_id' => $item->variant_id ? (string) $item->variant_id : '',
                'product_label' => $item->product_name_snapshot.($item->variant_name_snapshot ? ' — '.$item->variant_name_snapshot : ''),
                'quantity' => (string) $item->quantity,
                'discount_percent' => (string) $item->discount_percent,
                'unit_price' => (string) $item->unit_price,
                'line_total' => (string) $item->line_total,
                'notes' => $item->notes,
            ])->values()->all() ?? [],
        ];
    }
}
