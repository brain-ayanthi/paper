<?php

namespace App\Actions\Inventory;

use App\Http\Requests\Admin\StockTakeRequest;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentReason;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Excel\SpreadsheetReader;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Build a reviewable stock-adjustment draft from a CSV / XLSX file. */
class ImportStockAdjustmentsFromCsv
{
    public const TARGET_FIELDS = [
        'product_sku',
        'variant_sku',
        'barcode',
        'direction',
        'quantity',
        'unit_cost',
        'batch_number',
        'manufacture_date',
        'expiry_date',
        'notes',
    ];

    public const PREVIEW_ROWS = 10;

    public function __construct(
        private SpreadsheetReader $reader,
        private ResolveInventoryImportProduct $resolveProduct,
        private CreateStockAdjustment $createAdjustment,
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
     * @param  array{store_id:int,adjustment_date:string,reason_code_id?:?int,reason?:?string,notes?:?string}  $document
     * @return array{adjustment:?StockAdjustment,imported:int,skipped:int,errors:array<int,array{row:int,message:string}>}
     */
    public function commit(string $path, array $mapping, array $document, User $user): array
    {
        $mapping = $this->normalizeMapping($mapping);
        $required = ['direction', 'quantity'];
        if (! collect($required)->every(fn ($field) => in_array($field, $mapping, true))) {
            throw new \RuntimeException(__('inventory.adjustments.import.errors.required_mapping'));
        }

        if (! collect(['product_sku', 'variant_sku', 'barcode'])
            ->contains(fn ($field) => in_array($field, $mapping, true))) {
            throw new \RuntimeException(__('inventory.import_common.errors.identifier_mapping_required'));
        }

        $reasonCode = ! empty($document['reason_code_id'])
            ? StockAdjustmentReason::query()->whereKey($document['reason_code_id'])->value('code')
            : null;
        $items = [];
        $errors = [];
        $seen = [];
        $rowNumber = 0;

        foreach ($this->reader->rows($path) as $index => $cells) {
            $rowNumber++;
            if ($index === 0 || $this->rowIsBlank($cells)) {
                continue;
            }

            $get = fn (string $field) => $this->valueFor($cells, $mapping, $field);
            $direction = $this->normalizeDirection($get('direction'));
            $quantity = trim((string) $get('quantity'));
            $unitCost = $this->nullableString($get('unit_cost'));
            $batchNumber = $this->nullableString($get('batch_number'));
            $manufactureDate = $this->nullableString($get('manufacture_date'));
            $expiryDate = $this->nullableString($get('expiry_date'));
            $notes = $this->nullableString($get('notes'));

            $validator = Validator::make([
                'direction' => $direction,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'batch_number' => $batchNumber,
                'manufacture_date' => $manufactureDate,
                'expiry_date' => $expiryDate,
                'notes' => $notes,
            ], [
                'direction' => ['required', Rule::in(['in', 'out'])],
                'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:'.StockTakeRequest::QTY_MAX],
                'unit_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'max:'.StockTakeRequest::QTY_MAX],
                'batch_number' => ['nullable', 'string', 'max:64'],
                'manufacture_date' => ['nullable', 'date_format:Y-m-d'],
                'expiry_date' => ['nullable', 'date_format:Y-m-d'],
                'notes' => ['nullable', 'string', 'max:191'],
            ], [], [
                'direction' => __('inventory.adjustments.import.fields.direction'),
                'quantity' => __('inventory.adjustments.import.fields.quantity'),
                'unit_cost' => __('inventory.adjustments.import.fields.unit_cost'),
                'batch_number' => __('inventory.adjustments.import.fields.batch_number'),
                'manufacture_date' => __('inventory.adjustments.import.fields.manufacture_date'),
                'expiry_date' => __('inventory.adjustments.import.fields.expiry_date'),
                'notes' => __('inventory.adjustments.import.fields.notes'),
            ]);

            if ($validator->fails()) {
                $errors[] = ['row' => $rowNumber, 'message' => $validator->errors()->first()];

                continue;
            }
            if ($manufactureDate !== null && $expiryDate !== null && $expiryDate < $manufactureDate) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => __('inventory.adjustments.errors.expiry_before_manufacture', ['line' => $rowNumber]),
                ];

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
            $quantity = bcadd($quantity, '0', 4);
            $delta = $direction === 'out' ? bcmul($quantity, '-1', 4) : $quantity;

            if ($reasonCode === 'opening_stock' && $direction === 'in'
                && ($unitCost === null || bccomp($unitCost, '0', 4) <= 0)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => __('inventory.adjustments.import.errors.opening_cost_required'),
                ];

                continue;
            }

            $batch = $this->prepareBatch(
                (int) $document['store_id'],
                $product,
                $variant,
                $direction,
                $batchNumber,
                $manufactureDate,
                $expiryDate,
            );
            if ($batch['error']) {
                $errors[] = ['row' => $rowNumber, 'message' => $batch['error']];

                continue;
            }

            $key = implode(':', [
                $product->id,
                $variant?->id ?? 0,
                Str::lower($batchNumber ?? ''),
            ]);
            if (isset($seen[$key])) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => __('inventory.adjustments.import.errors.duplicate_line', ['row' => $seen[$key]]),
                ];

                continue;
            }
            $seen[$key] = $rowNumber;

            if ($direction === 'out' && ! $this->hasSufficientStock(
                (int) $document['store_id'],
                $product,
                $variant,
                $batch['batch'],
                $quantity,
            )) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => __('inventory.adjustments.import.errors.insufficient_stock', ['product' => $product->name]),
                ];

                continue;
            }

            $items[] = [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'batch_id' => $batch['batch']?->id,
                'batch_number' => $batch['batch'] ? null : $batchNumber,
                'manufacture_date' => $batch['batch'] ? null : $manufactureDate,
                'expiry_date' => $batch['batch'] ? null : $expiryDate,
                'quantity_delta' => $delta,
                'unit_cost' => $unitCost === null ? null : bcadd($unitCost, '0', 4),
                'notes' => $notes,
            ];
        }

        $adjustment = null;
        if ($items !== []) {
            $adjustment = ($this->createAdjustment)($document + ['items' => $items], $user);
        }

        return [
            'adjustment' => $adjustment,
            'imported' => count($items),
            'skipped' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @return array{batch:?ProductBatch,error:?string}
     */
    private function prepareBatch(
        int $storeId,
        Product $product,
        ?ProductVariant $variant,
        string $direction,
        ?string $batchNumber,
        ?string $manufactureDate,
        ?string $expiryDate,
    ): array {
        if (! $product->track_batches) {
            if ($batchNumber !== null || $manufactureDate !== null || $expiryDate !== null) {
                return ['batch' => null, 'error' => __('inventory.adjustments.import.errors.batch_not_tracked')];
            }

            return ['batch' => null, 'error' => null];
        }

        if ($batchNumber === null) {
            return ['batch' => null, 'error' => __('inventory.adjustments.import.errors.batch_required')];
        }

        $query = ProductBatch::query()
            ->where('store_id', $storeId)
            ->where('product_id', $product->id)
            ->where('variant_id', $variant?->id)
            ->where('batch_number', $batchNumber);

        $batch = $direction === 'in' ? $query->withTrashed()->first() : $query->first();
        if ($direction === 'out' && ! $batch) {
            return ['batch' => null, 'error' => __('inventory.adjustments.import.errors.out_batch_unknown', ['batch' => $batchNumber])];
        }

        return ['batch' => $batch, 'error' => null];
    }

    private function hasSufficientStock(
        int $storeId,
        Product $product,
        ?ProductVariant $variant,
        ?ProductBatch $batch,
        string $quantity,
    ): bool {
        $available = $batch
            ? (string) $batch->quantity
            : (string) (StockLevel::query()
                ->where('store_id', $storeId)
                ->where('product_id', $product->id)
                ->where('variant_id', $variant?->id)
                ->value('quantity') ?? '0');

        return bccomp($available, $quantity, 4) >= 0;
    }

    private function normalizeDirection(?string $value): ?string
    {
        return match ($this->canonical((string) $value)) {
            'in', 'add', 'increase', 'stockin', 'plus', 'positive' => 'in',
            'out', 'remove', 'decrease', 'stockout', 'minus', 'negative' => 'out',
            default => null,
        };
    }

    private function suggestField(string $header): ?string
    {
        $aliases = [
            'product_sku' => ['productsku', 'sku', 'productcode', 'itemcode'],
            'variant_sku' => ['variantsku', 'variation sku', 'childsku'],
            'barcode' => ['barcode', 'ean', 'upc', 'gtin'],
            'direction' => ['direction', 'directioninout', 'inout', 'movement', 'type'],
            'quantity' => ['quantity', 'qty', 'adjustmentquantity'],
            'unit_cost' => ['unitcost', 'cost', 'costprice'],
            'batch_number' => ['batchnumber', 'batch', 'lot', 'lotnumber'],
            'manufacture_date' => ['manufacturedate', 'manufacturingdate', 'mfgdate'],
            'expiry_date' => ['expirydate', 'expirationdate', 'expdate'],
            'notes' => ['notes', 'linenotes', 'note', 'remarks'],
        ];
        $needle = $this->canonical($header);

        foreach (self::TARGET_FIELDS as $field) {
            if ($needle === $this->canonical(__("inventory.adjustments.import.fields.{$field}"))) {
                return $field;
            }
        }

        foreach ($aliases as $field => $candidates) {
            if (in_array($needle, array_map([$this, 'canonical'], $candidates), true)) {
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
