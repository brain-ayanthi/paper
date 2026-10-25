<?php

namespace App\Actions\Maintenance;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Reconstruct `sale_items.unit_cost_snapshot` for sales rung up before the
 * cost snapshot existed.
 *
 * Until 2026-08-04 `CompleteSale` wrote a literal `'0'` there, so every
 * historical line reports cost 0 / margin 100% on Sales-by-Product,
 * Sales-by-Category, the dashboard profit card, `daily_metrics` and Day
 * Summary. New sales are correct; this repairs the back catalogue.
 *
 * ── Why it replays the ledger instead of copying today's cost ──────────
 * The obvious shortcut — stamp each line with the product's CURRENT weighted
 * average — produces numbers that are confidently wrong: buy prices move, so
 * last year's margins would be computed from this year's costs. Instead this
 * walks `stock_movements` in order and recomputes the running weighted average
 * exactly as {@see App\Actions\Inventory\RecordStockMovement} did at the time:
 *
 *     new_wac = (old_qty × old_wac + delta × unit_cost) ÷ new_qty
 *
 * on positive costed movements only; negatives leave it alone. Each sale's own
 * outbound movement marks its exact position in that timeline, so a line is
 * costed at the average that was in force the moment it sold.
 *
 * ── Sources, most trustworthy first ────────────────────────────────────
 *   1. `batch`     — the line's batch `cost_price`. A specific lot's landed
 *                    cost doesn't drift, so this needs no reconstruction.
 *   2. `wac`       — the replayed average at that line's own stock movement.
 *   3. `wac_time`  — no movement for the line (a non-stocked product), so the
 *                    replayed average as of the sale's timestamp.
 *   4. `kit`       — a kit holds no stock of its own; sum its components'
 *                    replayed averages at that time.
 *   5. `catalogue` — nothing in the ledger; the product's entered `cost_price`.
 *                    Time-invariant and therefore approximate — counted
 *                    separately in the summary so the operator can see how
 *                    much of the result rests on it.
 *
 * Anything with no source at all is left at zero and reported as `unknown` —
 * a guessed cost is worse than an obviously missing one.
 *
 * Dry by default: nothing is written unless `$apply` is true, so the operator
 * can see the row counts and the total COGS about to land before committing.
 * Idempotent — only rows still sitting at zero are considered, so a second run
 * changes nothing.
 */
class BackfillSaleLineCosts
{
    /** Sale items handled per chunk — bounded memory on a large back catalogue. */
    private const CHUNK = 500;

    /** @var array<string, array{timeline: array<int, array{0: string, 1: string}>, sales: array<int, string>}> */
    private array $groups = [];

    /**
     * @return array{
     *   applied: bool, scanned: int, filled: int, unknown: int,
     *   cogs: string, sources: array<string, int>
     * }
     */
    public function __invoke(bool $apply = false, ?int $storeId = null): array
    {
        $this->groups = [];

        $summary = [
            'applied' => $apply,
            'scanned' => 0,
            'filled'  => 0,
            'unknown' => 0,
            'cogs'    => '0',
            'sources' => ['batch' => 0, 'wac' => 0, 'wac_time' => 0, 'kit' => 0, 'catalogue' => 0],
        ];

        $this->targets($storeId)->chunkById(self::CHUNK, function ($rows) use (&$summary, $apply) {
            $updates = [];

            foreach ($rows as $row) {
                $summary['scanned']++;

                [$cost, $source] = $this->costFor($row);

                if ($source === null || bccomp($cost, '0', 4) <= 0) {
                    $summary['unknown']++;
                    continue;
                }

                $summary['filled']++;
                $summary['sources'][$source]++;
                $summary['cogs'] = bcadd($summary['cogs'], bcmul($cost, (string) $row->quantity, 4), 4);

                $updates[$row->id] = $cost;
            }

            if ($apply && $updates !== []) {
                foreach ($updates as $id => $cost) {
                    DB::table('sale_items')->where('id', $id)->update(['unit_cost_snapshot' => $cost]);
                }
            }
        }, 'si.id', 'id');

        $summary['cogs'] = bcadd($summary['cogs'], '0', 4);

        return $summary;
    }

    /** Zero-cost lines on live sales, newest store data included. */
    private function targets(?int $storeId)
    {
        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->whereNull('s.deleted_at')
            ->where(fn ($q) => $q->whereNull('si.unit_cost_snapshot')->orWhere('si.unit_cost_snapshot', '<=', 0))
            ->when($storeId, fn ($q) => $q->where('s.store_id', $storeId))
            ->select([
                'si.id', 'si.sale_id', 'si.product_id', 'si.variant_id', 'si.batch_id', 'si.quantity',
                's.store_id', 's.created_at as sold_at',
            ]);
    }

    /**
     * @return array{0: string, 1: ?string} [cost, source]
     */
    private function costFor(object $row): array
    {
        $storeId   = (int) $row->store_id;
        $productId = (int) $row->product_id;
        $variantId = $row->variant_id !== null ? (int) $row->variant_id : null;

        if ($row->batch_id !== null) {
            $batchCost = DB::table('product_batches')->where('id', $row->batch_id)->value('cost_price');
            if ($batchCost !== null && bccomp((string) $batchCost, '0', 4) > 0) {
                return [$this->fmt($batchCost), 'batch'];
            }
        }

        $group = $this->group($storeId, $productId, $variantId);

        // The line's own outbound movement pins it to an exact point in the
        // timeline — no timestamp guessing.
        if (isset($group['sales'][(int) $row->sale_id])) {
            $wac = $group['sales'][(int) $row->sale_id];
            if (bccomp($wac, '0', 4) > 0) {
                return [$wac, 'wac'];
            }
        }

        $atTime = $this->wacAt($group, (string) $row->sold_at);
        if (bccomp($atTime, '0', 4) > 0) {
            return [$atTime, 'wac_time'];
        }

        $product = Product::query()->find($productId);
        if (! $product) {
            return ['0', null];
        }

        if ($product->isKitType()) {
            $kit = $this->kitCostAt($product, $storeId, (string) $row->sold_at);
            if (bccomp($kit, '0', 4) > 0) {
                return [$kit, 'kit'];
            }
        }

        $catalogue = $variantId !== null
            ? DB::table('product_variants')->where('id', $variantId)->value('cost_price')
            : null;
        $catalogue = ($catalogue !== null && bccomp((string) $catalogue, '0', 4) > 0)
            ? (string) $catalogue
            : (string) ($product->cost_price ?? '0');

        if (bccomp($catalogue, '0', 4) > 0) {
            return [$this->fmt($catalogue), 'catalogue'];
        }

        return ['0', null];
    }

    /**
     * Replay one (store, product, variant) ledger, cached for the run.
     *
     * `timeline` is [movement time, wac after that movement]; `sales` maps a
     * sale id to the average in force when its outbound movement landed.
     *
     * @return array{timeline: array<int, array{0: string, 1: string}>, sales: array<int, string>}
     */
    private function group(int $storeId, int $productId, ?int $variantId): array
    {
        $key = $storeId.':'.$productId.':'.($variantId ?? '-');
        if (isset($this->groups[$key])) {
            return $this->groups[$key];
        }

        $movements = DB::table('stock_movements')
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['quantity_delta', 'quantity_after', 'unit_cost', 'reference_type', 'reference_id', 'created_at']);

        $timeline = [];
        $sales    = [];
        $wac      = '0';
        $oldQty   = '0';

        foreach ($movements as $m) {
            $delta  = (string) $m->quantity_delta;
            $newQty = (string) $m->quantity_after;

            // A sale reads the average BEFORE its own movement is applied —
            // which is the same value either way, since outgoing stock never
            // moves the average. Recorded first so the intent is explicit.
            if ($m->reference_type === Sale::class && $m->reference_id !== null) {
                $sales[(int) $m->reference_id] = $wac;
            }

            // Same recurrence RecordStockMovement uses, including its
            // receiving-into-empty-or-negative special case.
            if ($m->unit_cost !== null
                && bccomp($delta, '0', 8) > 0
                && bccomp($newQty, '0', 8) > 0
            ) {
                $unitCost = (string) $m->unit_cost;

                $wac = bccomp($oldQty, '0', 8) <= 0
                    ? $this->fmt($unitCost)
                    : $this->fmt(bcdiv(
                        bcadd(bcmul($oldQty, $wac, 8), bcmul($delta, $unitCost, 8), 8),
                        $newQty,
                        8,
                    ));
            }

            $timeline[] = [(string) $m->created_at, $wac];
            $oldQty     = $newQty;
        }

        return $this->groups[$key] = ['timeline' => $timeline, 'sales' => $sales];
    }

    /**
     * The average in force at `$at` — the last movement at or before it.
     *
     * @param array{timeline: array<int, array{0: string, 1: string}>, sales: array<int, string>} $group
     */
    private function wacAt(array $group, string $at): string
    {
        $wac = '0';
        foreach ($group['timeline'] as [$when, $value]) {
            if (strcmp($when, $at) > 0) {
                break;
            }
            $wac = $value;
        }

        return $wac;
    }

    /** Σ(component average at that time × quantity). One level deep. */
    private function kitCostAt(Product $kit, int $storeId, string $at): string
    {
        $kit->loadMissing('kitItems');

        $total = '0';
        foreach ($kit->kitItems as $item) {
            $componentId = (int) $item->component_product_id;
            if ($componentId === (int) $kit->id) {
                continue;
            }

            $variantId = $item->component_variant_id ? (int) $item->component_variant_id : null;
            $unit      = $this->wacAt($this->group($storeId, $componentId, $variantId), $at);

            if (bccomp($unit, '0', 4) <= 0) {
                $unit = (string) (Product::query()->whereKey($componentId)->value('cost_price') ?? '0');
            }

            $total = bcadd($total, bcmul($unit, (string) $item->quantity, 4), 4);
        }

        return $this->fmt($total);
    }

    private function fmt(mixed $value): string
    {
        return bcadd((string) $value, '0', 4);
    }
}
