{{-- Overview tab — who this supplier is, plus anything demanding
     payment (open purchases) at the top of the right column. The
     address sits here rather than in its own tab: suppliers store a
     flat address on the row, not a `customer_addresses`-style table. --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
    {{-- ── LEFT column: Profile + Tax ────────────────────────────── --}}
    <div class="space-y-5">
        <div class="card">
            <div class="card-header"><div>
                <div class="card-title">{{ __('suppliers.sections.profile') }}</div>
            </div></div>
            <div class="card-body">
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="fg-tertiary">{{ __('suppliers.fields.business_name') }}</dt><dd>{{ $supplier->business_name ?: '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('suppliers.fields.contact_person') }}</dt><dd>{{ $supplier->contact_person ?: '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('suppliers.fields.phone') }}</dt><dd class="mono">{{ $supplier->phone ? \App\Support\PhoneFormatter::pretty($supplier->phone) : '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('suppliers.fields.email') }}</dt><dd>{{ $supplier->email ? $supplier->display_email : '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('suppliers.fields.default_currency') }}</dt><dd class="mono">{{ $supplier->default_currency_code ?: '—' }}</dd></div>
                    <div><dt class="fg-tertiary">{{ __('suppliers.fields.payment_terms_days') }}</dt><dd>{{ $supplier->payment_terms_days !== null ? $supplier->payment_terms_days.' '.__('suppliers.fields.days') : '—' }}</dd></div>
                </dl>
            </div>
        </div>

        @if ($supplier->gstin || $supplier->pan || $supplier->tax_registration_number)
            <div class="card">
                <div class="card-header"><div>
                    <div class="card-title">{{ __('suppliers.sections.tax') }}</div>
                </div></div>
                <div class="card-body">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                        <div><dt class="fg-tertiary">{{ __('suppliers.fields.gstin') }}</dt><dd class="mono">{{ $supplier->gstin ?: '—' }}</dd></div>
                        <div><dt class="fg-tertiary">{{ __('suppliers.fields.pan') }}</dt><dd class="mono">{{ $supplier->pan ?: '—' }}</dd></div>
                        <div class="col-span-2"><dt class="fg-tertiary">{{ __('suppliers.fields.tax_reg') }}</dt><dd class="mono">{{ $supplier->tax_registration_number ?: '—' }}</dd></div>
                    </dl>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header"><div>
                <div class="card-title">{{ __('suppliers.sections.address') }}</div>
            </div></div>
            <div class="card-body">
                @php
                    $addr = collect([
                        $supplier->address_line1, $supplier->address_line2,
                        $supplier->city, $supplier->state, $supplier->postal_code, $supplier->country_code,
                    ])->filter()->implode(', ');
                @endphp
                <p class="text-sm {{ $addr === '' ? 'fg-tertiary' : '' }}">{{ $addr ?: '—' }}</p>
            </div>
        </div>
    </div>

    {{-- ── RIGHT column: Open purchases + Notes ──────────────────── --}}
    <div class="space-y-5">
        @if ($openPurchases->isNotEmpty())
            @php $asOf = \Carbon\CarbonImmutable::today(); @endphp
            <div class="card card-pad-0">
                <div class="card-header"><div>
                    <div class="card-title">{{ __('suppliers.open_purchases.title') }}</div>
                    <div class="card-title-sub">{{ __('suppliers.open_purchases.sub', ['count' => $openPurchases->count()]) }}</div>
                </div></div>
                <div class="dt-scroll">
                    <table class="dt-table">
                        <thead>
                            <tr>
                                <th>{{ __('suppliers.open_purchases.purchase') }}</th>
                                <th>{{ __('suppliers.open_purchases.due') }}</th>
                                <th class="num">{{ __('suppliers.open_purchases.age') }}</th>
                                <th class="num">{{ __('suppliers.open_purchases.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($openPurchases as $purchase)
                                {{-- Age counts from the DUE date when the PO has terms,
                                     otherwise from the purchase date — an invoice on net-30
                                     isn't overdue on day one. Same signed-diff convention as
                                     AgedReceivablesQuery: older date is the receiver, or the
                                     age reads negative and the thresholds below never fire. --}}
                                @php
                                    $from = \Carbon\CarbonImmutable::parse($purchase->due_date ?: $purchase->purchase_date)->startOfDay();
                                    $age  = (int) max(0, $from->diffInDays($asOf));
                                @endphp
                                <tr class="prod-row"
                                    @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.purchases.show', $purchase)) }}">
                                    <td class="mono">{{ $purchase->number }}</td>
                                    <td>{{ $purchase->due_date ? format_date($purchase->due_date) : format_date($purchase->purchase_date) }}</td>
                                    <td class="num tnum
                                        @if ($age > 90) text-rose-700 dark:text-rose-500 font-semibold
                                        @elseif ($age > 60) text-rose-600 dark:text-rose-400
                                        @elseif ($age > 30) text-amber-600 dark:text-amber-400
                                        @else fg-tertiary
                                        @endif">
                                        {{ $age }}d
                                    </td>
                                    <td class="num tnum font-semibold">{{ format_money($purchase->balance_due) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($supplier->notes)
            <div class="card">
                <div class="card-header"><div>
                    <div class="card-title">{{ __('suppliers.sections.notes') }}</div>
                    <div class="card-title-sub">{{ __('suppliers.sections.notes_sub') }}</div>
                </div></div>
                <div class="card-body">
                    <p class="text-sm whitespace-pre-wrap">{{ $supplier->notes }}</p>
                </div>
            </div>
        @endif
    </div>
</div>
