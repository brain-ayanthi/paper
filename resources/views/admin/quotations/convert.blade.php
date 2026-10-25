@php
    use Illuminate\Support\Js;
    $quotedChanged = bccomp((string) $quotation->grand_total, (string) $conversionTotals['grand_total'], 4) !== 0;
    $formatQuantity = function ($value): string {
        $quantity = (string) $value;
        return str_contains($quantity, '.') ? (rtrim(rtrim($quantity, '0'), '.') ?: '0') : $quantity;
    };
@endphp
<x-admin-layout
    active="quotations"
    :title="__('quotations.conversion.title')"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('quotations.title'), 'href' => route('admin.quotations.index')],
        ['label' => $quotation->displayNumber(), 'href' => route('admin.quotations.show', $quotation)],
        ['label' => __('quotations.conversion.title')],
    ]">
    <div class="page-wide max-w-5xl" x-data="{
        payments: [], nextId: 0,
        currency: {{ Js::from($quotation->currency_code) }},
        methods: {{ Js::from($paymentMethods->map(fn($method) => ['id' => (string)$method->id, 'name' => $method->name, 'requires_reference' => (bool)$method->requires_reference])->values()) }},
        grand: {{ Js::from((string) $conversionTotals['grand_total']) }},
        addPayment() { this.payments.push({ _id: ++this.nextId, payment_method_id: this.methods[0]?.id || '', amount: this.remaining.toFixed(2), reference: '' }); },
        removePayment(id) { this.payments = this.payments.filter(payment => payment._id !== id); },
        get paid() { return this.payments.reduce((sum, payment) => sum + (parseFloat(payment.amount) || 0), 0); },
        get remaining() { return Math.max(0, (parseFloat(this.grand) || 0) - this.paid); },
        requiresReference(payment) { return !!this.methods.find(method => method.id === payment.payment_method_id)?.requires_reference; },
        money(value) { return window.posFormatMoney ? window.posFormatMoney(value, this.currency) : Number(value || 0).toFixed(2); },
    }">
        <form method="POST" action="{{ route('admin.quotations.convert', $quotation) }}" data-ajax-form>
            @csrf
            <div class="page-header mb-6">
                <div class="flex items-start gap-3">
                    <a href="{{ route('admin.quotations.show', $quotation) }}" class="pos-btn pos-btn-sm pos-btn-ghost"><x-icon name="back" class="w-4 h-4" /></a>
                    <div><h1 class="page-title">{{ __('quotations.conversion.title') }}</h1><p class="page-sub">{{ __('quotations.conversion.sub', ['number' => $quotation->displayNumber()]) }}</p></div>
                </div>
                <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary"><x-icon name="check" class="w-4 h-4" />{{ __('quotations.conversion.complete') }}</button>
            </div>

            <x-alert type="warning" class="mb-5">{{ __('quotations.conversion.stock_warning') }}</x-alert>
            @if($quotedChanged)<x-alert type="info" class="mb-5">{{ __('quotations.conversion.tax_changed', ['quoted' => format_money($quotation->grand_total, null, $quotation->currency_code), 'current' => format_money($conversionTotals['grand_total'], null, $quotation->currency_code)]) }}</x-alert>@endif

            <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-5 items-start">
                <div class="space-y-5">
                    <div class="card"><div class="card-header"><div><div class="card-title">{{ __('quotations.sections.items') }}</div><div class="card-title-sub">{{ __('quotations.conversion.prices_honored') }}</div></div></div>
                        <div class="dt-scroll"><table class="dt-table"><thead><tr><th>{{ __('quotations.columns.product') }}</th><th class="num">{{ __('quotations.columns.quantity') }}</th><th class="num">{{ __('quotations.columns.unit_price') }}</th><th class="num">{{ __('quotations.columns.line_total') }}</th></tr></thead><tbody>
                            @foreach($quotation->items as $item)<tr><td>{{ $item->product_name_snapshot }}@if($item->variant_name_snapshot)<div class="text-xs fg-tertiary">{{ $item->variant_name_snapshot }}</div>@endif @if($item->notes)<div class="text-xs fg-secondary mt-1">{{ $item->notes }}</div>@endif</td><td class="num tnum">{{ $formatQuantity($item->quantity) }} {{ $item->unit }}</td><td class="num tnum">{{ format_money($item->unit_price, null, $quotation->currency_code) }}</td><td class="num tnum">{{ format_money($item->line_total, null, $quotation->currency_code) }}</td></tr>@endforeach
                        </tbody></table></div>
                    </div>

                    <div class="card"><div class="card-header"><div><div class="card-title">{{ __('quotations.conversion.payment') }}</div><div class="card-title-sub">{{ __('quotations.conversion.payment_sub') }}</div></div><button type="button" class="pos-btn pos-btn-sm pos-btn-ghost" @click="addPayment()"><x-icon name="plus" class="w-4 h-4" />{{ __('quotations.conversion.add_payment') }}</button></div>
                        <div class="card-body space-y-3">
                            <template x-for="(payment, index) in payments" :key="payment._id"><div class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_140px_minmax(0,1fr)_44px] gap-3 items-end">
                                <label class="field"><span class="field-label">{{ __('quotations.conversion.method') }}</span><select :name="`payments[${index}][payment_method_id]`" x-model="payment.payment_method_id" class="pos-input"><template x-for="method in methods" :key="method.id"><option :value="method.id" x-text="method.name"></option></template></select></label>
                                <label class="field"><span class="field-label">{{ __('quotations.conversion.amount') }}</span><input type="number" step="0.01" min="0.01" :name="`payments[${index}][amount]`" x-model="payment.amount" class="pos-input tnum"></label>
                                <label class="field"><span class="field-label">{{ __('quotations.conversion.reference') }}</span><input :name="`payments[${index}][reference]`" x-model="payment.reference" class="pos-input" :required="requiresReference(payment)"></label>
                                <button type="button" class="prod-del-btn mb-1" @click="removePayment(payment._id)" aria-label="{{ __('quotations.actions.remove_line') }}"><x-icon name="trash" class="w-4 h-4" /></button>
                            </div></template>
                            <p class="text-sm fg-tertiary" x-show="payments.length === 0">{{ $quotation->customer_id ? __('quotations.conversion.credit_help') : __('quotations.conversion.walk_in_payment_required') }}</p>
                        </div>
                    </div>
                </div>

                <div class="card sticky top-4"><div class="card-header"><div><div class="card-title">{{ __('quotations.conversion.sale_summary') }}</div></div></div><div class="card-body space-y-3">
                    <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.totals.subtotal') }}</span><span class="tnum">{{ format_money($conversionTotals['subtotal'], null, $quotation->currency_code) }}</span></div>
                    <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.totals.discount') }}</span><span class="tnum">{{ format_money($conversionTotals['discount_total'], null, $quotation->currency_code) }}</span></div>
                    <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.totals.tax') }}</span><span class="tnum">{{ format_money($conversionTotals['tax_total'], null, $quotation->currency_code) }}</span></div>
                    <div class="flex justify-between border-t border-[var(--color-border)] pt-3 text-lg font-semibold"><span>{{ __('quotations.totals.total') }}</span><span class="tnum">{{ format_money($conversionTotals['grand_total'], null, $quotation->currency_code) }}</span></div>
                    <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.conversion.paid') }}</span><span class="tnum" x-text="money(paid)"></span></div>
                    <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.conversion.balance') }}</span><span class="tnum" x-text="money(remaining)"></span></div>
                </div></div>
            </div>
        </form>
    </div>
</x-admin-layout>
