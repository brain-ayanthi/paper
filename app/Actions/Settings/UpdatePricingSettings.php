<?php

namespace App\Actions\Settings;

use App\Actions\Products\ResolveProductPrice;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Support\Facades\DB;

/** Persist pricing settings and safely initialise incomplete legacy batches. */
class UpdatePricingSettings
{
    public function __construct(private readonly ResolveProductPrice $resolveProductPrice) {}

    /** @param array{pricing_method:string,auto_apply_markup_on_receive:bool} $data */
    public function __invoke(Company $company, array $data): Company
    {
        return DB::transaction(function () use ($company, $data) {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $data = apply_filters('company.attributes', $data, $company);
            do_action('company.before_update', $company, $data);

            if (($data['pricing_method'] ?? $company->pricing_method) === 'batch_wise') {
                // Run on every transition/save in batch mode. This is
                // idempotent per batch: valid positive values are preserved,
                // while batches created during a product-pricing interval get
                // their missing snapshots when batch pricing is re-enabled.
                $this->snapshotIncompleteBatches();
                if ($company->batch_pricing_initialized_at === null) {
                    $data['batch_pricing_initialized_at'] = now();
                }
            }

            $company->forceFill($data)->save();
            do_action('company.after_update', $company);

            return $company->refresh();
        });
    }

    private function snapshotIncompleteBatches(): void
    {
        $batches = ProductBatch::query()
            ->withTrashed()
            ->lockForUpdate()
            ->get(['id', 'store_id', 'product_id', 'variant_id', 'cost_price', 'selling_price', 'mrp']);

        if ($batches->isEmpty()) {
            return;
        }

        $products = Product::query()
            ->withTrashed()
            ->whereIn('id', $batches->pluck('product_id')->unique())
            ->get()
            ->keyBy('id');
        $variants = ProductVariant::query()
            ->withTrashed()
            ->whereIn('id', $batches->pluck('variant_id')->filter()->unique())
            ->get()
            ->keyBy('id');
        $costs = StockLevel::query()
            ->whereIn('store_id', $batches->pluck('store_id')->unique())
            ->whereIn('product_id', $batches->pluck('product_id')->unique())
            ->get(['store_id', 'product_id', 'variant_id', 'weighted_average_cost'])
            ->keyBy(fn (StockLevel $level) => $this->key(
                (int) $level->store_id,
                (int) $level->product_id,
                $level->variant_id ? (int) $level->variant_id : null,
            ));

        $resolved = [];
        foreach ($batches as $batch) {
            $product = $products->get($batch->product_id);
            if (! $product) {
                continue;
            }

            $variant = $batch->variant_id ? $variants->get($batch->variant_id) : null;
            $key = $this->key((int) $batch->store_id, (int) $batch->product_id, $batch->variant_id);
            $prices = $resolved[$key] ??= ($this->resolveProductPrice)(
                $product,
                (int) $batch->store_id,
                $variant,
            );

            $attributes = [];
            if (($batch->selling_price === null || bccomp((string) $batch->selling_price, '0', 4) <= 0)
                && bccomp((string) $prices['selling_price'], '0', 4) > 0) {
                $attributes['selling_price'] = $prices['selling_price'];
            }
            if (($batch->mrp === null || bccomp((string) $batch->mrp, '0', 4) <= 0)
                && $prices['mrp'] !== null && bccomp((string) $prices['mrp'], '0', 4) > 0) {
                $attributes['mrp'] = $prices['mrp'];
            }
            if ($batch->cost_price === null || bccomp((string) $batch->cost_price, '0', 4) <= 0) {
                $wac = $costs->get($key)?->weighted_average_cost;
                $cost = $wac !== null && bccomp((string) $wac, '0', 4) > 0
                    ? (string) $wac
                    : $prices['cost_price'];
                if (bccomp((string) $cost, '0', 4) > 0) {
                    $attributes['cost_price'] = $cost;
                }
            }

            if ($attributes !== []) {
                $batch->forceFill($attributes)->save();
            }
        }
    }

    private function key(int $storeId, int $productId, ?int $variantId): string
    {
        return $storeId.':'.$productId.':'.($variantId ?? 0);
    }
}
