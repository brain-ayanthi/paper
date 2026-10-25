<?php

namespace App\Actions\Inventory;

use App\Http\Requests\Admin\StockTakeRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockTake;
use App\Models\User;
use App\Services\Excel\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Create a stock-take draft and fill its counted quantities from a sheet. */
class ImportStockTakeCountsFromCsv
{
    public const TARGET_FIELDS = [
        'product_sku',
        'variant_sku',
        'barcode',
        'counted_quantity',
        'notes',
    ];

    public const PREVIEW_ROWS = 10;

    public function __construct(
        private SpreadsheetReader $reader,
        private ResolveInventoryImportProduct $resolveProduct,
        private CreateStockTake $createStockTake,
    ) {}

    /**
     * @return array{headers:array<int,string>,suggested_map:array<string,?string>,rows:array<int,array<int,string>>,total_columns:int}
     */
    public function preview(string $path): array
    {
        $headers = [];
        $rows = [];

        foreach ($this->reader->rows($path, self::PREVIEW_ROWS + 1) as $index => $row) {
            if ($index === 0) {
                $headers = $row;

                continue;
            }

            $rows[] = $row;
        }

        $suggested = [];
        foreach ($headers as $header) {
            $suggested[$header] = $this->suggestField($header);
        }

        return [
            'headers' => $headers,
            'suggested_map' => $suggested,
            'rows' => $rows,
            'total_columns' => count($headers),
        ];
    }

    /**
     * @param  array<int|string, ?string>  $mapping
     * @param  array{store_id:int,take_date:string,name?:?string,notes?:?string}  $document
     * @return array{take:?StockTake,imported:int,skipped:int,errors:array<int,array{row:int,message:string}>}
     */
    public function commit(string $path, array $mapping, array $document, User $user): array
    {
        $mapping = $this->normalizeMapping($mapping);
        if (! in_array('counted_quantity', $mapping, true)) {
            throw new \RuntimeException(__('stock_takes.import.errors.count_mapping_required'));
        }
        if (! collect(['product_sku', 'variant_sku', 'barcode'])
            ->contains(fn ($field) => in_array($field, $mapping, true))) {
            throw new \RuntimeException(__('inventory.import_common.errors.identifier_mapping_required'));
        }

        $counts = [];
        $errors = [];
        $seen = [];
        $rowNumber = 0;

        foreach ($this->reader->rows($path) as $index => $cells) {
            $rowNumber++;
            if ($index === 0 || $this->rowIsBlank($cells)) {
                continue;
            }

            $get = fn (string $field) => $this->valueFor($cells, $mapping, $field);
            $counted = trim((string) $get('counted_quantity'));
            $notes = $this->nullableString($get('notes'));
            $validator = Validator::make([
                'counted_quantity' => $counted,
                'notes' => $notes,
            ], [
                'counted_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,4', 'max:'.StockTakeRequest::QTY_MAX],
                'notes' => ['nullable', 'string', 'max:191'],
            ], [], [
                'counted_quantity' => __('stock_takes.import.fields.counted_quantity'),
                'notes' => __('stock_takes.import.fields.notes'),
            ]);

            if ($validator->fails()) {
                $errors[] = ['row' => $rowNumber, 'message' => $validator->errors()->first()];

                continue;
            }

            $resolved = ($this->resolveProduct)(
                $this->nullableString($get('product_sku')),
                $this->nullableString($get('variant_sku')),
                $this->nullableString($get('barcode')),
            );
            if ($resolved['error']) {
                $errors[] = ['row' => $rowNumber, 'message' => $resolved['error']];

                continue;
            }

            /** @var Product $product */
            $product = $resolved['product'];
            /** @var ProductVariant|null $variant */
            $variant = $resolved['variant'];

            if ($product->track_batches) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => __('stock_takes.import.errors.batch_tracked', ['product' => $product->name]),
                ];

                continue;
            }

            $hasStockLevel = StockLevel::query()
                ->where('store_id', (int) $document['store_id'])
                ->where('product_id', $product->id)
                ->where('variant_id', $variant?->id)
                ->exists();
            if (! $hasStockLevel) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => __('stock_takes.import.errors.not_in_stock_list', ['product' => $product->name]),
                ];

                continue;
            }

            $key = $product->id.':'.($variant?->id ?? 0);
            if (isset($seen[$key])) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => __('stock_takes.import.errors.duplicate_line', ['row' => $seen[$key]]),
                ];

                continue;
            }
            $seen[$key] = $rowNumber;

            $counts[] = [
                'product' => $product,
                'variant' => $variant,
                'counted_quantity' => bcadd($counted, '0', 4),
                'notes' => $notes,
            ];
        }

        $take = null;
        if ($counts !== []) {
            $take = DB::transaction(function () use ($document, $counts, $user) {
                $take = ($this->createStockTake)($document, $user);

                foreach ($counts as $count) {
                    /** @var Product $product */
                    $product = $count['product'];
                    /** @var ProductVariant|null $variant */
                    $variant = $count['variant'];
                    $item = $take->items()
                        ->where('product_id', $product->id)
                        ->where('variant_id', $variant?->id)
                        ->firstOrFail();

                    $item->update([
                        'counted_quantity' => $count['counted_quantity'],
                        'notes' => $count['notes'],
                    ]);
                }

                return $take->refresh()->load('items');
            });
        }

        return [
            'take' => $take,
            'imported' => count($counts),
            'skipped' => count($errors),
            'errors' => $errors,
        ];
    }

    private function suggestField(string $header): ?string
    {
        $aliases = [
            'product_sku' => ['productsku', 'sku', 'productcode', 'itemcode'],
            'variant_sku' => ['variantsku', 'variationsku', 'childsku'],
            'barcode' => ['barcode', 'ean', 'upc', 'gtin'],
            'counted_quantity' => ['countedquantity', 'countedqty', 'actualstock', 'actualquantity', 'count', 'quantity'],
            'notes' => ['notes', 'linenotes', 'note', 'remarks'],
        ];
        $needle = $this->canonical($header);

        foreach (self::TARGET_FIELDS as $field) {
            if ($needle === $this->canonical(__("stock_takes.import.fields.{$field}"))) {
                return $field;
            }
        }

        foreach ($aliases as $field => $candidates) {
            if (in_array($needle, $candidates, true)) {
                return $field;
            }
        }

        return null;
    }

    private function canonical(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(trim($value))) ?? '';
    }

    /** @param array<int|string, ?string> $mapping @return array<int, string> */
    private function normalizeMapping(array $mapping): array
    {
        $out = [];
        foreach ($mapping as $index => $field) {
            if (is_numeric($index) && is_string($field) && in_array($field, self::TARGET_FIELDS, true)) {
                $out[(int) $index] = $field;
            }
        }

        return $out;
    }

    /** @param array<int, string> $cells @param array<int, string> $mapping */
    private function valueFor(array $cells, array $mapping, string $field): ?string
    {
        $index = array_search($field, $mapping, true);

        return $index === false ? null : ($cells[$index] ?? null);
    }

    /** @param array<int, string> $cells */
    private function rowIsBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
