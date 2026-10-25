@php
    use Illuminate\Support\Js;
    $editing = isset($quotation);
    $customerSnapshot = $editing ? ($quotation->customer_snapshot ?? []) : [];
    $billing = $editing ? ($quotation->billing_address_snapshot ?? []) : [];
    $shipping = $editing ? ($quotation->shipping_address_snapshot ?? []) : [];
    $formatAddress = function (array $address): string {
        if (isset($address['formatted'])) return (string) $address['formatted'];
        return implode("\n", array_filter([
            $address['line1'] ?? null, $address['line2'] ?? null,
            trim(implode(', ', array_filter([$address['city'] ?? null, $address['state'] ?? null]))),
            trim(implode(' ', array_filter([$address['postal_code'] ?? null, $address['country_code'] ?? null]))),
            $address['landmark'] ?? null,
        ]));
    };
    $customerId = old('customer_id', $selectedCustomer?->id);
    $customerLabel = $selectedCustomer
        ? $selectedCustomer->name.($selectedCustomer->phone ? ' · '.$selectedCustomer->phone : '')
        : '';
@endphp
<x-admin-layout
    :active="$editing ? 'quotations' : 'quotation-create'"
    :title="$editing ? __('quotations.edit.title') : __('quotations.create.title')"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('quotations.title'), 'href' => route('admin.quotations.index')],
        ['label' => $editing ? __('quotations.edit.title') : __('quotations.create.title')],
    ]">
    <div class="page-wide"
         x-data="adminSaleForm({
             searchUrl: {{ Js::from(route('admin.products.search')) }},
             quoteUrl: {{ Js::from(route('admin.quotations.preview')) }},
             scanUrl: {{ Js::from(route('admin.quotations.scan')) }},
             initialStoreId: {{ Js::from((string) old('store_id', $defaultStoreId)) }},
             initialSaleDate: {{ Js::from(old('issue_date', $editing ? $quotation->issue_date->toDateString() : now()->toDateString())) }},
             initialCustomerId: {{ Js::from((string) $customerId) }},
             initialItems: {{ Js::from($initialItems) }},
             labels: {{ Js::from(['unnamed' => __('quotations.fields.product')]) }},
         })">
        <form method="POST" action="{{ $editing ? route('admin.quotations.update', $quotation) : route('admin.quotations.store') }}" data-ajax-form novalidate>
            @csrf
            @if ($editing) @method('PATCH') @endif

            <div class="page-header mb-6">
                <div class="flex items-start gap-3">
                    <a href="{{ $editing ? route('admin.quotations.show', $quotation) : route('admin.quotations.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('quotations.title') }}">
                        <x-icon name="back" class="w-4 h-4" />
                    </a>
                    <div>
                        <h1 class="page-title">{{ $editing ? __('quotations.edit.title') : __('quotations.create.title') }}</h1>
                        <p class="page-sub">{{ __('quotations.create.sub') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.quotations.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost">{{ __('quotations.actions.discard') }}</a>
                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary" :disabled="items.length === 0">
                        <x-icon name="check" class="w-4 h-4" />{{ __('quotations.actions.save_draft') }}
                    </button>
                </div>
            </div>

            <x-alert type="info" class="mb-5">{{ __('quotations.create.no_stock_note') }}</x-alert>

            <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-5 items-start">
                <div class="space-y-5 min-w-0">
                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('quotations.sections.details') }}</div>
                            <div class="card-title-sub">{{ __('quotations.sections.details_sub') }}</div>
                        </div></div>
                        <div class="card-body space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                                <label class="field">
                                    <span class="field-label is-required">{{ __('quotations.fields.store') }}</span>
                                    @if ($editing)<input type="hidden" name="store_id" value="{{ $quotation->store_id }}">@endif
                                    <select @unless($editing) name="store_id" @endunless x-model="storeId" x-data="enhancedSelect({ value: {{ Js::from((string) old('store_id', $defaultStoreId)) }} })" class="pos-input" required @disabled($editing)>
                                        @foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="field">
                                    <span class="field-label">{{ __('quotations.fields.customer') }}</span>
                                    <select name="customer_id" x-model="customerId" x-data="remoteSelect({
                                        url: {{ Js::from(route('admin.customers.search')) }},
                                        value: {{ Js::from((string) $customerId) }},
                                        label: {{ Js::from($customerLabel) }},
                                        placeholder: {{ Js::from(__('quotations.fields.prospect')) }},
                                    })" class="pos-input"></select>
                                </label>
                                <label class="field">
                                    <span class="field-label is-required">{{ __('quotations.fields.issue_date') }}</span>
                                    <input type="text" name="issue_date" x-model="saleDate" class="pos-input js-datepicker" required>
                                </label>
                                <label class="field">
                                    <span class="field-label">{{ __('quotations.fields.valid_until') }}</span>
                                    <input type="text" name="valid_until" value="{{ old('valid_until', $editing ? optional($quotation->valid_until)->toDateString() : now()->addDays(7)->toDateString()) }}" class="pos-input js-datepicker">
                                </label>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4" x-show="!customerId" x-cloak>
                                <label class="field"><span class="field-label is-required">{{ __('quotations.fields.contact_name') }}</span><input name="contact_name" value="{{ old('contact_name', $customerSnapshot['name'] ?? '') }}" class="pos-input"></label>
                                <label class="field"><span class="field-label">{{ __('quotations.fields.contact_email') }}</span><input type="email" name="contact_email" value="{{ old('contact_email', $customerSnapshot['email'] ?? '') }}" class="pos-input"></label>
                                <label class="field"><span class="field-label">{{ __('quotations.fields.contact_phone') }}</span><input name="contact_phone" value="{{ old('contact_phone', $customerSnapshot['phone'] ?? '') }}" class="pos-input"></label>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <label class="field"><span class="field-label">{{ __('quotations.fields.billing_address') }}</span><textarea name="billing_address" rows="3" class="pos-input">{{ old('billing_address', $formatAddress($billing)) }}</textarea></label>
                                <label class="field"><span class="field-label">{{ __('quotations.fields.shipping_address') }}</span><textarea name="shipping_address" rows="3" class="pos-input">{{ old('shipping_address', $formatAddress($shipping)) }}</textarea></label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('quotations.sections.items') }}</div>
                            <div class="card-title-sub">{{ __('quotations.sections.items_sub') }}</div>
                        </div></div>
                        <div class="card-body">
                            <x-admin.barcode-scan-field
                                :placeholder="__('quotations.scan.placeholder')"
                                :camera-title="__('quotations.scan.camera_title')"
                                :camera-hint="__('quotations.scan.camera_hint')"
                                :camera-label="__('quotations.scan.camera_label')" />
                            <div class="pur-picker mt-4" @click.outside="closeSearch()">
                                <label class="field">
                                    <span class="field-label">{{ __('quotations.fields.add_product') }}</span>
                                    <input type="search" x-model="query" @input="onSearchInput()" @focus="focusSearch()" class="pos-input" placeholder="{{ __('quotations.fields.add_product_placeholder') }}" data-no-validate data-no-live-search>
                                </label>
                                <div class="pur-picker-results" x-show="searchOpen" x-cloak>
                                    <template x-if="searching"><div class="pur-picker-row pur-picker-empty">{{ __('quotations.picker.searching') }}</div></template>
                                    <template x-if="!searching && results.length === 0"><div class="pur-picker-row pur-picker-empty">{{ __('quotations.picker.empty') }}</div></template>
                                    <template x-for="row in results" :key="row.value"><div>
                                        <button type="button" class="pur-picker-row" @click="addProduct(row)"><span x-text="row.label"></span></button>
                                        <template x-for="variant in (row.variants || [])" :key="variant.id"><button type="button" class="pur-picker-row pur-picker-variant" @click="addProduct(row, variant)">↳ <span x-text="variant.label"></span></button></template>
                                    </div></template>
                                </div>
                            </div>
                            <div class="dt-scroll mt-4">
                                <table class="dt-table pur-lines dt-table--lines sale-lines" x-show="items.length > 0" x-cloak>
                                    <thead><tr>
                                        <th>{{ __('quotations.columns.product') }}</th><th class="num">{{ __('quotations.columns.quantity') }}</th>
                                        <th class="num">{{ __('quotations.columns.unit_price') }}</th><th class="num">{{ __('quotations.columns.discount') }}</th>
                                        <th class="num">{{ __('quotations.columns.line_total') }}</th><th class="dt-actions-col"></th>
                                    </tr></thead>
                                    <tbody><template x-for="(line, i) in items" :key="line._uid"><tr :data-line-uid="line._uid">
                                        <td><span x-text="line.product_label || labels.unnamed"></span><input type="hidden" :name="`items[${i}][product_id]`" :value="line.product_id"><input type="hidden" :name="`items[${i}][variant_id]`" :value="line.variant_id"><input type="text" :name="`items[${i}][notes]`" x-model="line.notes" class="pos-input pos-input-compact mt-2" placeholder="{{ __('quotations.fields.line_notes') }}" data-no-validate></td>
                                        <td><input type="number" step="any" min="0" :name="`items[${i}][quantity]`" x-model="line.quantity" @input="scheduleQuote()" class="pos-input tnum pos-input-compact" required data-no-validate></td>
                                        <td class="num tnum fg-secondary"><span x-text="line.unit_price !== null ? formatMoney(line.unit_price) : '—'"></span></td>
                                        <td><input type="number" step="0.01" min="0" max="100" :name="`items[${i}][discount_percent]`" x-model="line.discount_percent" @input="scheduleQuote()" class="pos-input tnum pos-input-compact" data-no-validate></td>
                                        <td class="num tnum"><span x-text="line.line_total !== null ? formatMoney(line.line_total) : '—'"></span></td>
                                        <td><button type="button" class="prod-del-btn" @click="removeLine(line._uid)" :aria-label="{{ Js::from(__('quotations.actions.remove_line')) }}"><x-icon name="trash" class="w-4 h-4" /></button></td>
                                    </tr></template></tbody>
                                </table>
                            </div>
                            <p class="fg-tertiary text-sm" x-show="items.length === 0">{{ __('quotations.items_empty') }}</p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><div><div class="card-title">{{ __('quotations.sections.terms') }}</div><div class="card-title-sub">{{ __('quotations.sections.terms_sub') }}</div></div></div>
                        <div class="card-body space-y-4">
                            <label class="field"><span class="field-label">{{ __('quotations.fields.expected_delivery') }}</span><input type="text" name="expected_delivery_date" value="{{ old('expected_delivery_date', $editing ? optional($quotation->expected_delivery_date)->toDateString() : '') }}" class="pos-input js-datepicker max-w-xs"></label>
                            <label class="field"><span class="field-label">{{ __('quotations.fields.terms') }}</span><textarea name="terms" rows="4" class="pos-input">{{ old('terms', $editing ? $quotation->terms : '') }}</textarea></label>
                            <label class="field"><span class="field-label">{{ __('quotations.fields.customer_notes') }}</span><textarea name="notes" rows="3" class="pos-input">{{ old('notes', $editing ? $quotation->notes : '') }}</textarea></label>
                            <label class="field"><span class="field-label">{{ __('quotations.fields.internal_notes') }}</span><textarea name="internal_notes" rows="3" class="pos-input">{{ old('internal_notes', $editing ? $quotation->internal_notes : '') }}</textarea><span class="field-help">{{ __('quotations.fields.internal_notes_help') }}</span></label>
                        </div>
                    </div>
                </div>

                <div class="card sticky top-4">
                    <div class="card-header"><div><div class="card-title">{{ __('quotations.sections.summary') }}</div></div></div>
                    <div class="card-body space-y-3">
                        <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.totals.subtotal') }}</span><span class="tnum" x-text="formatMoney(totals.subtotal)"></span></div>
                        <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.totals.discount') }}</span><span class="tnum" x-text="formatMoney(totals.discount_total)"></span></div>
                        <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.totals.tax') }}</span><span class="tnum" x-text="formatMoney(totals.tax_total)"></span></div>
                        <div class="border-t border-[var(--color-border)] pt-3 flex justify-between text-lg font-semibold"><span>{{ __('quotations.totals.total') }}</span><span class="tnum" x-text="formatMoney(totals.grand_total)"></span></div>
                        <p class="text-xs fg-tertiary">{{ __('quotations.create.total_help') }}</p>
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-admin-layout>
