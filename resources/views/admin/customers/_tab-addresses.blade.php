{{-- Addresses tab. Read-only here — the address editor lives in the
     customer form (add/remove rows), so there's one place that writes
     addresses rather than two that can drift. --}}
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">{{ __('customers.sections.addresses') }}</div>
            <div class="card-title-sub">{{ __('customers.sections.addresses_sub') }}</div>
        </div>
        @can('update', $customer)
            <a href="{{ route('admin.customers.edit', $customer) }}" class="pos-btn pos-btn-xs pos-btn-ghost">
                <x-icon name="edit" class="w-3.5 h-3.5" />
                {{ __('customers.actions.edit') }}
            </a>
        @endcan
    </div>
    <div class="card-body">
        @if ($customer->addresses->isEmpty())
            <div class="dt-empty">
                <span class="dt-empty-icon"><x-icon name="store" class="w-5 h-5" /></span>
                <div class="dt-empty-title">{{ __('customers.sections.addresses') }}</div>
                <div class="dt-empty-sub">{{ __('customers.sections.addresses_sub') }}</div>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach ($customer->addresses as $address)
                    <div class="cust-address">
                        <div class="flex items-center gap-2 mb-1">
                            <strong class="text-sm">{{ $address->label ?: __('customers.address.label') }}</strong>
                            @if ($address->is_default)
                                <span class="prod-badge prod-badge-positive">{{ __('customers.address.is_default') }}</span>
                            @endif
                        </div>
                        <div class="text-sm fg-secondary">
                            {{ collect([
                                $address->line1,
                                $address->line2,
                                $address->landmark,
                                $address->city,
                                $address->state,
                                $address->postal_code,
                                $address->country_code,
                            ])->filter()->implode(', ') ?: '—' }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
