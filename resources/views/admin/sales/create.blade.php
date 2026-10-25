@php
    use Illuminate\Support\Js;
@endphp

{{-- `active` matches the nav item's id in config/admin-nav.php — this page
     has its own entry ("New sale"), so it must not light up Sales History. --}}
<x-admin-layout
    active="sale-create"
    :title="__('sales.create.title')"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('sales.title'), 'href' => route('admin.sales.index')],
        ['label' => __('sales.create.title')],
    ]">

    {{-- Back-office sale. Mirrors the purchase editor's shape (header card +
         line table + totals rail) but prices are resolved server-side and
         the running total comes from a /quote round-trip, so the preview
         can't drift from what CompleteSale records. --}}
    <div class="page-wide"
         x-data="adminSaleForm({
             searchUrl:        {{ Js::from(route('admin.products.search')) }},
             quoteUrl:         {{ Js::from(route('admin.sales.quote')) }},
             scanUrl:          {{ Js::from(route('admin.sales.scan')) }},
             customerSearchUrl:{{ Js::from(route('admin.customers.search')) }},
             paymentMethods:   {{ Js::from($paymentMethods->map(fn ($m) => [
                                    'id'                 => (string) $m->id,
                                    'name'               => $m->name,
                                    'requires_reference' => (bool) $m->requires_reference,
                                ])->values()) }},
             initialStoreId:   {{ Js::from(old('store_id', $defaultStoreId)) }},
             initialSaleDate:  {{ Js::from(old('sale_date', now()->toDateString())) }},
             labels:           {{ Js::from(['unnamed' => __('sales.create.fields.product')]) }},
         })">

        <form method="POST" action="{{ route('admin.sales.store') }}" novalidate data-ajax-form>
            @csrf

            <div class="page-header mb-6">
                <div class="flex items-start gap-3">
                    <a href="{{ route('admin.sales.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost"
                       aria-label="{{ __('sales.title') }}">
                        <x-icon name="back" class="w-4 h-4" />
                    </a>
                    <div>
                        <h1 class="page-title">{{ __('sales.create.title') }}</h1>
                        <p class="page-sub">{{ __('sales.create.sub') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.sales.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost">
                        {{ __('sales.create.actions.discard') }}
                    </a>
                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary" :disabled="items.length === 0">
                        <x-icon name="check" class="w-4 h-4" />
                        {{ __('sales.create.actions.save') }}
                    </button>
                </div>
            </div>

            {{-- The shift trade-off is stated up front rather than buried:
                 cash rung up here never reaches a drawer count. --}}
            <x-alert type="info" class="mb-5">{{ __('sales.create.shift_note') }}</x-alert>

            <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-5 items-start">
                {{-- ── LEFT: header + lines ──────────────────────────── --}}
                <div class="space-y-5 min-w-0">
                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('sales.create.sections.header') }}</div>
                            <div class="card-title-sub">{{ __('sales.create.sections.header_sub') }}</div>
                        </div></div>
                        <div class="card-body">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <label class="field">
                                    <span class="field-label">{{ __('sales.create.fields.store') }}</span>
                                    <select name="store_id" x-model="storeId"
                                            x-data="enhancedSelect({ value: {{ Js::from((string) old('store_id', $defaultStoreId)) }} })"
                                            class="pos-input" required>
                                        @foreach ($stores as $s)
                                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="field">
                                    <span class="field-label">{{ __('sales.create.fields.customer') }}</span>
                                    <select name="customer_id" x-model="customerId"
                                            x-data="remoteSelect({
                                                url: {{ Js::from(route('admin.customers.search')) }},
                                                placeholder: {{ Js::from(__('sales.create.fields.walk_in')) }},
                                            })"
                                            class="pos-input"></select>
                                    <span class="field-help">{{ __('sales.create.fields.customer_help') }}</span>
                                </label>

                                <label class="field">
                                    <span class="field-label">{{ __('sales.create.fields.sale_date') }}</span>
                                    <input type="text" name="sale_date" x-model="saleDate"
                                           class="pos-input js-datepicker" required>
                                </label>
                            </div>
                        </div>
                    </div>

                    {{-- ── Items ─────────────────────────────────────── --}}
                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('sales.create.sections.items') }}</div>
                            <div class="card-title-sub">{{ __('sales.create.sections.items_sub') }}</div>
                        </div></div>
                        <div class="card-body">
                            {{-- Barcode first: a wedge scanner types here and sends
                                 Enter, F8 focuses it from anywhere, and the camera
                                 button covers tablets. Scanning a line already on
                                 the sale bumps its qty rather than stacking a
                                 duplicate. --}}
                            <x-admin.barcode-scan-field
                                :placeholder="__('sales.create.scan.placeholder')"
                                :camera-title="__('sales.create.scan.camera_title')"
                                :camera-hint="__('sales.create.scan.camera_hint')"
                                :camera-label="__('sales.create.scan.camera_label')" />

                            {{-- Inline product picker — same shape as the purchase
                                 editor's: debounced remote search, parent row plus
                                 an indented row per variant. --}}
                            <div class="pur-picker mt-4" @click.outside="closeSearch()">
                                <label class="field">
                                    <span class="field-label">{{ __('sales.create.fields.add_product') }}</span>
                                    <input type="search" x-model="query" @input="onSearchInput()" @focus="focusSearch()"
                                           class="pos-input"
                                           placeholder="{{ __('sales.create.fields.add_product_placeholder') }}"
                                           data-no-validate data-no-live-search>
                                </label>
                                <div class="pur-picker-results" x-show="searchOpen" x-cloak>
                                    <template x-if="searching">
                                        <div class="pur-picker-row pur-picker-empty">{{ __('sales.create.picker.searching') }}</div>
                                    </template>
                                    <template x-if="!searching && results.length === 0">
                                        <div class="pur-picker-row pur-picker-empty">{{ __('sales.create.picker.empty') }}</div>
                                    </template>
                                    <template x-for="row in results" :key="row.value">
                                        <div>
                                            <button type="button" class="pur-picker-row" @click="addProduct(row)">
                                                <span x-text="row.label"></span>
                                            </button>
                                            <template x-for="v in (row.variants || [])" :key="v.id">
                                                <button type="button" class="pur-picker-row pur-picker-variant"
                                                        @click="addProduct(row, v)">
                                                    <span>↳ <span x-text="v.label"></span></span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div class="dt-scroll mt-4">
                                <table class="dt-table pur-lines dt-table--lines sale-lines" x-show="items.length > 0" x-cloak>
                                    <thead>
                                        <tr>
                                            <th>{{ __('sales.create.columns.product') }}</th>
                                            <th class="num">{{ __('sales.create.columns.quantity') }}</th>
                                            <th class="num">{{ __('sales.create.columns.unit_price') }}</th>
                                            <th class="num">{{ __('sales.create.columns.discount') }}</th>
                                            <th class="num">{{ __('sales.create.columns.line_total') }}</th>
                                            <th class="dt-actions-col"><span class="sr-only">{{ __('sales.create.actions.remove_line') }}</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(line, i) in items" :key="line._uid">
                                            <tr :data-line-uid="line._uid">
                                                <td>
                                                    <div class="pur-line-product">
                                                        <span x-text="line.product_label || labels.unnamed"></span>
                                                    </div>
                                                    <input type="hidden" :name="`items[${i}][product_id]`" :value="line.product_id">
                                                    <input type="hidden" :name="`items[${i}][variant_id]`" :value="line.variant_id">
                                                </td>
                                                {{-- `step="any"`, not a decimal step: the arrows/scroll then move
                                                     by whole units (nobody sells 0.0001 more of anything) while a
                                                     weighed item can still take a fractional quantity. --}}
                                                <td class="pur-line-qty">
                                                    <input type="number" step="any" min="0"
                                                           :name="`items[${i}][quantity]`" x-model="line.quantity"
                                                           @input="scheduleQuote()"
                                                           class="pos-input tnum pos-input-compact" data-no-validate required>
                                                </td>
                                                {{-- Read-only: price comes from the catalogue, never the form. --}}
                                                <td class="num tnum fg-secondary">
                                                    <span x-text="line.unit_price !== null ? formatMoney(line.unit_price) : '—'"></span>
                                                </td>
                                                <td>
                                                    <input type="number" step="0.01" min="0" max="100"
                                                           :name="`items[${i}][discount_percent]`" x-model="line.discount_percent"
                                                           @input="scheduleQuote()"
                                                           class="pos-input tnum pos-input-compact" data-no-validate>
                                                </td>
                                                <td class="num tnum">
                                                    <span x-text="line.line_total !== null ? formatMoney(line.line_total) : '—'"></span>
                                                </td>
                                                <td>
                                                    <div class="prod-row-actions">
                                                        <button type="button" class="prod-del-btn"
                                                                @click="removeLine(line._uid)"
                                                                :aria-label="{{ Js::from(__('sales.create.actions.remove_line')) }}">
                                                            <x-icon name="trash" class="w-4 h-4" />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <p class="fg-tertiary text-sm" x-show="items.length === 0" x-cloak>
                                {{ __('sales.create.items_empty') }}
                            </p>
                        </div>
                    </div>

                    {{-- ── Payment ───────────────────────────────────── --}}
                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('sales.create.sections.payment') }}</div>
                            <div class="card-title-sub">{{ __('sales.create.sections.payment_sub') }}</div>
                        </div></div>
                        <div class="card-body">
                            <div class="form-stack">
                                <template x-for="(p, i) in payments" :key="p._uid">
                                    <div class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_140px_minmax(0,1fr)_44px] gap-3 items-end">
                                        <label class="field">
                                            <span class="field-label">{{ __('sales.create.fields.payment_method') }}</span>
                                            <select :name="`payments[${i}][payment_method_id]`"
                                                    x-model="p.payment_method_id"
                                                    x-data="enhancedSelect({ value: p.payment_method_id })"
                                                    class="pos-input">
                                                @foreach ($paymentMethods as $m)
                                                    <option value="{{ $m->id }}">{{ $m->name }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span class="field-label">{{ __('sales.create.fields.amount') }}</span>
                                            <input type="number" step="0.0001" min="0"
                                                   :name="`payments[${i}][amount]`" x-model="p.amount"
                                                   class="pos-input tnum" data-no-validate>
                                        </label>
                                        <label class="field">
                                            <span class="field-label">{{ __('sales.create.fields.reference') }}</span>
                                            <input type="text" :name="`payments[${i}][reference]`" x-model="p.reference"
                                                   class="pos-input" maxlength="191" data-no-validate>
                                        </label>
                                        <button type="button" class="prod-del-btn mb-1"
                                                @click="removePayment(p._uid)"
                                                :aria-label="{{ Js::from(__('sales.create.payment.remove')) }}">
                                            <x-icon name="trash" class="w-4 h-4" />
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div class="flex items-center gap-3 mt-3">
                                <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost" @click="addPayment()">
                                    <x-icon name="plus" class="w-4 h-4" />
                                    {{ __('sales.create.payment.add') }}
                                </button>
                                <span class="fg-tertiary text-sm" x-show="payments.length === 0" x-cloak>
                                    {{ __('sales.create.payment.unpaid_help') }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('sales.create.sections.notes') }}</div>
                        </div></div>
                        <div class="card-body">
                            <textarea name="notes" x-model="notes" rows="4" maxlength="5000"
                                      class="pos-input">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- ── RIGHT: totals rail ────────────────────────────── --}}
                <div class="space-y-5">
                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('sales.create.sections.totals') }}</div>
                        </div></div>
                        <div class="card-body">
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                                <div>
                                    <dt class="fg-tertiary">{{ __('sales.create.totals.subtotal') }}</dt>
                                    <dd class="num tnum" x-text="formatMoney(totals.subtotal)"></dd>
                                </div>
                                <div>
                                    <dt class="fg-tertiary">{{ __('sales.create.totals.discount') }}</dt>
                                    <dd class="num tnum" x-text="formatMoney(totals.discount_total)"></dd>
                                </div>
                                <div>
                                    <dt class="fg-tertiary">{{ __('sales.create.totals.tax') }}</dt>
                                    <dd class="num tnum" x-text="formatMoney(totals.tax_total)"></dd>
                                </div>
                                <div class="col-span-2 border-t border-subtle pt-3">
                                    <dt class="font-semibold">{{ __('sales.create.totals.grand') }}</dt>
                                    <dd class="num tnum text-base font-semibold" x-text="formatMoney(totals.grand_total)"></dd>
                                </div>
                                <div>
                                    <dt class="fg-tertiary">{{ __('sales.create.totals.paid') }}</dt>
                                    <dd class="num tnum" x-text="formatMoney(paidTotal)"></dd>
                                </div>
                                <div>
                                    <dt class="fg-tertiary">{{ __('sales.create.totals.balance') }}</dt>
                                    <dd class="num tnum"
                                        :class="balanceDue > 0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : ''"
                                        x-text="formatMoney(balanceDue)"></dd>
                                </div>
                            </dl>
                            <p class="field-help mt-2">{{ __('sales.create.totals.preview_note') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-admin-layout>
