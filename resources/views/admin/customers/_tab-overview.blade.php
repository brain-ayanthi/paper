{{-- Overview tab — who this customer is, plus anything demanding action
     (open sales) right at the top of the right column. --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
    {{-- ── LEFT column: Profile + Business ───────────────────────── --}}
    <div class="space-y-5">
        <div class="card">
            <div class="card-header"><div>
                <div class="card-title">{{ __('customers.sections.profile') }}</div>
            </div></div>
            <div class="card-body">
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="fg-tertiary">{{ __('customers.fields.phone') }}</dt><dd class="mono">{{ $customer->phone ? \App\Support\PhoneFormatter::pretty($customer->phone) : '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('customers.fields.whatsapp_phone') }}</dt><dd class="mono">{{ $customer->whatsapp_phone ? \App\Support\PhoneFormatter::pretty($customer->whatsapp_phone) : '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('customers.fields.email') }}</dt><dd>{{ $customer->email ? $customer->display_email : '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('customers.fields.dob') }}</dt><dd>{{ $customer->dob ? format_date($customer->dob) : '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('customers.fields.gender') }}</dt><dd>{{ $customer->gender ? __('customers.gender.'.$customer->gender) : '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('customers.fields.group') }}</dt><dd>{{ $customer->group?->name ?: '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('customers.fields.discount') }}</dt><dd>{{ $customer->default_discount_percent ? rtrim(rtrim(number_format((float) $customer->default_discount_percent, 4, '.', ''), '0'), '.').'%' : '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('customers.fields.credit_limit') }}</dt><dd>{{ $customer->credit_limit ? format_money($customer->credit_limit) : '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('customers.kpis.first_store') }}</dt><dd>{{ $customer->firstStore?->name ?: '—' }}</dd></div>
                </dl>
            </div>
        </div>

        @if ($customer->is_business)
            <div class="card">
                <div class="card-header"><div>
                    <div class="card-title">{{ __('customers.sections.business') }}</div>
                </div></div>
                <div class="card-body">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                        <div class="col-span-2"><dt class="fg-tertiary">{{ __('customers.fields.business_name') }}</dt><dd>{{ $customer->business_name ?: '—' }}</dd></div>
                        <div><dt class="fg-tertiary">{{ __('customers.fields.gstin') }}</dt><dd class="mono">{{ $customer->gstin ?: '—' }}</dd></div>
                        <div><dt class="fg-tertiary">{{ __('customers.fields.pan') }}</dt><dd class="mono">{{ $customer->pan ?: '—' }}</dd></div>
                        <div class="col-span-2"><dt class="fg-tertiary">{{ __('customers.fields.tax_reg') }}</dt><dd class="mono">{{ $customer->tax_registration_number ?: '—' }}</dd></div>
                    </dl>
                </div>
            </div>
        @endif
    </div>

    {{-- ── RIGHT column: Open sales + Notes ──────────────────────── --}}
    <div class="space-y-5">
        {{-- Open sales drill-down — listed oldest-first, with per-sale
             aging. Sale row clicks through to the sale show page where
             the cashier can record a payment or process a refund. --}}
        @if ($openSales->isNotEmpty())
            @php $asOf = \Carbon\CarbonImmutable::today(); @endphp
            <div class="card card-pad-0">
                <div class="card-header"><div>
                    <div class="card-title">{{ __('customers.sections.open_sales') }}</div>
                    <div class="card-title-sub">{{ __('customers.sections.open_sales_sub', ['count' => $openSales->count()]) }}</div>
                </div></div>
                <div class="dt-scroll">
                    <table class="dt-table">
                        <thead>
                            <tr>
                                <th>{{ __('customers.open_sales.sale') }}</th>
                                <th>{{ __('customers.open_sales.date') }}</th>
                                <th class="num">{{ __('customers.open_sales.age') }}</th>
                                <th class="num">{{ __('customers.open_sales.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($openSales as $sale)
                                {{-- Same aging convention as AgedReceivablesQuery: Carbon 3's
                                     diffInDays is signed, so the older date must be the receiver
                                     or every age reads negative and the thresholds below never
                                     fire. Future-dated sales (clock skew) clamp to 0. --}}
                                @php $age = (int) max(0, \Carbon\CarbonImmutable::parse($sale->sale_date)->startOfDay()->diffInDays($asOf)); @endphp
                                <tr class="prod-row"
                                    @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.sales.show', $sale)) }}">
                                    <td class="mono">{{ $sale->number }}</td>
                                    <td>{{ format_date($sale->sale_date) }}</td>
                                    <td class="num tnum
                                        @if ($age > 90) text-rose-700 dark:text-rose-500 font-semibold
                                        @elseif ($age > 60) text-rose-600 dark:text-rose-400
                                        @elseif ($age > 30) text-amber-600 dark:text-amber-400
                                        @else fg-tertiary
                                        @endif">
                                        {{ $age }}d
                                    </td>
                                    <td class="num tnum font-semibold">{{ format_money($sale->balance_due) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($customer->notes)
            <div class="card">
                <div class="card-header"><div>
                    <div class="card-title">{{ __('customers.sections.notes') }}</div>
                    <div class="card-title-sub">{{ __('customers.sections.notes_sub') }}</div>
                </div></div>
                <div class="card-body">
                    <p class="text-sm whitespace-pre-wrap">{{ $customer->notes }}</p>
                </div>
            </div>
        @endif
    </div>
</div>
