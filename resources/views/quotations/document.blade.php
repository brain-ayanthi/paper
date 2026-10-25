@php
    $customer = $quotation->customer_snapshot ?? [];
    $customerName = $customer['business_name'] ?? $customer['name'] ?? __('quotations.walk_in');
    $formatAddress = function (?array $address): string {
        if (!$address) return '';
        if (isset($address['formatted'])) return (string) $address['formatted'];
        return implode("\n", array_filter([
            $address['line1'] ?? null, $address['line2'] ?? null,
            trim(implode(', ', array_filter([$address['city'] ?? null, $address['state'] ?? null]))),
            trim(implode(' ', array_filter([$address['postal_code'] ?? null, $address['country_code'] ?? null]))),
            $address['landmark'] ?? null,
        ]));
    };
    $status = $quotation->effectiveStatus();
    $billingAddress = $formatAddress($quotation->billing_address_snapshot);
    $shippingAddress = $formatAddress($quotation->shipping_address_snapshot);
    $formatQuantity = function ($value): string {
        $quantity = (string) $value;
        return str_contains($quantity, '.') ? (rtrim(rtrim($quantity, '0'), '.') ?: '0') : $quantity;
    };
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar','he','ur','fa']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('quotations.document.title') }} {{ $quotation->displayNumber() }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f4f4f5;color:#18181b;font:14px/1.5 Arial,sans-serif}.page{width:210mm;min-height:297mm;margin:18px auto;background:#fff;padding:18mm;box-shadow:0 1px 8px #0002}.toolbar{width:210mm;margin:18px auto 0;display:flex;justify-content:flex-end;gap:8px}.btn{border:1px solid #d4d4d8;background:#fff;border-radius:6px;padding:8px 14px;cursor:pointer;color:#18181b;text-decoration:none}.btn-primary{background:#18181b;color:#fff;border-color:#18181b}.header{display:flex;justify-content:space-between;gap:30px;border-bottom:2px solid #18181b;padding-bottom:18px}.brand{display:flex;gap:12px;align-items:flex-start}.logo{max-width:65mm;max-height:22mm}.company-name{font-size:22px;font-weight:700}.muted{color:#71717a}.doc-title{text-align:right}.doc-title h1{margin:0;font-size:26px;letter-spacing:.04em}.number{font-family:monospace;font-size:13px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin:24px 0}.label{text-transform:uppercase;letter-spacing:.08em;color:#71717a;font-size:10px;font-weight:700;margin-bottom:5px}.pre{white-space:pre-line}.meta{display:grid;grid-template-columns:auto auto;gap:5px 18px;justify-content:end}.meta div:nth-child(odd){color:#71717a}table{width:100%;border-collapse:collapse;margin-top:18px}th{background:#f4f4f5;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;padding:9px;border-bottom:1px solid #d4d4d8}td{padding:10px 9px;border-bottom:1px solid #e4e4e7;vertical-align:top}.num{text-align:right;font-variant-numeric:tabular-nums}.sku{font:11px monospace;color:#71717a}.totals{width:80mm;margin:18px 0 0 auto}.totals div{display:flex;justify-content:space-between;padding:4px 0}.totals .grand{font-size:18px;font-weight:700;border-top:2px solid #18181b;margin-top:6px;padding-top:9px}.notes{display:grid;grid-template-columns:1fr 1fr;gap:25px;margin-top:30px}.notice{margin-top:30px;padding:10px 12px;background:#f4f4f5;border-left:3px solid #71717a;font-size:12px}.status{display:inline-block;margin-top:8px;padding:3px 8px;border-radius:999px;background:#f4f4f5;font-size:11px;font-weight:700;text-transform:uppercase}.response{margin-top:25px;border-top:1px solid #e4e4e7;padding-top:18px;text-align:center}.flash{width:210mm;margin:12px auto;padding:10px 14px;border-radius:6px;background:#dcfce7;color:#166534}.flash.error{background:#fee2e2;color:#991b1b}@media print{body{background:#fff}.toolbar,.flash,.response{display:none}.page{margin:0;width:auto;min-height:auto;box-shadow:none;padding:12mm}@page{size:A4;margin:0}}
    </style>
</head>
<body>
    @if(session('success'))<div class="flash">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash error">{{ session('error') }}</div>@endif
    <div class="toolbar">
        @unless($public)<a class="btn" href="{{ route('admin.quotations.show', $quotation) }}">{{ __('quotations.document.back') }}</a>@endunless
        <button class="btn btn-primary" onclick="window.print()">{{ __('quotations.actions.print_pdf') }}</button>
    </div>
    <main class="page">
        <header class="header">
            <div class="brand">
                @if($company->logo_url)<img class="logo" src="{{ $company->logo_url }}" alt="">@endif
                <div><div class="company-name">{{ $company->legal_name ?: $company->name ?: config('app.name') }}</div>
                    <div class="muted pre">{{ implode("\n", array_filter([$company->address_line1, $company->address_line2, trim(implode(', ', array_filter([$company->city, $company->state, $company->postal_code]))), $company->phone, $company->email])) }}</div>
                    @if($company->tax_registration_number)<div class="muted">{{ __('quotations.fields.tax_registration') }}: {{ $company->tax_registration_number }}</div>@endif
                </div>
            </div>
            <div class="doc-title"><h1>{{ __('quotations.document.heading') }}</h1><div class="number">{{ $quotation->displayNumber() }}</div><span class="status">{{ __('quotations.statuses.'.$status) }}</span></div>
        </header>

        <section class="grid">
            <div><div class="label">{{ __('quotations.document.prepared_for') }}</div><strong>{{ $customerName }}</strong>
                @if(($customer['business_name'] ?? null) && ($customer['name'] ?? null))<div>{{ $customer['name'] }}</div>@endif
                @if($customer['email'] ?? null)<div class="muted">{{ demo_mask_email($customer['email']) }}</div>@endif
                @if($customer['phone'] ?? null)<div class="muted">{{ \App\Support\PhoneFormatter::pretty($customer['phone']) }}</div>@endif
                @if($customer['gstin'] ?? null)<div>{{ __('quotations.fields.gstin') }}: {{ $customer['gstin'] }}</div>@endif
                @if($billingAddress)<div class="pre muted" style="margin-top:7px"><span class="label">{{ __('quotations.fields.billing_address') }}</span><br>{{ $billingAddress }}</div>@endif
                @if($shippingAddress)<div class="pre muted" style="margin-top:7px"><span class="label">{{ __('quotations.fields.shipping_address') }}</span><br>{{ $shippingAddress }}</div>@endif
            </div>
            <div class="meta">
                <div>{{ __('quotations.fields.issue_date') }}</div><div>{{ format_date($quotation->issue_date) }}</div>
                <div>{{ __('quotations.fields.valid_until') }}</div><div>{{ $quotation->valid_until ? format_date($quotation->valid_until) : '—' }}</div>
                <div>{{ __('quotations.fields.expected_delivery') }}</div><div>{{ $quotation->expected_delivery_date ? format_date($quotation->expected_delivery_date) : '—' }}</div>
                <div>{{ __('quotations.columns.store') }}</div><div>{{ $quotation->store?->name }}</div>
            </div>
        </section>

        <table><thead><tr><th>{{ __('quotations.columns.product') }}</th><th class="num">{{ __('quotations.columns.quantity') }}</th><th class="num">{{ __('quotations.columns.unit_price') }}</th><th class="num">{{ __('quotations.columns.discount') }}</th><th class="num">{{ __('quotations.columns.tax') }}</th><th class="num">{{ __('quotations.columns.line_total') }}</th></tr></thead>
        <tbody>@foreach($quotation->items as $item)<tr>
            <td><strong>{{ $item->product_name_snapshot }}</strong>@if($item->variant_name_snapshot)<div class="muted">{{ $item->variant_name_snapshot }}</div>@endif @if($item->sku_snapshot)<div class="sku">{{ $item->sku_snapshot }}</div>@endif @if($item->notes)<div class="muted">{{ $item->notes }}</div>@endif</td>
            <td class="num">{{ $formatQuantity($item->quantity) }} {{ $item->unit }}</td><td class="num">{{ format_money($item->unit_price, null, $quotation->currency_code) }}</td><td class="num">{{ format_money($item->discount_amount, null, $quotation->currency_code) }}</td><td class="num">{{ format_money($item->tax_amount, null, $quotation->currency_code) }}</td><td class="num"><strong>{{ format_money($item->line_total, null, $quotation->currency_code) }}</strong></td>
        </tr>@endforeach</tbody></table>

        <div class="totals"><div><span>{{ __('quotations.totals.subtotal') }}</span><span>{{ format_money($quotation->subtotal, null, $quotation->currency_code) }}</span></div><div><span>{{ __('quotations.totals.discount') }}</span><span>{{ format_money($quotation->discount_total, null, $quotation->currency_code) }}</span></div><div><span>{{ __('quotations.totals.tax') }}</span><span>{{ format_money($quotation->tax_total, null, $quotation->currency_code) }}</span></div><div class="grand"><span>{{ __('quotations.totals.total') }}</span><span>{{ format_money($quotation->grand_total, null, $quotation->currency_code) }}</span></div></div>

        @if($quotation->terms || $quotation->notes)<section class="notes">@if($quotation->terms)<div><div class="label">{{ __('quotations.fields.terms') }}</div><div class="pre">{{ $quotation->terms }}</div></div>@endif @if($quotation->notes)<div><div class="label">{{ __('quotations.fields.customer_notes') }}</div><div class="pre">{{ $quotation->notes }}</div></div>@endif</section>@endif
        <div class="notice"><strong>{{ __('quotations.document.not_invoice') }}</strong><br>{{ __('quotations.document.stock_notice') }}</div>

        @if($public && $quotation->status === App\Models\Quotation::STATUS_SENT && !$quotation->isExpired())
            <section class="response"><p>{{ __('quotations.public.response_prompt') }}</p><div style="display:flex;justify-content:center;gap:10px">
                <form method="POST" action="{{ route('quotation.public.accept', $token) }}">@csrf<button class="btn btn-primary">{{ __('quotations.public.accept') }}</button></form>
                <form method="POST" action="{{ route('quotation.public.decline', $token) }}">@csrf<button class="btn">{{ __('quotations.public.decline') }}</button></form>
            </div></section>
        @elseif($public && in_array($quotation->status, [App\Models\Quotation::STATUS_ACCEPTED, App\Models\Quotation::STATUS_DECLINED], true))
            <section class="response"><strong>{{ __('quotations.public.response_recorded', ['status' => __('quotations.statuses.'.$quotation->status)]) }}</strong></section>
        @endif
    </main>
</body></html>
