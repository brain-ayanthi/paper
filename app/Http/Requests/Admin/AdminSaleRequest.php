<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Validates the back-office POST /admin/sales payload — shape only.
 * Business rules (stock, payment sums, credit limits, discount governance)
 * live in {@see App\Actions\Sales\CompleteSale}, per the system-wide
 * deferred-validation convention.
 *
 * Deliberately NOT a subclass of {@see CompleteSaleRequest}: this form
 * accepts **no `unit_price`**. The cashier is a trusted till that prices
 * its own cart from a synced catalog; the back-office form is not, so
 * {@see App\Http\Controllers\Admin\SaleController::store()} resolves every
 * price from the catalog. That keeps markdowns flowing through the
 * `discount_percent` / `discount_amount` fields where the `sales.discount`
 * permission and the store threshold still govern them — a price typed
 * straight into the line would route around both.
 *
 * `batch_id` is absent for the same reason: the controller picks FEFO so
 * the operator never has to think about batches.
 */
class AdminSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An HTML form with no payment rows sends no `payments` key at all —
     * there are simply no inputs to serialise — so the `present` rule below
     * would reject the very thing it's meant to allow: an unpaid credit
     * sale. A JSON client can send `payments: []`; a form cannot. Default it
     * here so both clients mean the same thing.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('payments')) {
            $this->merge(['payments' => []]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id'    => ['required', 'integer', Rule::exists('stores', 'id')->where('is_active', true)],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            // Back-dating is allowed (recording a sale after the fact is the
            // point); post-dating is not, matching the cashier's rule.
            'sale_date'   => ['nullable', 'date', 'before_or_equal:today'],
            'local_uuid'  => ['nullable', 'string', 'max:36'],
            'notes'       => ['nullable', 'string', 'max:5000'],

            'items'                    => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id'       => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.variant_id'       => [
                'nullable', 'integer',
                // A variant must belong to ITS OWN line's product. Without this
                // a crafted POST pairs product 1 with product 7's variant and
                // corrupts both products' stock. Mirrors PurchaseRequest.
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (! preg_match('/^items\.(\d+)\.variant_id$/', $attribute, $m)) {
                        return;
                    }
                    $productId = $this->input("items.{$m[1]}.product_id");
                    if ($productId === null || $productId === '') {
                        return;
                    }
                    $belongs = DB::table('product_variants')
                        ->where('id', $value)
                        ->where('product_id', $productId)
                        ->whereNull('deleted_at')
                        ->exists();
                    if (! $belongs) {
                        $fail(__('sales.create.errors.variant_product_mismatch'));
                    }
                },
            ],
            'items.*.quantity'         => ['required', 'numeric', 'gt:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.notes'            => ['nullable', 'string', 'max:500'],

            // `present`, not `required` — an empty array posts a credit sale
            // (the whole total lands on the customer's account). CompleteSale
            // enforces "credit needs a customer".
            'payments'                     => ['present', 'array', 'max:10'],
            'payments.*.payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'payments.*.amount'            => ['required', 'numeric', 'gt:0'],
            'payments.*.reference'         => ['nullable', 'string', 'max:191'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'store_id'                     => __('sales.create.fields.store'),
            'customer_id'                  => __('sales.create.fields.customer'),
            'sale_date'                    => __('sales.create.fields.sale_date'),
            'items'                        => __('sales.create.fields.items'),
            'items.*.product_id'           => __('sales.create.fields.product'),
            'items.*.quantity'             => __('sales.create.fields.quantity'),
            'items.*.discount_percent'     => __('sales.create.fields.discount'),
            'payments.*.payment_method_id' => __('sales.create.fields.payment_method'),
            'payments.*.amount'            => __('sales.create.fields.amount'),
        ];
    }

    /**
     * Header for {@see App\Actions\Sales\CompleteSale}. `origin` is stamped
     * here rather than accepted from the client — it is what exempts the
     * sale from till-shift enforcement, so it must never be spoofable.
     *
     * @return array<string, mixed>
     */
    public function headerData(): array
    {
        return [
            'store_id'    => (int) $this->input('store_id'),
            'customer_id' => $this->filled('customer_id') ? (int) $this->input('customer_id') : null,
            'sale_date'   => $this->input('sale_date'),
            'local_uuid'  => $this->input('local_uuid'),
            'notes'       => $this->input('notes'),
            'origin'      => 'admin',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function lineData(): array
    {
        return array_values($this->input('items', []));
    }

    /** @return array<int, array<string, mixed>> */
    public function paymentData(): array
    {
        return array_values($this->input('payments', []));
    }
}
