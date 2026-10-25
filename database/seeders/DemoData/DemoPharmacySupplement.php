<?php

namespace Database\Seeders\DemoData;

use App\Actions\Inventory\RecordStockMovement;
use App\Models\ProductLocation;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class DemoPharmacySupplement
{
    /** @return array{inserted:int, existing:int, stores:int} */
    public function run(?Command $command = null): array
    {
        if (! config('demo_pharmacy.authorized', false) && ! config('demo_rebuild.authorized', false)) {
            throw new RuntimeException('Use pos:demo-add-pharmacy-products in demo maintenance mode.');
        }

        $scale = (string) config('demo_pharmacy.price_scale', config('demo_rebuild.price_scale', '1'));
        if (! preg_match('/^\d+(\.\d{1,4})?$/D', $scale) || bccomp($scale, '0', 4) <= 0) {
            throw new RuntimeException('Price scale must be a positive decimal.');
        }

        $unitId = DB::table('units')->where('code', 'pc')->value('id');
        $actorId = DB::table('users')->where('is_super_admin', true)->value('id');
        $currency = DB::table('company')->value('base_currency_code');
        $stores = DB::table('stores')->whereNull('deleted_at')->where('is_active', true)->orderBy('id')->get();
        if (! $unitId || ! $actorId || ! $currency || $stores->isEmpty()
            || $stores->contains(fn ($store) => $store->currency_code !== $currency)) {
            throw new RuntimeException('Requires a super admin, piece unit, company currency, and active stores using that currency.');
        }
        foreach (['OTC', 'H'] as $schedule) {
            if (! DB::table('drug_schedules')->where('code', $schedule)->whereNull('deleted_at')->exists()) {
                throw new RuntimeException("Required drug schedule [{$schedule}] is missing.");
            }
        }

        $existingProductIds = DB::table('products')->orderBy('id')->pluck('id')->all();
        $existingProducts = $this->fingerprint('products', $existingProductIds);
        $existingImages = $this->fingerprint('product_images');
        $inserted = $existing = 0;

        DB::transaction(function () use (
            $command, $scale, $unitId, $actorId, $stores,
            $existingProductIds, $existingProducts, $existingImages, &$inserted, &$existing,
        ): void {
            $command?->info('Adding pharmacy catalogue without changing existing products or images.');
            foreach (RealisticPharmacyCatalog::products() as $index => $row) {
                $sku = 'DEMO-MED-'.sprintf('%03d', $index + 1);
                $found = DB::table('products')->where('sku', $sku)->first();
                if ($found) {
                    $meta = json_decode((string) $found->meta, true);
                    if (($meta['demo_pharmacy_catalog'] ?? false) !== true
                        || $found->name !== $row['name']) {
                        throw new RuntimeException("SKU collision for [{$sku}]; no rows were changed.");
                    }
                    $existing++;

                    continue;
                }

                $categoryId = $this->lookupOrInsert('categories', $row['category']);
                // Keep the brand directory compact: marketed product names stay
                // on the product, while the reusable brand record is the actual
                // manufacturer responsible for that medicine/device.
                $brandId = $this->lookupOrInsert('brands', $row['manufacturer']);
                $price = Money::round(bcmul($row['price'], $scale, 8));
                $cost = Money::round(bcmul($price, '0.62', 8));
                $productId = DB::table('products')->insertGetId([
                    'sku' => $sku,
                    'barcode' => $sku,
                    'name' => $row['name'],
                    'slug' => Str::slug($sku),
                    'category_id' => $categoryId,
                    'brand_id' => $brandId,
                    'unit_id' => $unitId,
                    'type' => 'simple',
                    'cost_price' => $cost,
                    'selling_price' => $price,
                    'mrp' => Money::round(bcmul($price, '1.10', 8)),
                    'track_stock' => true,
                    'track_batches' => true,
                    'track_expiry' => true,
                    'reorder_level' => '20',
                    'reorder_quantity' => '200',
                    'hsn_code' => $row['category'] === 'First Aid & Devices' ? '3005' : '3004',
                    'pharmacy_schedule' => $row['schedule'],
                    'generic_name' => $row['generic'],
                    'manufacturer' => $row['manufacturer'],
                    'meta' => json_encode(['demo_pharmacy_catalog' => true], JSON_THROW_ON_ERROR),
                    'is_active' => true,
                    'created_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                foreach ($stores as $store) {
                    $this->seedStore($store, $productId, $index + 1, $row, $cost, $price, $actorId);
                }
                $inserted++;
            }
            $command?->info("Pharmacy catalogue: {$inserted} added, {$existing} already present.");

            if ($existingProducts !== $this->fingerprint('products', $existingProductIds)
                || $existingImages !== $this->fingerprint('product_images')) {
                throw new RuntimeException('An existing product or product image changed; pharmacy supplement rolled back.');
            }
        });

        return ['inserted' => $inserted, 'existing' => $existing, 'stores' => $stores->count()];
    }

    private function seedStore(
        object $store,
        int $productId,
        int $index,
        array $row,
        string $cost,
        string $price,
        int $actorId,
    ): void {
        DB::table('product_store_prices')->insert([
            'store_id' => $store->id,
            'product_id' => $productId,
            'variant_id' => null,
            'cost_price' => $cost,
            'selling_price' => $price,
            'mrp' => Money::round(bcmul($price, '1.10', 8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cold = $row['generic'] === 'Insulin glargine';
        $kind = $cold ? 'cold' : ($row['schedule'] === 'H' ? 'controlled' : 'primary');
        $location = [
            'aisle' => 'PH',
            'rack' => $cold ? 'FRIDGE-1' : ($row['schedule'] === 'H' ? 'RX-H' : 'OTC'),
            'shelf' => (string) (($index % 5) + 1),
            'bin' => 'B'.sprintf('%02d', ($index % 8) + 1),
        ];
        DB::table('product_locations')->insert($location + [
            'store_id' => $store->id,
            'product_id' => $productId,
            'variant_id' => null,
            'kind' => $kind,
            'is_primary' => true,
            'note' => $cold ? 'Maintain cold chain' : ($row['schedule'] === 'H' ? 'Prescription stock' : 'Dispensing stock'),
            'sort_key' => ProductLocation::makeSortKey($location),
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $batches = [
            ['A', (string) (180 + ($index % 5) * 20), 540 + $index, $cost],
            ['B', (string) ($index % 7 === 0 ? 24 : 120 + ($index % 4) * 15),
                $index % 7 === 0 ? 35 : 720 + $index, Money::round(bcmul($cost, '1.02', 8))],
        ];
        foreach ($batches as [$suffix, $quantity, $expiryDays, $batchCost]) {
            $expiry = now()->startOfDay()->addDays($expiryDays);
            $batchId = DB::table('product_batches')->insertGetId([
                'store_id' => $store->id,
                'product_id' => $productId,
                'variant_id' => null,
                'batch_number' => 'MED-'.now()->format('ym').'-'.$store->id.'-'.sprintf('%03d', $index).$suffix,
                'manufacture_date' => $expiry->copy()->subYears(2)->toDateString(),
                'expiry_date' => $expiry->toDateString(),
                'cost_price' => $batchCost,
                'selling_price' => $price,
                'mrp' => Money::round(bcmul($price, '1.10', 8)),
                'quantity' => '0',
                'initial_quantity' => $quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(RecordStockMovement::class)(
                (int) $store->id,
                $productId,
                null,
                $batchId,
                $quantity,
                'opening',
                null,
                null,
                $batchCost,
                'Demo pharmacy opening stock',
                $actorId,
            );
        }
    }

    private function lookupOrInsert(string $table, string $name): int
    {
        $slug = Str::slug($name);
        $row = DB::table($table)->where('slug', $slug)->first();
        if ($row) {
            if ($table === 'categories' && $row->deleted_at !== null) {
                throw new RuntimeException("Archived category slug collision for [{$slug}].");
            }

            return (int) $row->id;
        }

        return DB::table($table)->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
