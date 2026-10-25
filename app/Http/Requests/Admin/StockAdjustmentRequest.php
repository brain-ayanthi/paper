<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id'        => ['required', 'integer', Rule::exists('stores', 'id')->where('is_active', true)],
            'adjustment_date' => ['required', 'date'],
            'reason_code_id'  => ['nullable', 'integer', Rule::exists('stock_adjustment_reasons', 'id')->where('is_active', true)],
            'reason'          => ['nullable', 'string', 'max:191'],
            'notes'           => ['nullable', 'string', 'max:1000'],

            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.variant_id'       => ['nullable', 'integer', Rule::exists('product_variants', 'id')],
            'items.*.batch_id'         => ['nullable', 'integer', Rule::exists('product_batches', 'id')],
            // New-batch capture (mirrors purchase-receive). A line carries
            // EITHER an existing batch_id OR these fields to create one at
            // post time — never both (batch_id wins in persistedAttributes).
            'items.*.batch_number'     => ['nullable', 'string', 'max:64'],
            'items.*.manufacture_date' => ['nullable', 'date'],
            // The mfg/expiry ordering is checked in withValidator() instead of
            // `after_or_equal:` — the built-in message names the raw payload
            // key ("items.0.manufacture_date"), which means nothing to a
            // stock keeper. Ours names the line and the product.
            'items.*.expiry_date'      => ['nullable', 'date'],
            // Delta is signed; bound BOTH ends to the DECIMAL(15,4) range so a
            // stray value (e.g. a barcode in the qty field) can't crash the DB.
            'items.*.quantity_delta'   => ['required', 'numeric', 'not_in:0', 'min:-'.StockTakeRequest::QTY_MAX, 'max:'.StockTakeRequest::QTY_MAX],
            'items.*.unit_cost'        => ['nullable', 'numeric', 'min:0', 'max:'.StockTakeRequest::QTY_MAX],
            'items.*.batch_selling_price' => ['nullable', 'numeric', 'gt:0', 'max:'.StockTakeRequest::QTY_MAX],
            'items.*.batch_mrp'           => ['nullable', 'numeric', 'gt:0', 'max:'.StockTakeRequest::QTY_MAX],
            'items.*.notes'            => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * Per-line cross-field guards. Every message names the line number the
     * operator can actually see, because a document can carry dozens of rows
     * and "items.7.expiry_date" tells them nothing about which one to fix.
     *
     *   - A new batch (batch_number, no batch_id) only makes sense on an
     *     inflow line: you can't materialise a batch and remove stock from it
     *     in the same breath. The UI hides the option on Out lines; this is
     *     the backstop.
     *   - Expiry can't precede manufacture.
     *   - The same product/variant can't name the same NEW batch number twice
     *     in one document — both lines would resolve to a single batch row
     *     (the find-or-create key is store+product+variant+number), so the
     *     operator thinks they logged two batches and got one. Two DIFFERENT
     *     products sharing a lot number is fine and common: the number comes
     *     off the manufacturer's box, and it only identifies a batch together
     *     with the product it belongs to.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $seenBatchKeys = [];

            foreach ((array) $this->input('items', []) as $i => $line) {
                $lineNo      = (int) $i + 1;
                $batchNumber = trim((string) ($line['batch_number'] ?? ''));
                $hasNewBatch = $batchNumber !== '' && empty($line['batch_id']);
                $delta       = isset($line['quantity_delta']) ? (float) $line['quantity_delta'] : 0;

                if ($hasNewBatch && $delta <= 0) {
                    $validator->errors()->add(
                        "items.{$i}.batch_number",
                        __('inventory.adjustments.errors.new_batch_in_only'),
                    );
                }

                $selling = $line['batch_selling_price'] ?? null;
                $mrp = $line['batch_mrp'] ?? null;
                if ($hasNewBatch && $selling !== null && $selling !== '' && $mrp !== null && $mrp !== ''
                    && is_numeric($selling) && is_numeric($mrp)
                    && (float) $mrp < (float) $selling) {
                    $validator->errors()->add(
                        "items.{$i}.batch_mrp",
                        __('inventory.adjustments.errors.batch_mrp_below_selling', ['line' => $lineNo]),
                    );
                }

                if (! empty($line['expiry_date']) && ! empty($line['manufacture_date'])
                    && strtotime((string) $line['expiry_date']) < strtotime((string) $line['manufacture_date'])) {
                    $validator->errors()->add(
                        "items.{$i}.expiry_date",
                        __('inventory.adjustments.errors.expiry_before_manufacture', ['line' => $lineNo]),
                    );
                }

                if ($hasNewBatch) {
                    $key = implode('|', [
                        $line['product_id'] ?? '',
                        $line['variant_id'] ?? '',
                        mb_strtolower($batchNumber),
                    ]);

                    if (isset($seenBatchKeys[$key])) {
                        $validator->errors()->add(
                            "items.{$i}.batch_number",
                            __('inventory.adjustments.errors.batch_number_duplicate', [
                                'batch' => $batchNumber,
                                'line'  => $lineNo,
                                'other' => $seenBatchKeys[$key],
                            ]),
                        );
                    } else {
                        $seenBatchKeys[$key] = $lineNo;
                    }
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.required'             => __('inventory.adjustments.errors.items_required'),
            'items.*.quantity_delta.not_in' => __('inventory.adjustments.errors.quantity_zero'),
        ];
    }

    /**
     * Friendly names for the line fields. Without these, any remaining
     * built-in message reads "The items.0.unit_cost field must be a number".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items.*.product_id'       => __('inventory.adjustments.items.col_product'),
            'items.*.variant_id'       => __('inventory.adjustments.items.col_variant'),
            'items.*.batch_id'         => __('inventory.adjustments.items.col_batch'),
            'items.*.batch_number'     => __('inventory.adjustments.items.batch_number_label'),
            'items.*.manufacture_date' => __('inventory.adjustments.items.manufacture_date'),
            'items.*.expiry_date'      => __('inventory.adjustments.items.expiry_date'),
            'items.*.quantity_delta'   => __('inventory.adjustments.items.col_qty'),
            'items.*.unit_cost'        => __('inventory.adjustments.items.col_cost'),
            'items.*.batch_mrp'        => __('inventory.adjustments.items.batch_mrp'),
            'items.*.notes'            => __('inventory.adjustments.items.col_notes'),
            'adjustment_date'          => __('inventory.adjustments.columns.date'),
            'store_id'                 => __('inventory.adjustments.columns.store'),
        ];
    }

    /**
     * Payload shape consumed by Create/UpdateStockAdjustment.
     *
     * @return array<string, mixed>
     */
    public function persistedAttributes(): array
    {
        $data = $this->validated();

        // Normalise the items: drop empty rows and cast numerics.
        $data['items'] = array_map(function (array $line) {
            $batchId = isset($line['batch_id']) && $line['batch_id'] !== '' ? (int) $line['batch_id'] : null;
            // An existing-batch pick wins: ignore any new-batch fields that
            // may have lingered in the payload so we never both reference a
            // batch and try to create one on the same line.
            $newBatchNumber = $batchId === null && ! empty($line['batch_number']) ? trim((string) $line['batch_number']) : null;

            return [
                'product_id'       => (int) $line['product_id'],
                'variant_id'       => isset($line['variant_id']) && $line['variant_id'] !== '' ? (int) $line['variant_id'] : null,
                'batch_id'         => $batchId,
                'batch_number'     => $newBatchNumber,
                'manufacture_date' => $newBatchNumber !== null && ! empty($line['manufacture_date']) ? $line['manufacture_date'] : null,
                'expiry_date'      => $newBatchNumber !== null && ! empty($line['expiry_date']) ? $line['expiry_date'] : null,
                'quantity_delta'   => (float) $line['quantity_delta'],
                'unit_cost'        => isset($line['unit_cost']) && $line['unit_cost'] !== '' ? (float) $line['unit_cost'] : null,
                'batch_selling_price' => isset($line['batch_selling_price']) && $line['batch_selling_price'] !== '' ? (float) $line['batch_selling_price'] : null,
                'batch_mrp'       => isset($line['batch_mrp']) && $line['batch_mrp'] !== '' ? (float) $line['batch_mrp'] : null,
                'notes'            => $line['notes'] ?? null,
            ];
        }, array_values($data['items']));

        return $data;
    }
}
