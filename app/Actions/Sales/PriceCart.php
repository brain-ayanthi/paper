<?php

namespace App\Actions\Sales;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Tax\TaxResolver;
use App\Support\PricedCart;
use Illuminate\Support\Facades\Log;

/**
 * The single source of truth for what a cart costs.
 *
 * This is the per-line tax/total math lifted verbatim out of
 * {@see CompleteSale} so that EVERY place which needs an authoritative
 * total — the sale itself, and the QR/gateway charge amount — runs the
 * exact same code. Previously the cashier JS computed the grand total
 * with a simplified single-rate, float-accumulated estimate, while the
 * server computed it per-component with per-line 4dp rounding; the two
 * could disagree by a paisa or two, which in the QR flow meant the
 * gateway charged one number while the recorded sale held another.
 *
 * Pure pricing only — no stock locks, no DB writes, no side effects.
 * Callers resolve their own Product models (CompleteSale also locks
 * stock; the quote path doesn't need to).
 *
 * The math MUST stay byte-for-byte identical to what CompleteSale used
 * to do inline — see the matching scale-8-then-round-4-dp convention.
 */
class PriceCart
{
    public function __construct(
        private readonly TaxResolver $taxResolver,
        private readonly \App\Actions\Products\ResolveSalePrice $resolvePrice,
    ) {}

    /**
     * The authoritative unit price for a line — SEC #2 (defect #15).
     *
     * Checkout used the `unit_price` the browser sent, with no server-side
     * check at all: a tampered client (or anyone posting to the endpoint)
     * could sell a $500 item for $0.01, and the sale recorded the fake price
     * as fact. This is the till's single most valuable guarantee, so the price
     * is now always resolved here and the client's figure is only honoured by
     * someone holding `sales.adjust_price`.
     *
     * The resolution mirrors the cashier's own logic exactly, and the batch
     * step is not optional: for batch-tracked stock the SELLING PRICE LIVES ON
     * THE BATCH (pharmacy MRP differs per batch), so resolving purely from the
     * product would quietly re-price every pharmacy line.
     */
    private function resolveUnitPrice(array $raw, Product $product, Store $store): string
    {
        $batchId = isset($raw['batch_id']) && $raw['batch_id'] !== '' ? (int) $raw['batch_id'] : null;

        $variantId = isset($raw['variant_id']) && $raw['variant_id'] !== '' ? (int) $raw['variant_id'] : null;
        $variant   = $variantId !== null ? ProductVariant::query()->find($variantId) : null;

        return ($this->resolvePrice)($product, $store->id, $variant, $batchId);
    }

    /**
     * @param array<int, array{raw: array<string, mixed>, product: \App\Models\Product, index: int}> $resolvedLines
     */
    public function handle(
        array $resolvedLines,
        Store $store,
        ?\App\Models\User $actor = null,
        bool $honorProvidedUnitPrices = false,
    ): PricedCart
    {
        // Only a holder of `sales.adjust_price` may override the catalogue
        // price; for everyone else the server's figure wins (SEC #2).
        $mayAdjustPrice = $actor?->hasPermission('sales.adjust_price') ?? false;

        $subtotal      = '0';
        $discountTotal = '0';
        $taxTotal      = '0';
        $lines         = [];

        foreach ($resolvedLines as $resolved) {
            /** @var \App\Models\Product $product */
            $product = $resolved['product'];
            $raw     = $resolved['raw'];
            $i       = $resolved['index'];

            $qty     = (string) ($raw['quantity']         ?? '0');
            $discPct = (string) ($raw['discount_percent'] ?? '0');
            $discAmt = (string) ($raw['discount_amount']  ?? '0');

            // Server-resolved price (SEC #2). The client's value is accepted
            // only from someone permitted to set one, and is logged when it
            // actually differs so an override is never silent.
            $unit       = $this->resolveUnitPrice($raw, $product, $store);
            $clientUnit = isset($raw['unit_price']) ? (string) $raw['unit_price'] : null;

            if (($mayAdjustPrice || $honorProvidedUnitPrices)
                && $clientUnit !== null
                && bccomp($clientUnit, $unit, 4) !== 0) {
                Log::notice($honorProvidedUnitPrices ? 'Quotation price honored on sale conversion.' : 'Manual sale price override.', [
                    'user_id'    => $actor?->id,
                    'store_id'   => $store->id,
                    'product_id' => $product->id,
                    'catalogue'  => $unit,
                    'charged'    => $clientUnit,
                ]);

                $unit = $clientUnit;
            }

            $gross = bcmul($qty, $unit, 8);

            // Discount: amount wins if both set, else percent of gross.
            if (bccomp($discAmt, '0', 8) > 0) {
                $discount = $discAmt;
            } elseif (bccomp($discPct, '0', 8) > 0) {
                $discount = bcdiv(bcmul($gross, $discPct, 8), '100', 8);
            } else {
                $discount = '0';
            }

            // Tax via the canonical resolver — inclusive/exclusive,
            // multi-component, and classification short-circuits all
            // live there. Pass discount so the taxable base is net.
            $breakdown = $this->taxResolver->resolveForLine(
                product:    $product,
                unitPrice:  $unit,
                quantity:   $qty,
                discount:   $this->round4($discount),
                store:      $store,
            );

            // Net (taxable) amount per line:
            //   - exclusive: net = gross − discount (tax adds on top)
            //   - inclusive: net = the back-extracted taxable amount the
            //     resolver already computed (using gross here would
            //     double-count the embedded tax).
            $netRounded = $breakdown->isInclusive
                ? $breakdown->taxableAmount()
                : $this->round4(bcsub($gross, $discount, 8));
            $taxRounded = $breakdown->total;
            $lineTotal  = bcadd($netRounded, $taxRounded, 4);

            // Write the authoritative price back onto the line before it
            // leaves here. Downstream consumers — the sale_items insert, the
            // discount-percentage calculation in CompleteSale — read `raw`,
            // and if they kept reading the client's figure the TOTALS would be
            // correct while the RECORDED line still showed the tampered price
            // (SEC #2). Fixing it at the single point every consumer shares
            // beats asking each of them to remember.
            $raw['unit_price'] = $this->round4($unit);

            $lines[] = [
                'index'      => $i,
                'product'    => $product,
                'raw'        => $raw,
                // Passthrough — the caller resolves cost (it has the locked
                // stock row and the chosen batch); pricing has no opinion on
                // it, but the consumer that writes sale_items needs it here.
                'unit_cost'  => $resolved['unit_cost'] ?? '0',
                'net'        => $netRounded,
                'tax'        => $taxRounded,
                'line_total' => $lineTotal,
                'discount'   => $discount,
                'breakdown'  => $breakdown,
            ];

            $subtotal      = bcadd($subtotal,      $netRounded,              4);
            $discountTotal = bcadd($discountTotal, $this->round4($discount), 4);
            $taxTotal      = bcadd($taxTotal,      $taxRounded,              4);
        }

        $grandTotal = bcadd($subtotal, $taxTotal, 4);

        return new PricedCart(
            lines:         $lines,
            subtotal:      $subtotal,
            discountTotal: $discountTotal,
            taxTotal:      $taxTotal,
            grandTotal:    $grandTotal,
        );
    }

    /** Delegates to the ONE shared rounding (code-review #31). */
    private function round4(string $v): string
    {
        return \App\Support\Money::round($v);
    }
}
