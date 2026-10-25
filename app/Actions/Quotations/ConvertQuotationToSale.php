<?php

namespace App\Actions\Quotations;

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\PriceCart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ConvertQuotationToSale
{
    public function __construct(
        private readonly CompleteSale $completeSale,
        private readonly PriceCart $priceCart,
    ) {}

    /** @return array{subtotal: string, discount_total: string, tax_total: string, grand_total: string} */
    public function preview(Quotation $quotation, User $actor): array
    {
        $this->assertConvertible($quotation);
        $quotation->loadMissing('items');
        $products = Product::query()
            ->with(['taxGroup.components', 'category.taxGroup.components'])
            ->whereIn('id', $quotation->items->pluck('product_id')->all())
            ->get()
            ->keyBy('id');
        $variants = ProductVariant::query()
            ->whereIn('id', $quotation->items->pluck('variant_id')->filter()->all())
            ->get()
            ->keyBy('id');
        $store = Store::query()->where('is_active', true)->find($quotation->store_id);
        if (! $store) {
            throw new RuntimeException(__('quotations.errors.store_unavailable'));
        }
        $resolved = [];
        foreach ($quotation->items->values() as $index => $item) {
            $product = $products->get($item->product_id);
            if (! $product || ! $product->is_active) {
                throw new RuntimeException(__('quotations.errors.product_unavailable'));
            }
            $variant = $item->variant_id ? $variants->get($item->variant_id) : null;
            if ($item->variant_id && (! $variant || ! $variant->is_active || (int) $variant->product_id !== (int) $product->id)) {
                throw new RuntimeException(__('quotations.errors.variant_unavailable'));
            }
            $resolved[] = [
                'product' => $product,
                'index' => $index,
                'raw' => [
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'discount_amount' => (string) $item->discount_amount,
                    'discount_percent' => (string) $item->discount_percent,
                ],
            ];
        }

        $priced = $this->priceCart->handle($resolved, $store, $actor, honorProvidedUnitPrices: true);

        return [
            'subtotal' => $priced->subtotal,
            'discount_total' => $priced->discountTotal,
            'tax_total' => $priced->taxTotal,
            'grand_total' => $priced->grandTotal,
        ];
    }

    /** @param array<int, array<string, mixed>> $payments */
    public function __invoke(Quotation $quotation, array $payments, User $actor): Sale
    {
        return DB::transaction(function () use ($quotation, $payments, $actor) {
            $locked = Quotation::query()->lockForUpdate()->with('items')->findOrFail($quotation->id);

            if ($locked->converted_sale_id) {
                return Sale::query()->findOrFail($locked->converted_sale_id);
            }
            $this->assertConvertible($locked);
            if (! Store::query()->whereKey($locked->store_id)->where('is_active', true)->exists()) {
                throw new RuntimeException(__('quotations.errors.store_unavailable'));
            }

            $this->assertItemsAvailable($locked);

            $lines = $locked->items->map(fn ($item) => [
                'product_id' => (int) $item->product_id,
                'variant_id' => $item->variant_id ? (int) $item->variant_id : null,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'discount_amount' => (string) $item->discount_amount,
                'discount_percent' => (string) $item->discount_percent,
                'notes' => $item->notes,
            ])->all();

            $sale = ($this->completeSale)([
                'store_id' => (int) $locked->store_id,
                'customer_id' => $locked->customer_id ? (int) $locked->customer_id : null,
                'currency_code' => $locked->currency_code,
                'sale_date' => now()->toDateString(),
                'local_uuid' => (string) Str::uuid(),
                'notes' => __('quotations.conversion.sale_note', ['number' => $locked->displayNumber()]),
                'origin' => 'admin',
            ], $lines, $payments, $actor, honorProvidedUnitPrices: true);

            $locked->forceFill([
                'status' => Quotation::STATUS_CONVERTED,
                'converted_sale_id' => $sale->id,
                'converted_at' => now(),
                'converted_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();
            $locked->activities()->create([
                'actor_id' => $actor->id,
                'type' => 'converted',
                'meta' => ['sale_id' => $sale->id, 'sale_number' => $sale->number],
            ]);

            return $sale;
        });
    }

    private function assertItemsAvailable(Quotation $quotation): void
    {
        $activeProducts = Product::query()
            ->where('is_active', true)
            ->whereIn('id', $quotation->items->pluck('product_id')->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $activeProductIds = array_flip($activeProducts);

        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->whereIn('id', $quotation->items->pluck('variant_id')->filter()->all())
            ->get(['id', 'product_id'])
            ->keyBy('id');

        foreach ($quotation->items as $item) {
            if (! isset($activeProductIds[(int) $item->product_id])) {
                throw new RuntimeException(__('quotations.errors.product_unavailable'));
            }
            if ($item->variant_id) {
                $variant = $variants->get($item->variant_id);
                if (! $variant || (int) $variant->product_id !== (int) $item->product_id) {
                    throw new RuntimeException(__('quotations.errors.variant_unavailable'));
                }
            }
        }
    }

    private function assertConvertible(Quotation $quotation): void
    {
        if (! $quotation->canConvert()) {
            throw new RuntimeException(__('quotations.errors.cannot_convert_status', [
                'status' => __('quotations.statuses.'.$quotation->effectiveStatus()),
            ]));
        }
    }
}
