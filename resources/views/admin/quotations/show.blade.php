@php
    use Illuminate\Support\Js;
    $status = $quotation->effectiveStatus();
    $badge = match ($status) {
        'accepted', 'converted' => 'positive', 'sent' => 'info', 'expired' => 'warning',
        'declined', 'cancelled' => 'negative', default => 'muted',
    };
    $customer = $quotation->customer_snapshot ?? [];
    $customerName = $customer['business_name'] ?? $customer['name'] ?? __('quotations.walk_in');
    $publicUrl = $quotation->sent_at ? $quotation->publicUrl() : null;
    $whatsappPhone = $customer['whatsapp_phone'] ?? $customer['phone'] ?? null;
    $formatQuantity = function ($value): string {
        $quantity = (string) $value;
        return str_contains($quantity, '.') ? (rtrim(rtrim($quantity, '0'), '.') ?: '0') : $quantity;
    };
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
    $billingAddress = $formatAddress($quotation->billing_address_snapshot);
    $shippingAddress = $formatAddress($quotation->shipping_address_snapshot);
@endphp
<x-admin-layout
    active="quotations"
    :title="$quotation->displayNumber()"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('quotations.title'), 'href' => route('admin.quotations.index')],
        ['label' => $quotation->displayNumber()],
    ]">
    <div class="page-wide">
        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.quotations.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost"><x-icon name="back" class="w-4 h-4" /></a>
                <div>
                    <div class="flex items-center gap-2"><h1 class="page-title mono">{{ $quotation->displayNumber() }}</h1><span class="prod-badge prod-badge-{{ $badge }}">{{ __('quotations.statuses.'.$status) }}</span></div>
                    <p class="page-sub">{{ __('quotations.show.sub', ['date' => format_date($quotation->issue_date), 'customer' => $customerName]) }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap justify-end">
                <a href="{{ route('admin.quotations.document', $quotation) }}" target="_blank" class="pos-btn pos-btn-sm pos-btn-ghost"><x-icon name="receipt" class="w-4 h-4" />{{ __('quotations.actions.print') }}</a>
                <a href="{{ route('admin.quotations.pdf', $quotation) }}" class="pos-btn pos-btn-sm pos-btn-ghost"><x-icon name="download" class="w-4 h-4" />{{ __('quotations.actions.download_pdf') }}</a>
                @if($quotation->isEditable())
                    @can('update', $quotation)<a href="{{ route('admin.quotations.edit', $quotation) }}" class="pos-btn pos-btn-sm pos-btn-ghost"><x-icon name="edit" class="w-4 h-4" />{{ __('quotations.actions.edit') }}</a>@endcan
                @endif
                @if($quotation->canSend())
                    @can('send', $quotation)
                        <form method="POST" action="{{ route('admin.quotations.send', $quotation) }}" data-ajax-form>@csrf<button class="pos-btn pos-btn-sm pos-btn-primary"><x-icon name="external" class="w-4 h-4" />{{ $quotation->sent_at ? __('quotations.actions.resend') : __('quotations.actions.send') }}</button></form>
                    @endcan
                @endif
                @if($quotation->canConvert())
                    @can('convert', $quotation)<a href="{{ route('admin.quotations.convert.form', $quotation) }}" class="pos-btn pos-btn-sm pos-btn-primary"><x-icon name="cart" class="w-4 h-4" />{{ __('quotations.actions.convert') }}</a>@endcan
                @endif
            </div>
        </div>

        @if ($quotation->isExpired())<x-alert type="warning" class="mb-5">{{ __('quotations.show.expired_notice') }}</x-alert>@endif
        @if ($latestRevision)
            <x-alert type="info" class="mb-5">
                {{ __('quotations.show.superseded_notice') }}
                <a class="link mono" href="{{ route('admin.quotations.show', $latestRevision) }}">{{ $latestRevision->displayNumber() }}</a>
            </x-alert>
        @endif
        @if ($quotation->convertedSale)<x-alert type="success" class="mb-5">{!! __('quotations.show.converted_notice', ['sale' => '<a class="link mono" href="'.e(route('admin.sales.show', $quotation->convertedSale)).'">'.e($quotation->convertedSale->number).'</a>']) !!}</x-alert>@endif

        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_340px] gap-5 items-start">
            <div class="space-y-5">
                <div class="card">
                    <div class="card-header"><div><div class="card-title">{{ __('quotations.sections.items') }}</div></div></div>
                    <div class="dt-scroll"><table class="dt-table">
                        <thead><tr><th>{{ __('quotations.columns.product') }}</th><th class="num">{{ __('quotations.columns.quantity') }}</th><th class="num">{{ __('quotations.columns.unit_price') }}</th><th class="num">{{ __('quotations.columns.discount') }}</th><th class="num">{{ __('quotations.columns.tax') }}</th><th class="num">{{ __('quotations.columns.line_total') }}</th></tr></thead>
                        <tbody>@foreach ($quotation->items as $item)<tr>
                            <td><div>{{ $item->product_name_snapshot }}</div>@if($item->variant_name_snapshot)<div class="text-xs fg-tertiary">{{ $item->variant_name_snapshot }}</div>@endif @if($item->sku_snapshot)<div class="text-xs fg-tertiary mono">{{ $item->sku_snapshot }}</div>@endif @if($item->notes)<div class="text-xs fg-secondary mt-1">{{ $item->notes }}</div>@endif</td>
                            <td class="num tnum">{{ $formatQuantity($item->quantity) }} {{ $item->unit }}</td>
                            <td class="num tnum">{{ format_money($item->unit_price, null, $quotation->currency_code) }}</td>
                            <td class="num tnum">{{ format_money($item->discount_amount, null, $quotation->currency_code) }}</td>
                            <td class="num tnum">{{ format_money($item->tax_amount, null, $quotation->currency_code) }}</td>
                            <td class="num tnum font-medium">{{ format_money($item->line_total, null, $quotation->currency_code) }}</td>
                        </tr>@endforeach</tbody>
                    </table></div>
                    <div class="card-body border-t border-[var(--color-border)]"><div class="ml-auto max-w-sm space-y-2">
                        <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.totals.subtotal') }}</span><span class="tnum">{{ format_money($quotation->subtotal, null, $quotation->currency_code) }}</span></div>
                        <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.totals.discount') }}</span><span class="tnum">{{ format_money($quotation->discount_total, null, $quotation->currency_code) }}</span></div>
                        <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.totals.tax') }}</span><span class="tnum">{{ format_money($quotation->tax_total, null, $quotation->currency_code) }}</span></div>
                        <div class="flex justify-between border-t border-[var(--color-border)] pt-2 text-lg font-semibold"><span>{{ __('quotations.totals.total') }}</span><span class="tnum">{{ format_money($quotation->grand_total, null, $quotation->currency_code) }}</span></div>
                    </div></div>
                </div>

                @if ($quotation->terms || $quotation->notes || $quotation->internal_notes)
                    <div class="card"><div class="card-header"><div><div class="card-title">{{ __('quotations.sections.notes_terms') }}</div></div></div><div class="card-body grid grid-cols-1 md:grid-cols-2 gap-5">
                        @if($quotation->terms)<div><div class="field-label">{{ __('quotations.fields.terms') }}</div><div class="whitespace-pre-line text-sm">{{ $quotation->terms }}</div></div>@endif
                        @if($quotation->notes)<div><div class="field-label">{{ __('quotations.fields.customer_notes') }}</div><div class="whitespace-pre-line text-sm">{{ $quotation->notes }}</div></div>@endif
                        @if($quotation->internal_notes)<div class="md:col-span-2 p-3 bg-[var(--color-surface-2)] rounded"><div class="field-label">{{ __('quotations.fields.internal_notes') }}</div><div class="whitespace-pre-line text-sm">{{ $quotation->internal_notes }}</div></div>@endif
                    </div></div>
                @endif
            </div>

            <div class="space-y-5">
                <div class="card"><div class="card-header"><div><div class="card-title">{{ __('quotations.sections.customer') }}</div></div></div><div class="card-body space-y-2 text-sm">
                    <div class="font-medium">{{ $customerName }}</div>
                    @if($customer['name'] ?? null)<div>{{ $customer['name'] }}</div>@endif
                    @if($customer['email'] ?? null)<div class="fg-secondary">{{ demo_mask_email($customer['email']) }}</div>@endif
                    @if($customer['phone'] ?? null)<div class="fg-secondary">{{ \App\Support\PhoneFormatter::pretty($customer['phone']) }}</div>@endif
                    @if($customer['gstin'] ?? null)<div><span class="fg-tertiary">{{ __('quotations.fields.gstin') }}:</span> <span class="mono">{{ $customer['gstin'] }}</span></div>@endif
                </div></div>

                @if($billingAddress || $shippingAddress)
                    <div class="card"><div class="card-header"><div><div class="card-title">{{ __('quotations.sections.addresses') }}</div></div></div><div class="card-body space-y-4 text-sm">
                        @if($billingAddress)<div><div class="field-label">{{ __('quotations.fields.billing_address') }}</div><div class="whitespace-pre-line">{{ $billingAddress }}</div></div>@endif
                        @if($shippingAddress)<div><div class="field-label">{{ __('quotations.fields.shipping_address') }}</div><div class="whitespace-pre-line">{{ $shippingAddress }}</div></div>@endif
                    </div></div>
                @endif

                <div class="card"><div class="card-header"><div><div class="card-title">{{ __('quotations.sections.dates') }}</div></div></div><div class="card-body space-y-2 text-sm">
                    <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.fields.issue_date') }}</span><span>{{ format_date($quotation->issue_date) }}</span></div>
                    <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.fields.valid_until') }}</span><span>{{ $quotation->valid_until ? format_date($quotation->valid_until) : '—' }}</span></div>
                    <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.fields.expected_delivery') }}</span><span>{{ $quotation->expected_delivery_date ? format_date($quotation->expected_delivery_date) : '—' }}</span></div>
                    <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.columns.store') }}</span><span>{{ $quotation->store?->name }}</span></div>
                    <div class="flex justify-between"><span class="fg-secondary">{{ __('quotations.columns.owner') }}</span><span>{{ $quotation->creator?->name ?: '—' }}</span></div>
                </div></div>

                @if ($publicUrl)
                    <div class="card"><div class="card-header"><div><div class="card-title">{{ __('quotations.sections.share') }}</div><div class="card-title-sub">{{ __('quotations.sections.share_sub') }}</div></div></div><div class="card-body space-y-3">
                        <div class="flex gap-2"><input value="{{ $publicUrl }}" readonly class="pos-input mono text-xs"><button type="button" class="pos-btn pos-btn-sm pos-btn-ghost" onclick="navigator.clipboard.writeText(@js($publicUrl))"><x-icon name="copy" class="w-4 h-4" />{{ __('quotations.actions.copy_link') }}</button></div>
                        @can('whatsapp.send_receipt')<x-whatsapp-button :url="route('admin.quotations.whatsapp', $quotation)" :phone="$whatsappPhone" class="w-full" />@endcan
                        <div class="text-xs fg-tertiary">{{ trans_choice('quotations.show.views', $quotation->view_count, ['count' => $quotation->view_count]) }}</div>
                    </div></div>
                @endif

                <div class="card"><div class="card-header"><div><div class="card-title">{{ __('quotations.sections.activity') }}</div></div></div><div class="card-body"><div class="space-y-3">
                    @foreach($quotation->activities as $activity)<div class="flex gap-3 text-sm"><span class="mt-1.5 w-2 h-2 rounded-full bg-[var(--color-border-strong)] shrink-0"></span><div><div>{{ __('quotations.activities.'.$activity->type) }}</div><div class="text-xs fg-tertiary">{{ $activity->actor?->name ?: __('quotations.public.customer') }} · {{ format_datetime($activity->created_at) }}</div></div></div>@endforeach
                </div></div></div>

                <div class="flex flex-wrap gap-2">
                    @if($quotation->canSend() && $quotation->status === App\Models\Quotation::STATUS_SENT)
                        @can('send', $quotation)
                            <form method="POST" action="{{ route('admin.quotations.accept', $quotation) }}" data-ajax-form>@csrf<button class="pos-btn pos-btn-sm pos-btn-ghost">{{ __('quotations.actions.mark_accepted') }}</button></form>
                            <form method="POST" action="{{ route('admin.quotations.decline', $quotation) }}" data-ajax-form>@csrf<button class="pos-btn pos-btn-sm pos-btn-ghost">{{ __('quotations.actions.mark_declined') }}</button></form>
                        @endcan
                    @endif
                    @if($quotation->canRevise())
                        @can('revise', $quotation)<form method="POST" action="{{ route('admin.quotations.revise', $quotation) }}" data-ajax-form>@csrf<button class="pos-btn pos-btn-sm pos-btn-ghost">{{ __('quotations.actions.create_revision') }}</button></form>@endcan
                    @endif
                    @if($quotation->canCancel())
                        @can('cancel', $quotation)<button type="button" class="pos-btn pos-btn-sm pos-btn-danger" @click="$store.confirm.show({ title: {{ Js::from(__('quotations.confirm_cancel.title')) }}, message: {{ Js::from(__('quotations.confirm_cancel.message')) }}, intent: 'danger', confirmLabel: {{ Js::from(__('quotations.confirm_cancel.confirm')) }}, cancelLabel: {{ Js::from(__('quotations.confirm_cancel.back')) }}, onConfirm: () => $submitForm({{ Js::from(route('admin.quotations.cancel', $quotation)) }}) })">{{ __('quotations.actions.cancel') }}</button>@endcan
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
