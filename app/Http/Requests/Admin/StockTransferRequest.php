<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where('is_active', true)],
            'to_store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where('is_active', true), Rule::notIn([$this->input('from_store_id')])],
            'transfer_date' => ['required', 'date'],
            'expected_arrival_date' => ['nullable', 'date', 'after_or_equal:transfer_date'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')],
            'items.*.batch_id' => ['nullable', 'integer'],
            // Bound to the DECIMAL(15,4) range so a stray value (e.g. a barcode
            // in the qty field) is rejected with an error, not a DB crash.
            'items.*.requested_quantity' => ['required', 'numeric', 'gt:0', 'max:'.StockTakeRequest::QTY_MAX],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:'.StockTakeRequest::QTY_MAX],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('items', []);
            if (! is_array($items) || $items === []) {
                return;
            }

            $products = Product::query()
                ->whereIn('id', collect($items)->pluck('product_id')->filter()->unique())
                ->get(['id', 'track_batches'])
                ->keyBy('id');

            $batches = ProductBatch::query()
                ->whereIn('id', collect($items)->pluck('batch_id')->filter()->unique())
                ->get(['id', 'store_id', 'product_id', 'variant_id'])
                ->keyBy('id');

            $sourceStoreId = (int) $this->input('from_store_id');
            $seenBatches = [];

            foreach (array_values($items) as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $productId = (int) ($line['product_id'] ?? 0);
                $variantId = isset($line['variant_id']) && $line['variant_id'] !== ''
                    ? (int) $line['variant_id']
                    : null;
                $batchId = isset($line['batch_id']) && $line['batch_id'] !== ''
                    ? (int) $line['batch_id']
                    : null;
                $product = $products->get($productId);
                $tracksBatches = (bool) ($product?->track_batches ?? false);
                $field = "items.{$index}.batch_id";

                if ($tracksBatches && $batchId === null) {
                    $validator->errors()->add($field, __('inventory.transfers.errors.batch_required'));

                    continue;
                }

                if ($batchId === null) {
                    continue;
                }

                if (! $tracksBatches) {
                    $validator->errors()->add($field, __('inventory.transfers.errors.batch_not_tracked'));

                    continue;
                }

                $batch = $batches->get($batchId);
                if (! $batch
                    || (int) $batch->store_id !== $sourceStoreId
                    || (int) $batch->product_id !== $productId
                    || ($batch->variant_id !== null ? (int) $batch->variant_id : null) !== $variantId) {
                    $validator->errors()->add($field, __('inventory.transfers.errors.invalid_batch'));

                    continue;
                }

                if (isset($seenBatches[$batchId])) {
                    $validator->errors()->add($field, __('inventory.transfers.errors.duplicate_batch'));

                    continue;
                }

                $seenBatches[$batchId] = true;
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to_store_id.not_in' => __('inventory.transfers.errors.same_store'),
            'items.required' => __('inventory.transfers.errors.items_required'),
            'items.min' => __('inventory.transfers.errors.items_required'),
            'items.*.requested_quantity.gt' => __('inventory.transfers.errors.quantity_zero'),
        ];
    }

    /**
     * Friendly field names so validation reads "The destination store
     * field is required" instead of "The to store id field is required".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from_store_id' => __('inventory.transfers.fields.from_store'),
            'to_store_id' => __('inventory.transfers.fields.to_store'),
            'transfer_date' => __('inventory.transfers.fields.transfer_date'),
            'items.*.product_id' => __('inventory.transfers.items.col_product'),
            'items.*.batch_id' => __('inventory.transfers.items.col_batch'),
            'items.*.requested_quantity' => __('inventory.transfers.items.col_qty'),
            'items.*.unit_cost' => __('inventory.transfers.items.col_cost'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function persistedAttributes(): array
    {
        $data = $this->validated();

        $batchCosts = ProductBatch::query()
            ->whereIn('id', collect($data['items'])->pluck('batch_id')->filter()->unique())
            ->pluck('cost_price', 'id');

        $data['items'] = array_map(function (array $line) use ($batchCosts): array {
            $batchId = isset($line['batch_id']) && $line['batch_id'] !== '' ? (int) $line['batch_id'] : null;
            $batchCost = $batchId !== null ? $batchCosts->get($batchId) : null;

            return [
                'product_id' => (int) $line['product_id'],
                'variant_id' => isset($line['variant_id']) && $line['variant_id'] !== '' ? (int) $line['variant_id'] : null,
                'batch_id' => $batchId,
                'requested_quantity' => (string) $line['requested_quantity'],
                // A selected batch owns its cost. Do not trust a browser-posted
                // replacement; non-batch transfers keep the existing input.
                'unit_cost' => $batchId !== null
                    ? ($batchCost !== null ? (string) $batchCost : null)
                    : (isset($line['unit_cost']) && $line['unit_cost'] !== '' ? (string) $line['unit_cost'] : null),
            ];
        }, array_values($data['items']));

        return $data;
    }
}
