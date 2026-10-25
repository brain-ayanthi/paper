<?php

namespace Database\Seeders;

use App\Models\ProductLocation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo shelf / rack locations — gives every demo product a physical
 * place in every demo store, so the Locations card in the product
 * editor, the shelf badge on the cashier screen, the count sheet and
 * the location columns on reports all have something to show on a
 * fresh install. Run with /seed?class=ProductLocationsDemoSeeder.
 *
 * Layout is DERIVED, not hand-listed: the catalogue differs per
 * industry (retail / pharmacy / supermarket all seed their own
 * categories), so a slug → aisle map would only fit one of them.
 * Instead each ROOT category gets an aisle letter and products walk
 * racks within it — which is how a real shop is laid out anyway.
 *
 *   - pick face  → the aisle/rack/shelf the product is normally taken from
 *   - controlled → schedule H / H1 / X / Rx drugs sit in a locked cabinet
 *   - cold       → dairy, frozen, insulin & vaccines sit in the chiller
 *   - backstock  → every third product also has a godown carton
 *
 * Each store is laid out differently (the aisle letters rotate) because
 * the whole point of per-store locations is that branch A and branch B
 * don't match — a demo where every store agrees teaches the wrong thing.
 *
 * Locations carry NO quantities (see the migration's note) — this seeder
 * only labels where things sit and never touches the stock ledger.
 *
 * Idempotent + non-destructive: a (store, product) pair that already has
 * any location row is left completely alone, so re-running never
 * clobbers coordinates someone typed, imported, or edited.
 *
 * Depends on a catalogue seeder (Retail / Pharmacy / Supermarket, or
 * ProductsDemoSeeder) plus at least one store.
 */
class ProductLocationsDemoSeeder extends Seeder
{
    /**
     * Drug schedules that belong in a locked cabinet rather than on the
     * open dispensing rack. `OTC` is deliberately absent.
     */
    private const CONTROLLED_SCHEDULES = ['H', 'H1', 'X', 'G', 'Rx', 'NDPS'];

    /** Category / product names that mean "this lives in the fridge". */
    private const COLD_PATTERN = '/insulin|vaccine|injection|frozen|ice ?cream|chilled|dairy|milk|yogurt|yoghurt|butter|cheese|curd|paneer/i';

    /** Rows inserted per batch — keeps memory flat on a big catalogue. */
    private const CHUNK = 500;

    public function run(): void
    {
        $stores = $this->stores();
        if ($stores->isEmpty()) {
            $this->command?->warn('Product locations demo: no stores — run the installer or StoresDemoSeeder first.');

            return;
        }

        // Kits are excluded on purpose: a bundle is assembled at the till
        // and never sits on a shelf of its own — its components carry the
        // location. `type` is nullable on older rows, hence the orWhereNull.
        $products = DB::table('products')
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('type', '<>', 'kit')->orWhereNull('type'))
            ->orderBy('id')
            ->get(['id', 'name', 'category_id', 'pharmacy_schedule', 'generic_name']);

        if ($products->isEmpty()) {
            $this->command?->warn('Product locations demo: no products — run a catalogue seeder first.');

            return;
        }

        $aisles     = $this->aisleLetters();
        $letterList = array_values($aisles);
        $rootOf     = $this->rootCategoryMap();
        $catNames   = DB::table('categories')->pluck('name', 'id')->all();
        $occupied   = $this->existingPairs($stores->pluck('id')->all());
        $now        = now();

        $buffer  = [];
        $written = 0;
        $skipped = 0;

        foreach ($stores->values() as $storeIndex => $store) {
            // Position of each product within its own aisle, so racks run
            // 1, 2, 3… down the aisle instead of jumping with product ids.
            $positions = [];

            foreach ($products as $product) {
                $rootId = $rootOf[$product->category_id] ?? null;
                $aisle  = $letterList === []
                    ? 'A'
                    : $letterList[($this->aisleIndex($aisles, $rootId) + $storeIndex) % count($letterList)];

                $pos = $positions[$aisle] = ($positions[$aisle] ?? 0) + 1;

                if (isset($occupied[$store->id.':'.$product->id])) {
                    $skipped++;
                    continue;
                }

                $rows = $this->rowsFor(
                    $product,
                    $catNames[$product->category_id] ?? '',
                    $aisle,
                    $pos,
                    $storeIndex,
                );

                foreach (array_values($rows) as $order => $row) {
                    $buffer[] = $row + [
                        'store_id'   => $store->id,
                        'product_id' => $product->id,
                        'variant_id' => null,          // product-level rows only — variant bins are v1.1
                        'is_primary' => $order === 0,  // exactly one pick face per (store, product)
                        'sort_key'   => ProductLocation::makeSortKey($row),
                        'sort_order' => $order,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $written++;
                }

                if (count($buffer) >= self::CHUNK) {
                    DB::table('product_locations')->insert($buffer);
                    $buffer = [];
                }
            }
        }

        if ($buffer !== []) {
            DB::table('product_locations')->insert($buffer);
        }

        $storeCount = $stores->count();
        $this->command?->info(
            "Product locations demo: {$written} locations across {$storeCount} store(s)"
            .($skipped > 0 ? " ({$skipped} product/store pairs already located — left untouched)." : '.')
        );
    }

    /**
     * The location rows for one product at one store, pick face first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(object $product, string $categoryName, string $aisle, int $pos, int $storeIndex): array
    {
        $schedule   = trim((string) ($product->pharmacy_schedule ?? ''));
        $controlled = $schedule !== '' && in_array($schedule, self::CONTROLLED_SCHEDULES, true);
        $cold       = preg_match(self::COLD_PATTERN, $categoryName.' '.$product->name.' '.($product->generic_name ?? '')) === 1;

        $rows = [];

        if ($controlled) {
            // The cabinet IS the pick face for a scheduled drug — the
            // pharmacist unlocks it, dispenses, locks it again.
            $rows[] = [
                'kind'  => 'controlled',
                'aisle' => 'CAB',
                'rack'  => 'C-'.(1 + ($pos % 3)),
                'shelf' => (string) (1 + ($pos % 4)),
                'bin'   => 'B'.(1 + ($pos % 6)),
                'note'  => 'Locked cabinet — schedule '.$schedule,
            ];
        } elseif ($cold) {
            // Chiller door, still inside its aisle so the walk order holds.
            $rows[] = [
                'kind'  => 'cold',
                'aisle' => $aisle,
                'rack'  => 'F-'.(1 + ($pos % 4)),
                'shelf' => (string) (1 + ($pos % 3)),
                'bin'   => null,
                'note'  => 'Chiller',
            ];
        } else {
            $rows[] = [
                'kind'  => 'primary',
                'aisle' => $aisle,
                'rack'  => 'R-'.(1 + (($pos + $storeIndex) % 12)),
                'shelf' => (string) (1 + ($pos % 4)),
                'bin'   => null,
                'note'  => null,
            ];
        }

        // Every third product also keeps a reserve carton out back, so the
        // demo shows what a multi-location product looks like.
        if ($pos % 3 === 0) {
            $rows[] = [
                'kind'  => 'backstock',
                'aisle' => 'GDN',
                'rack'  => 'S-'.(1 + ($pos % 8)),
                'shelf' => null,
                'bin'   => null,
                'note'  => 'Godown reserve',
            ];
        }

        return $rows;
    }

    /** Active stores, falling back to all of them on an install with none flagged. */
    private function stores(): \Illuminate\Support\Collection
    {
        $active = DB::table('stores')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name']);

        return $active->isNotEmpty()
            ? $active
            : DB::table('stores')->whereNull('deleted_at')->orderBy('id')->get(['id', 'name']);
    }

    /**
     * "store_id:product_id" keys that already have a location row, so a
     * re-run never rewrites (or duplicates) anyone's coordinates.
     *
     * @param  array<int, int>  $storeIds
     * @return array<string, true>
     */
    private function existingPairs(array $storeIds): array
    {
        $pairs = [];

        DB::table('product_locations')
            ->whereIn('store_id', $storeIds)
            ->orderBy('id')
            ->select('store_id', 'product_id')
            ->chunk(2000, function ($rows) use (&$pairs) {
                foreach ($rows as $row) {
                    $pairs[$row->store_id.':'.$row->product_id] = true;
                }
            });

        return $pairs;
    }

    /**
     * Aisle letters, one per root category, in display order: A, B, C…
     * then AA, AB once a catalogue outgrows the alphabet.
     *
     * @return array<int, string>
     */
    private function aisleLetters(): array
    {
        $roots = DB::table('categories')
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $letters = [];
        foreach (array_values($roots) as $i => $id) {
            $letters[$id] = $this->letter($i);
        }

        return $letters;
    }

    /**
     * Where a category's aisle sits in the letter list. Uncategorised
     * products land in the last aisle rather than being skipped — a
     * product with no shelf is exactly what this feature exists to fix.
     *
     * @param  array<int, string>  $aisles
     */
    private function aisleIndex(array $aisles, ?int $rootId): int
    {
        $keys  = array_keys($aisles);
        $index = $rootId === null ? false : array_search($rootId, $keys, true);

        return $index === false ? count($keys) - 1 : $index;
    }

    /**
     * category_id → its root ancestor's id, so every sub-category shares
     * its parent aisle (Cola and Juices both sit in Beverages).
     *
     * @return array<int, int>
     */
    private function rootCategoryMap(): array
    {
        $parents = DB::table('categories')
            ->whereNull('deleted_at')
            ->pluck('parent_id', 'id')
            ->all();

        $roots = [];
        foreach ($parents as $id => $_) {
            $current = (int) $id;

            // Bounded walk — a corrupted parent chain must not spin here.
            // array_key_exists, not isset: a ROOT's parent_id is null, and
            // isset() would read that as "missing" and stop one level early.
            for ($hop = 0; $hop < 10; $hop++) {
                $parent = $parents[$current] ?? null;
                if ($parent === null || ! array_key_exists((int) $parent, $parents)) {
                    break;
                }
                $current = (int) $parent;
            }

            $roots[(int) $id] = $current;
        }

        return $roots;
    }

    /** 0 → "A", 25 → "Z", 26 → "AA". */
    private function letter(int $index): string
    {
        $label = '';
        $i     = $index + 1;

        while ($i > 0) {
            $i--;
            $label = chr(65 + ($i % 26)).$label;
            $i     = intdiv($i, 26);
        }

        return $label;
    }
}
