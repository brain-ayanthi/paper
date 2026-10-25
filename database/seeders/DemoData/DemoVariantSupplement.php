<?php

namespace Database\Seeders\DemoData;

use App\Actions\Inventory\RecordStockMovement;
use App\Models\ProductLocation;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoVariantSupplement
{
    /** @return array{inserted:int, existing:int, converted:int, parents:int, stores:int} */
    public function run(?Command $command = null): array
    {
        if (! config('demo_variants.authorized', false)) {
            throw new RuntimeException('Use pos:demo-add-product-variants in demo maintenance mode.');
        }

        $actorId = DB::table('users')->where('is_super_admin', true)->value('id');
        $stores = DB::table('stores')->whereNull('deleted_at')->where('is_active', true)->orderBy('id')->get();
        if (! $actorId || $stores->isEmpty()) {
            throw new RuntimeException('Requires a super admin and at least one active store.');
        }

        $existingVariantIds = DB::table('product_variants')->orderBy('id')->pluck('id')->all();
        $existingVariants = $this->fingerprint('product_variants', $existingVariantIds);
        $existingImages = $this->fingerprint('product_images');
        $inserted = $existing = $converted = 0;
        $definitions = $this->definitions();

        DB::transaction(function () use (
            $command, $actorId, $stores, $definitions,
            $existingVariantIds, $existingVariants, $existingImages, &$inserted, &$existing, &$converted,
        ): void {
            $command?->info('Extending six existing variant products without changing current child variants or images.');
            foreach ($definitions as $definition) {
                $product = DB::table('products')
                    ->where('sku', $definition['sku'])
                    ->whereNull('deleted_at')
                    ->first();
                if (! $product || $product->type !== 'variant') {
                    throw new RuntimeException("Required variant parent [{$definition['sku']}] was not found.");
                }

                foreach ($definition['new'] as $row) {
                    $variantSku = $definition['sku'].'-'.$row['ordinal'];
                    $found = DB::table('product_variants')->where('sku', $variantSku)->first();
                    if ($found) {
                        if ((int) $found->product_id !== (int) $product->id
                            || $found->deleted_at !== null
                            || $this->normalizeCombo(json_decode((string) $found->attributes, true)) !== $this->normalizeCombo($row['combo'])) {
                            throw new RuntimeException("Variant SKU collision for [{$variantSku}]; no rows were changed.");
                        }
                        $existing++;

                        continue;
                    }
                    if ($this->comboExists((int) $product->id, $row['combo'])) {
                        throw new RuntimeException("Variant combination collision for [{$variantSku}]; no rows were changed.");
                    }

                    $factor = bcadd('1', bcmul((string) ($row['ordinal'] - 1), '0.15', 4), 4);
                    $cost = Money::round(bcmul((string) $product->cost_price, $factor, 8));
                    $price = Money::round(bcmul((string) $product->selling_price, $factor, 8));
                    $mrp = Money::round(bcmul($price, '1.10', 8));
                    $variantId = DB::table('product_variants')->insertGetId([
                        'product_id' => $product->id,
                        'sku' => $variantSku,
                        'barcode' => $variantSku,
                        'attributes' => json_encode($row['combo'], JSON_THROW_ON_ERROR),
                        'cost_price' => $cost,
                        'selling_price' => $price,
                        'mrp' => $mrp,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    foreach ($stores as $store) {
                        $this->seedStore($store, $product, $variantId, $row['ordinal'], $cost, $price, $mrp, $actorId);
                    }
                    $inserted++;
                }

                $meta = json_decode((string) $product->meta, true) ?: [];
                $meta['variant_attributes'] = $definition['attributes'];
                $encodedMeta = json_encode($meta, JSON_THROW_ON_ERROR);
                if ((string) $product->meta !== $encodedMeta) {
                    DB::table('products')->where('id', $product->id)->update([
                        'meta' => $encodedMeta,
                        'updated_at' => now(),
                    ]);
                }
            }

            $command?->info('Converting 18 existing batch-tracked products into four-variant products.');
            foreach (RealisticVariantConversions::products() as $definition) {
                $product = DB::table('products')
                    ->where('sku', $definition['sku'])
                    ->whereNull('deleted_at')
                    ->first();
                if (! $product) {
                    throw new RuntimeException("Required product [{$definition['sku']}] was not found.");
                }

                if ($product->type === 'variant') {
                    $existing += $this->verifyConvertedProduct($product, $definition);

                    continue;
                }
                if ($product->type !== 'simple' || ! $product->track_batches) {
                    throw new RuntimeException("Product [{$definition['sku']}] must be a batch-tracked simple product.");
                }

                $inserted += $this->convertProduct($product, $definition, $stores, (int) $actorId);
                $converted++;
            }
            $command?->info("Product variants: {$inserted} added, {$existing} already present.");

            if ($existingVariants !== $this->fingerprint('product_variants', $existingVariantIds)
                || $existingImages !== $this->fingerprint('product_images')) {
                throw new RuntimeException('An existing variant or product image changed; variant supplement rolled back.');
            }
        });

        return [
            'inserted' => $inserted,
            'existing' => $existing,
            'converted' => $converted,
            'parents' => count($definitions) + count(RealisticVariantConversions::products()),
            'stores' => $stores->count(),
        ];
    }

    private function convertProduct(object $product, array $definition, object $stores, int $actorId): int
    {
        foreach ($stores as $store) {
            $level = DB::table('product_stock_levels')
                ->where('store_id', $store->id)
                ->where('product_id', $product->id)
                ->whereNull('variant_id')
                ->first();
            if ($level && bccomp((string) $level->reserved_quantity, '0', 4) !== 0) {
                throw new RuntimeException("Product [{$definition['sku']}] has reserved stock in store [{$store->code}].");
            }
            $levelQuantity = (string) ($level?->quantity ?? '0');
            $batchQuantity = (string) DB::table('product_batches')
                ->where('store_id', $store->id)
                ->where('product_id', $product->id)
                ->whereNull('variant_id')
                ->whereNull('deleted_at')
                ->sum('quantity');
            if (bccomp($levelQuantity, $batchQuantity, 4) !== 0) {
                throw new RuntimeException("Product [{$definition['sku']}] has a batch/stock mismatch in store [{$store->code}].");
            }
        }

        $variants = [];
        $ordinal = 0;
        foreach ($definition['attributes'][0]['values'] as $first) {
            foreach ($definition['attributes'][1]['values'] as $second) {
                $combo = [
                    $definition['attributes'][0]['name'] => $first,
                    $definition['attributes'][1]['name'] => $second,
                ];
                $variantSku = $definition['sku'].'-'.(++$ordinal);
                if (DB::table('product_variants')->where('sku', $variantSku)->exists()) {
                    throw new RuntimeException("Variant SKU collision for [{$variantSku}]; no rows were changed.");
                }
                $factor = $definition['multipliers'][$ordinal - 1];
                $cost = Money::round(bcmul((string) $product->cost_price, $factor, 8));
                $price = Money::round(bcmul((string) $product->selling_price, $factor, 8));
                $mrp = Money::round(bcmul($price, '1.10', 8));
                $variantId = DB::table('product_variants')->insertGetId([
                    'product_id' => $product->id,
                    'sku' => $variantSku,
                    'barcode' => $variantSku,
                    'attributes' => json_encode($combo, JSON_THROW_ON_ERROR),
                    'cost_price' => $cost,
                    'selling_price' => $price,
                    'mrp' => $mrp,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $variants[] = compact('variantId', 'ordinal', 'cost', 'price', 'mrp');
            }
        }

        foreach ($stores as $store) {
            foreach ($variants as $variant) {
                $this->seedConvertedStore($store, $product, $variant);
            }
            $this->moveBaseBatches($store, $product, $variants, $actorId);
        }

        $meta = json_decode((string) $product->meta, true) ?: [];
        $meta['variant_attributes'] = $definition['attributes'];
        $meta['demo_variant_conversion'] = true;
        DB::table('products')->where('id', $product->id)->update([
            'name' => $definition['brand'].' '.$definition['name'],
            'type' => 'variant',
            'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        return count($variants);
    }

    private function verifyConvertedProduct(object $product, array $definition): int
    {
        $expected = [];
        $ordinal = 0;
        foreach ($definition['attributes'][0]['values'] as $first) {
            foreach ($definition['attributes'][1]['values'] as $second) {
                $expected[$definition['sku'].'-'.(++$ordinal)] = [
                    $definition['attributes'][0]['name'] => $first,
                    $definition['attributes'][1]['name'] => $second,
                ];
            }
        }
        $found = DB::table('product_variants')
            ->where('product_id', $product->id)
            ->whereNull('deleted_at')
            ->get();
        if ($found->count() !== count($expected)) {
            throw new RuntimeException("Converted product [{$definition['sku']}] has an unexpected variant count.");
        }
        foreach ($found as $variant) {
            if (! isset($expected[$variant->sku])
                || $this->normalizeCombo(json_decode((string) $variant->attributes, true)) !== $this->normalizeCombo($expected[$variant->sku])) {
                throw new RuntimeException("Converted product [{$definition['sku']}] has an unexpected variant definition.");
            }
        }

        return count($expected);
    }

    private function seedConvertedStore(object $store, object $product, array $variant): void
    {
        DB::table('product_store_prices')->insert([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'variant_id' => $variant['variantId'],
            'cost_price' => $variant['cost'],
            'selling_price' => $variant['price'],
            'mrp' => $variant['mrp'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $source = DB::table('product_locations')
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();
        $coordinates = [
            'aisle' => $source?->aisle ?? 'VAR',
            'rack' => $source?->rack ?? 'R1',
            'shelf' => $source?->shelf ?? 'S1',
            'bin' => 'V'.sprintf('%02d', $variant['ordinal']),
        ];
        DB::table('product_locations')->insert($coordinates + [
            'store_id' => $store->id,
            'product_id' => $product->id,
            'variant_id' => $variant['variantId'],
            'kind' => 'primary',
            'is_primary' => true,
            'note' => 'Demo variant rack location',
            'sort_key' => ProductLocation::makeSortKey($coordinates),
            'sort_order' => 100 + $variant['ordinal'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function moveBaseBatches(object $store, object $product, array $variants, int $actorId): void
    {
        $startingQuantity = (string) (DB::table('product_stock_levels')
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->value('quantity') ?? '0');
        $batches = DB::table('product_batches')
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->whereNull('deleted_at')
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->get();
        foreach ($batches as $batch) {
            $quantity = (string) $batch->quantity;
            app(RecordStockMovement::class)(
                (int) $store->id, (int) $product->id, null, (int) $batch->id,
                bcsub('0', $quantity, 4), 'adjustment', null, null, (string) $batch->cost_price,
                'Reclassified to demo product variants', $actorId,
            );
            $part = bcdiv($quantity, (string) count($variants), 4);
            foreach ($variants as $index => $variant) {
                $variantQuantity = $index === array_key_last($variants)
                    ? bcsub($quantity, bcmul($part, (string) (count($variants) - 1), 4), 4)
                    : $part;
                $batchId = DB::table('product_batches')->insertGetId([
                    'store_id' => $store->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant['variantId'],
                    'batch_number' => $batch->batch_number,
                    'manufacture_date' => $batch->manufacture_date,
                    'expiry_date' => $batch->expiry_date,
                    'cost_price' => $variant['cost'],
                    'selling_price' => $variant['price'],
                    'mrp' => $variant['mrp'],
                    'quantity' => '0',
                    'initial_quantity' => $variantQuantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                app(RecordStockMovement::class)(
                    (int) $store->id, (int) $product->id, (int) $variant['variantId'], $batchId,
                    $variantQuantity, 'adjustment', null, null, $variant['cost'],
                    'Reclassified from base demo product stock', $actorId,
                );
            }
        }

        $baseQuantity = (string) (DB::table('product_stock_levels')
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->value('quantity') ?? '0');
        $variantQuantity = (string) DB::table('product_stock_levels')
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->whereIn('variant_id', array_column($variants, 'variantId'))
            ->sum('quantity');
        if (bccomp($baseQuantity, '0', 4) !== 0 || bccomp($variantQuantity, $startingQuantity, 4) !== 0) {
            throw new RuntimeException("Stock reclassification failed for product [{$product->sku}] in store [{$store->code}].");
        }
    }

    private function seedStore(
        object $store,
        object $product,
        int $variantId,
        int $ordinal,
        string $cost,
        string $price,
        string $mrp,
        int $actorId,
    ): void {
        DB::table('product_store_prices')->insert([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'cost_price' => $cost,
            'selling_price' => $price,
            'mrp' => $mrp,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $source = DB::table('product_locations')
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();
        $coordinates = [
            'aisle' => $source?->aisle ?? 'VAR',
            'rack' => $source?->rack ?? 'R1',
            'shelf' => $source?->shelf ?? 'S1',
            'bin' => 'V'.sprintf('%02d', $ordinal),
        ];
        DB::table('product_locations')->insert($coordinates + [
            'store_id' => $store->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'kind' => 'primary',
            'is_primary' => true,
            'note' => 'Additional demo variant',
            'sort_key' => ProductLocation::makeSortKey($coordinates),
            'sort_order' => 100 + $ordinal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $product->track_batches) {
            app(RecordStockMovement::class)(
                (int) $store->id, (int) $product->id, $variantId, null,
                '400', 'opening', null, null, $cost,
                'Opening stock for added demo variant', $actorId,
            );

            return;
        }

        foreach ([['A', '240', 420], ['B', '160', 680]] as [$suffix, $quantity, $expiryDays]) {
            $expiry = $product->track_expiry ? now()->startOfDay()->addDays($expiryDays + $ordinal) : null;
            $batchId = DB::table('product_batches')->insertGetId([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'batch_number' => 'VAR-'.now()->format('ym').'-'.$store->id.'-'.$product->id.'-'.$ordinal.$suffix,
                'manufacture_date' => $expiry?->copy()->subMonths(18)->toDateString(),
                'expiry_date' => $expiry?->toDateString(),
                'cost_price' => $cost,
                'selling_price' => $price,
                'mrp' => $mrp,
                'quantity' => '0',
                'initial_quantity' => $quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(RecordStockMovement::class)(
                (int) $store->id, (int) $product->id, $variantId, $batchId,
                $quantity, 'opening', null, null, $cost,
                'Opening batch for added demo variant', $actorId,
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function definitions(): array
    {
        return [
            [
                'sku' => 'DEMO-HANES-121',
                'attributes' => [['name' => 'Size', 'values' => ['S', 'M', 'L', 'XL', 'XXL']], ['name' => 'Color', 'values' => ['Navy', 'White']]],
                'new' => [
                    ['ordinal' => 7, 'combo' => ['Size' => 'XL', 'Color' => 'Navy']],
                    ['ordinal' => 8, 'combo' => ['Size' => 'XL', 'Color' => 'White']],
                    ['ordinal' => 9, 'combo' => ['Size' => 'XXL', 'Color' => 'Navy']],
                    ['ordinal' => 10, 'combo' => ['Size' => 'XXL', 'Color' => 'White']],
                ],
            ],
            [
                'sku' => 'DEMO-HANES-122',
                'attributes' => [['name' => 'Size', 'values' => ['M', 'L', 'XL']], ['name' => 'Pack', 'values' => ['3 pairs', '6 pairs']]],
                'new' => [
                    ['ordinal' => 5, 'combo' => ['Size' => 'XL', 'Pack' => '3 pairs']],
                    ['ordinal' => 6, 'combo' => ['Size' => 'XL', 'Pack' => '6 pairs']],
                ],
            ],
            [
                'sku' => 'DEMO-SISTEMA-123',
                'attributes' => [['name' => 'Capacity', 'values' => ['500ml', '750ml', '1L']], ['name' => 'Color', 'values' => ['Blue', 'Green']]],
                'new' => [
                    ['ordinal' => 5, 'combo' => ['Capacity' => '1L', 'Color' => 'Blue']],
                    ['ordinal' => 6, 'combo' => ['Capacity' => '1L', 'Color' => 'Green']],
                ],
            ],
            [
                'sku' => 'DEMO-LAVAZZA-124',
                'attributes' => [['name' => 'Weight', 'values' => ['250g', '500g', '1kg']], ['name' => 'Roast', 'values' => ['Medium', 'Dark']]],
                'new' => [
                    ['ordinal' => 5, 'combo' => ['Weight' => '1kg', 'Roast' => 'Medium']],
                    ['ordinal' => 6, 'combo' => ['Weight' => '1kg', 'Roast' => 'Dark']],
                ],
            ],
            [
                'sku' => 'DEMO-DOVE-125',
                'attributes' => [['name' => 'Size', 'values' => ['250ml', '500ml', '750ml']], ['name' => 'Scent', 'values' => ['Original', 'Shea Butter']]],
                'new' => [
                    ['ordinal' => 5, 'combo' => ['Size' => '750ml', 'Scent' => 'Original']],
                    ['ordinal' => 6, 'combo' => ['Size' => '750ml', 'Scent' => 'Shea Butter']],
                ],
            ],
            [
                'sku' => 'DEMO-SISTEMA-126',
                'attributes' => [['name' => 'Capacity', 'values' => ['500ml', '1L', '2L']], ['name' => 'Color', 'values' => ['Clear', 'Blue']]],
                'new' => [
                    ['ordinal' => 5, 'combo' => ['Capacity' => '2L', 'Color' => 'Clear']],
                    ['ordinal' => 6, 'combo' => ['Capacity' => '2L', 'Color' => 'Blue']],
                ],
            ],
        ];
    }

    private function comboExists(int $productId, array $combo): bool
    {
        $needle = $this->normalizeCombo($combo);
        foreach (DB::table('product_variants')->where('product_id', $productId)->cursor() as $variant) {
            if ($needle === $this->normalizeCombo(json_decode((string) $variant->attributes, true))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeCombo(mixed $combo): array
    {
        $normalized = [];
        foreach ((array) $combo as $key => $value) {
            $normalized[mb_strtolower(trim((string) $key))] = mb_strtolower(trim((string) $value));
        }
        ksort($normalized);

        return $normalized;
    }

    private function fingerprint(string $table, ?array $ids = null): string
    {
        if ($ids === []) {
            return hash('sha256', '');
        }
        $query = DB::table($table)->orderBy('id');
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
        $rows = [];
        foreach ($query->cursor() as $row) {
            $rows[] = serialize((array) $row);
        }

        return hash('sha256', implode('', $rows));
    }
}
