<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Inventory\ResolveFefoBatch;
use App\Actions\Products\ResolveSalePrice;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\PriceCart;
use App\Exceptions\InsufficientStock;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminSaleRequest;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Store;
use App\Services\Barcodes\ScannedProductResolver;
use App\Services\Customers\CustomerDiscountResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Back-office sale creation — raising a sale without opening the cashier.
 *
 * Lives apart from {@see SaleController} (which already carries the whole
 * cashier surface) purely for size; the routes are still `admin.sales.*`.
 *
 * Three deliberate differences from the cashier's complete endpoint:
 *
 *  1. **Prices are resolved here, never accepted from the form.** A price
 *     typed into a line would bypass the `sales.discount` permission and
 *     the store threshold entirely, since a markdown expressed as a price
 *     isn't recorded as a discount. Markdowns go through `discount_percent`
 *     where the existing governance still applies.
 *  2. **Batches are picked FEFO**, so an operator raising an invoice never
 *     has to think about them. {@see CompleteSale} never auto-resolves.
 *  3. **`origin = admin`**, which exempts the sale from per-store till-shift
 *     enforcement — the back office has no drawer open, which is the point
 *     of the screen. The trade-off is real and surfaced in the UI: cash
 *     taken here sits outside the drawer count and the Z-report.
 */
class SaleCreateController extends Controller
{
    use RespondsJsonOrRedirect;

    public function create(): View
    {
        $this->authorize('create', Sale::class);

        return view('admin.sales.create', $this->editorPayload());
    }

    /**
     * Barcode → product/variant, for the scan field on the create form.
     * Same resolver (and so the same variant-barcode-wins rule) the purchase
     * and inventory editors use; only the permission differs.
     */
    public function scan(Request $request, ScannedProductResolver $resolver): JsonResponse
    {
        $this->authorize('create', Sale::class);

        $barcode = trim((string) $request->query('barcode', ''));
        if ($barcode === '') {
            return response()->json(['message' => __('sales.create.scan.empty')], 422);
        }

        $row = $resolver->resolve($barcode);
        if ($row === null) {
            return response()->json([
                'message' => __('sales.create.scan.not_found', ['barcode' => $barcode]),
            ], 404);
        }

        return response()->json($row);
    }

    /**
     * Live totals for the form. Runs the exact same {@see PriceCart} the
     * recorded sale runs, so the preview can never disagree with what gets
     * saved — the reason this is a server round-trip rather than client
     * arithmetic (product-driven tax groups and inclusive pricing aren't
     * knowable from the DOM).
     */
    public function quote(
        Request $request,
        ResolveSalePrice $resolvePrice,
        ResolveFefoBatch $resolveBatch,
        PriceCart $priceCart,
    ): JsonResponse
    {
        $this->authorize('create', Sale::class);

        $store = Store::query()->where('is_active', true)->find($request->integer('store_id'));
        if (! $store) {
            return response()->json(['ok' => false, 'message' => __('sales.create.errors.store_required')], 422);
        }
        // `create` is a class-level ability, so the posted store_id was never
        // checked against the actor's stores (SEC #103 — the create-path
        // counterpart of the #90 umbrella).
        abort_unless_store_access((int) $store->id);

        $items = array_values($request->input('items', []));
        if ($items === []) {
            return response()->json([
                'ok'    => true,
                'lines' => [],
                'totals' => [
                    'subtotal' => '0', 'discount_total' => '0',
                    'tax_total' => '0', 'grand_total' => '0',
                ],
            ]);
        }

        try {
            $lines = $this->resolveLines(
                $items,
                $store,
                $resolvePrice,
                $resolveBatch,
                customerId: $request->integer('customer_id') ?: null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        $resolved = [];
        foreach ($lines as $i => $line) {
            $product = Product::find($line['product_id']);
            if (! $product) {
                continue;
            }
            $resolved[] = ['product' => $product, 'raw' => $line, 'index' => $i];
        }

        $priced = $priceCart->handle($resolved, $store);

        return response()->json([
            'ok'    => true,
            'lines' => array_map(fn (array $l) => [
                'unit_price' => (string) ($lines[$l['index']]['unit_price'] ?? '0'),
                'discount'   => (string) $l['discount'],
                'tax'        => (string) $l['tax'],
                'line_total' => (string) $l['line_total'],
            ], $priced->lines),
            'totals' => [
                'subtotal'       => $priced->subtotal,
                'discount_total' => $priced->discountTotal,
                'tax_total'      => $priced->taxTotal,
                'grand_total'    => $priced->grandTotal,
            ],
        ]);
    }

    public function store(
        AdminSaleRequest $request,
        CompleteSale $complete,
        ResolveSalePrice $resolvePrice,
        ResolveFefoBatch $resolveBatch,
    ): JsonResponse|RedirectResponse {
        $this->authorize('create', Sale::class);

        $header = $request->headerData();
        $store  = Store::query()->where('is_active', true)->find($header['store_id']);
        if (! $store) {
            return $this->jsonOrError($request, __('sales.create.errors.store_required'));
        }
        // SEC #103 — without this a user assigned only to Store B could POST
        // store_id = Store A and ring up a sale there, decrementing Store A's
        // stock and posting Store A revenue.
        abort_unless_store_access((int) $store->id);

        try {
            $lines = $this->resolveLines(
                $request->lineData(),
                $store,
                $resolvePrice,
                $resolveBatch,
                customerId: $header['customer_id'] ?? null,
            );
            $sale = $complete($header, $lines, $request->paymentData(), $request->user());
        } catch (InsufficientStock $e) {
            return $this->jsonOrError($request, __('sales.errors.insufficient_stock', [
                'name'      => $e->productName,
                'available' => $e->available,
                'requested' => $e->requested,
            ]));
        } catch (\App\Exceptions\ExpiredBatchSale $e) {
            return $this->jsonOrError($request, __('sales.errors.expired_batch_sale', [
                'name'   => $e->productName,
                'batch'  => $e->batchNumber,
                'expiry' => $e->expiryDate,
            ]));
        } catch (\App\Exceptions\DiscountNotAllowed | \App\Exceptions\DiscountAboveThreshold $e) {
            return $this->jsonOrError($request, $e->getMessage());
        } catch (RuntimeException $e) {
            // Covers CreditRequiresCustomer, CreditLimitExceeded, over-payment
            // and the empty-cart guards — all already carry a translated message.
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect(
            $request,
            __('sales.flash.completed', ['number' => $sale->number]),
            route('admin.sales.show', $sale),
        );
    }

    /**
     * Turn the form's `{product_id, variant_id, quantity, discount_percent}`
     * rows into the shape {@see CompleteSale} expects, stamping the catalog
     * price and (when asked) a FEFO batch onto each.
     *
     * Shared by {@see quote()} and {@see store()} so the previewed total and
     * the recorded one are computed from identical inputs.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function resolveLines(
        array $items,
        Store $store,
        ResolveSalePrice $resolvePrice,
        ResolveFefoBatch $resolveBatch,
        ?int $customerId = null,
    ): array {
        // The customer's automatic discount (their own percent, else their
        // group's). The cashier folds this into each line's discount the
        // same way; doing it here is what makes a back-office sale honour
        // the tier instead of quietly charging list price.
        $customerPct = app(CustomerDiscountResolver::class)->percentForId($customerId);
        $hasCustomerDiscount = bccomp($customerPct, '0', 4) > 0;

        $productIds = array_filter(array_map(fn ($i) => $i['product_id'] ?? null, $items));
        $variantIds = array_filter(array_map(fn ($i) => $i['variant_id'] ?? null, $items));

        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $variants = $variantIds !== []
            ? ProductVariant::query()->whereIn('id', $variantIds)->get()->keyBy('id')
            : collect();

        $company          = Company::current();
        $blockExpired     = (bool) ($company->block_expired_batch_sale ?? false);
        $maySellExpired   = auth()->user()?->hasPermission('inventory.sell_expired') ?? false;

        $lines = [];
        foreach ($items as $raw) {
            $product = $products->get((int) ($raw['product_id'] ?? 0));
            if (! $product) {
                continue;
            }

            $variantId = ! empty($raw['variant_id']) ? (int) $raw['variant_id'] : null;
            $variant   = $variantId ? $variants->get($variantId) : null;

            $batch = null;
            if ($product->track_batches) {
                $quantity = (string) ($raw['quantity'] ?? '0');
                $batch = $resolveBatch(
                    (int) $store->id,
                    (int) $product->id,
                    $variantId,
                    excludeExpired: $blockExpired && ! $maySellExpired,
                    requiredQuantity: $quantity,
                );
                if (! $batch) {
                    throw new RuntimeException(__('sales.errors.invalid_batch', ['name' => $product->name]));
                }
            }

            $price = $resolvePrice($product, $store->id, $variant, $batch?->id);

            $line = [
                'product_id'       => (int) $product->id,
                'variant_id'       => $variantId,
                'quantity'         => (string) ($raw['quantity'] ?? '0'),
                // Authoritative — the form never sends a price.
                'unit_price'       => (string) $price,
                'discount_percent' => (string) ($raw['discount_percent'] ?? '0'),
                'notes'            => $raw['notes'] ?? null,
            ];
            if ($batch) {
                $line['batch_id'] = $batch->id;
            }

            // PriceCart honours `discount_amount` OVER `discount_percent`
            // (they don't stack), so when a customer discount applies we
            // resolve the whole line discount to an amount ourselves:
            // the line's own percent first, then the customer's percent on
            // what's left. Untouched when there's no customer discount, so
            // the existing percent path stays exactly as it was.
            if ($hasCustomerDiscount) {
                $gross = bcmul($line['quantity'], $line['unit_price'], 8);
                $own   = bcdiv(bcmul($gross, $line['discount_percent'], 8), '100', 8);
                $net   = bcsub($gross, $own, 8);
                $cust  = bcdiv(bcmul($net, $customerPct, 8), '100', 8);

                $line['discount_amount'] = bcadd(bcadd($own, $cust, 8), '0', 4);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function editorPayload(): array
    {
        return [
            'stores'         => accessible_stores(),
            'paymentMethods' => PaymentMethod::query()
                ->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'type', 'requires_reference']),
            'defaultStoreId' => active_store_id(),
        ];
    }
}
