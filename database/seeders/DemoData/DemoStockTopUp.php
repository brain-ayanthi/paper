<?php

namespace Database\Seeders\DemoData;

use App\Actions\Inventory\RecordStockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoStockTopUp
{
    /** @return array{eligible:int,low:int,out:int,already_high:int} */
    public function plan(string $target): array
    {
        $this->validateTarget($target);
        $counts = ['eligible' => 0, 'low' => 0, 'out' => 0, 'already_high' => 0];
        foreach ($this->levels() as $level) {
            $status = $this->status($level, $target);
            $counts[$status]++;
        }

        return $counts;
    }

    /** @return array{topped_up:int,low_preserved:int,out_preserved:int,already_high:int,quantity_added:string} */
    public function run(string $target = '5000', ?Command $command = null): array
    {
        if (! config('demo_stock_top_up.authorized', false) && ! config('demo_rebuild.authorized', false)) {
            throw new RuntimeException('Use pos:demo-top-up-stock in demo maintenance mode.');
        }
        $this->validateTarget($target);
        $actorId = DB::table('users')->where('is_super_admin', true)->value('id');
        if (! $actorId) {
            throw new RuntimeException('A super admin is required to record demo stock movements.');
        }

        $result = ['topped_up' => 0, 'low_preserved' => 0, 'out_preserved' => 0, 'already_high' => 0, 'quantity_added' => '0.0000'];
        DB::transaction(function () use ($target, $actorId, $command, &$result): void {
            $levels = $this->levels(true);
            $protected = [];
            foreach ($levels as $level) {
                $status = $this->status($level, $target);
                if ($status === 'out') {
                    $result['out_preserved']++;
                    $protected[$level->id] = (string) $level->quantity;

                    continue;
                }
                if ($status === 'low') {
                    $result['low_preserved']++;
                    $protected[$level->id] = (string) $level->quantity;

                    continue;
                }
                if ($status === 'already_high') {
                    $result['already_high']++;

                    continue;
                }

                $delta = bcsub($target, (string) $level->quantity, 4);
                $batchId = $level->track_batches
                    ? $this->topUpBatch($level, $delta)
                    : null;
                app(RecordStockMovement::class)(
                    (int) $level->store_id,
                    (int) $level->product_id,
                    $level->variant_id !== null ? (int) $level->variant_id : null,
                    $batchId,
                    $delta,
                    'adjustment',
                    null,
                    null,
                    (string) $level->effective_cost,
                    'Demo high-stock top-up',
                    (int) $actorId,
                );
                $result['topped_up']++;
                $result['quantity_added'] = bcadd($result['quantity_added'], $delta, 4);
            }

            foreach ($protected as $levelId => $quantity) {
                $current = DB::table('product_stock_levels')->where('id', $levelId)->value('quantity');
                if ($current === null || bccomp((string) $current, $quantity, 4) !== 0) {
                    throw new RuntimeException('A protected low-stock or out-of-stock quantity changed; top-up rolled back.');
                }
            }
            $command?->info("High-stock top-up: {$result['topped_up']} levels raised to {$target}; {$result['low_preserved']} low and {$result['out_preserved']} out-of-stock levels preserved.");
        });

        return $result;
    }

    private function topUpBatch(object $level, string $delta): int
    {
        $variantPart = $level->variant_id ?? 0;
        $batchNumber = "DEMO-TOPUP-{$level->store_id}-{$level->product_id}-{$variantPart}-".now()->format('Ym');
        $batchId = DB::table('product_batches')
            ->where('store_id', $level->store_id)
            ->where('product_id', $level->product_id)
            ->where('variant_id', $level->variant_id)
            ->where('batch_number', $batchNumber)
            ->whereNull('deleted_at')
            ->value('id');
        if ($batchId) {
            return (int) $batchId;
        }

        return DB::table('product_batches')->insertGetId([
            'store_id' => $level->store_id,
            'product_id' => $level->product_id,
            'variant_id' => $level->variant_id,
            'batch_number' => $batchNumber,
            'manufacture_date' => now()->subDays(30)->toDateString(),
            'expiry_date' => $level->track_expiry ? now()->addYears(3)->toDateString() : null,
            'cost_price' => $level->effective_cost,
            'selling_price' => $level->effective_price,
            'mrp' => $level->effective_mrp,
            'quantity' => '0',
            'initial_quantity' => $delta,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function status(object $level, string $target): string
    {
        $quantity = (string) $level->quantity;
        if (bccomp($quantity, '0', 4) <= 0) {
            return 'out';
        }
        $threshold = $level->reorder_level_override ?? $level->product_reorder_level;
        if ($threshold !== null && bccomp($quantity, (string) $threshold, 4) <= 0) {
            return 'low';
        }

        return bccomp($quantity, $target, 4) >= 0 ? 'already_high' : 'eligible';
    }

    private function levels(bool $lock = false): Collection
    {
        $query = DB::table('product_stock_levels as levels')
            ->join('products as products', 'products.id', '=', 'levels.product_id')
            ->join('stores as stores', 'stores.id', '=', 'levels.store_id')
            ->leftJoin('product_variants as variants', 'variants.id', '=', 'levels.variant_id')
            ->whereNull('products.deleted_at')
            ->whereNull('stores.deleted_at')
            ->where('products.is_active', true)
            ->where('products.track_stock', true)
            ->where('stores.is_active', true)
            ->where(function ($nested): void {
                $nested->where('products.type', '!=', 'variant')
                    ->orWhereNotNull('levels.variant_id');
            })
            ->select([
                'levels.id', 'levels.store_id', 'levels.product_id', 'levels.variant_id',
                'levels.quantity', 'levels.reorder_level_override',
                'products.reorder_level as product_reorder_level',
                'products.track_batches', 'products.track_expiry',
                DB::raw('COALESCE(variants.cost_price, products.cost_price) as effective_cost'),
                DB::raw('COALESCE(variants.selling_price, products.selling_price) as effective_price'),
                DB::raw('COALESCE(variants.mrp, products.mrp) as effective_mrp'),
            ])
            ->orderBy('levels.id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function validateTarget(string $target): void
    {
        if (! preg_match('/^\d+(\.\d{1,4})?$/D', $target) || bccomp($target, '1000', 4) < 0) {
            throw new RuntimeException('High-stock target must be a decimal of at least 1000.');
        }
    }
}
