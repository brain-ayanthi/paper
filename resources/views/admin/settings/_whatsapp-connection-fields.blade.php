@php($settings = $connection?->settings ?? [])
<div x-data="{ provider: @js(old('provider', $connection?->provider ?: 'meta')), mode: @js(old('mode', data_get($settings, 'mode', 'automatic'))) }" class="contents">
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <label class="field"><span class="field-label is-required">{{ __('whatsapp.fields.name') }}</span><input name="name" class="pos-input" required value="{{ old('name', $connection?->name) }}"></label>
    <label class="field"><span class="field-label is-required">{{ __('whatsapp.fields.provider') }}</span><select name="provider" class="pos-input" x-data="enhancedSelect()" x-model="provider" required>@foreach($providers as $code => $provider)<option value="{{ $code }}">{{ $provider->label() }}</option>@endforeach</select></label>
    <label class="field"><span class="field-label">{{ __('whatsapp.fields.store') }}</span><select name="store_id" class="pos-input" x-data="enhancedSelect()"><option value="">{{ __('whatsapp.settings.company_default') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string) old('store_id', $connection?->store_id) === (string) $store->id)>{{ $store->name }}</option>@endforeach</select></label>
    <label class="field"><span class="field-label is-required">{{ __('whatsapp.fields.mode') }}</span><select name="mode" class="pos-input" x-data="enhancedSelect()" x-model="mode"><option value="automatic">{{ __('whatsapp.modes.automatic') }}</option><option value="click_to_chat">{{ __('whatsapp.modes.click_to_chat') }}</option></select></label>
    <label class="field"><span class="field-label">{{ __('whatsapp.fields.sender_phone') }}</span><input name="sender_phone" class="pos-input" value="{{ old('sender_phone', $connection?->sender_phone) }}"></label>
    <label class="field"><span class="field-label">{{ __('whatsapp.fields.graph_version') }}</span><input name="graph_version" class="pos-input" value="{{ data_get($settings, 'graph_version', config('services.whatsapp.meta_graph_version')) }}"></label>
    <label class="field"><span class="field-label">{{ __('whatsapp.fields.retention_days') }}</span><input type="number" min="30" max="3650" name="retention_days" class="pos-input" value="{{ data_get($settings, 'retention_days', config('services.whatsapp.retention_days')) }}"></label>
</div>
@foreach($providers as $providerCode => $provider)
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4" x-show="provider === @js($providerCode)" x-cloak>
        @foreach($provider->settingsSchema() as $key => $field)
            <label class="field">
                <span class="field-label" @if($field['required']) :class="{ 'is-required': mode === 'automatic' }" @endif>{{ $field['label'] }}</span>
                @if($field['type'] === 'password')
                    <div class="pos-input-wrap has-suffix" x-data="{ shown: false }">
                        <input
                            x-bind:type="shown ? 'text' : 'password'"
                            name="credentials[{{ $key }}]"
                            class="pos-input"
                            x-bind:disabled="provider !== @js($providerCode)"
                            @if($field['required']) :required="mode === 'automatic' && provider === @js($providerCode) && @js(! $connection)" @endif
                            value=""
                            placeholder="{{ $connection ? __('whatsapp.values.keep_secret') : '' }}"
                            autocomplete="new-password"
                        >
                        <button
                            type="button"
                            class="pos-input-suffix"
                            tabindex="-1"
                            @click="shown = !shown"
                            :aria-label="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))"
                            :title="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))"
                        >
                            <span x-show="!shown"><x-icon name="eye" class="w-[18px] h-[18px]" /></span>
                            <span x-show="shown" x-cloak><x-icon name="eye-off" class="w-[18px] h-[18px]" /></span>
                        </button>
                    </div>
                @else
                    <input
                        name="credentials[{{ $key }}]"
                        type="{{ $field['type'] }}"
                        class="pos-input"
                        :disabled="provider !== @js($providerCode)"
                        @if($field['required']) :required="mode === 'automatic' && provider === @js($providerCode) && @js(! $connection)" @endif
                        value="{{ old('credentials.'.$key, data_get($connection?->credentials ?? [], $key)) }}"
                    >
                @endif
            </label>
        @endforeach
    </div>
@endforeach
<label class="field-toggle"><input type="hidden" name="auto_send_receipts" value="0"><input type="checkbox" name="auto_send_receipts" value="1" @checked(data_get($settings, 'auto_send_receipts', false))><span>{{ __('whatsapp.fields.auto_send_receipts') }}</span></label>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @foreach(['sale_receipt','quotation','credit_note','customer_statement','customer_payment','supplier_payment'] as $type)
        <div class="field"><span class="field-label">{{ __('whatsapp.document_types.'.$type) }}</span><div class="grid grid-cols-2 gap-2"><input name="templates[{{ $type }}][name]" class="pos-input" value="{{ data_get($settings, 'templates.'.$type.'.name') }}" placeholder="{{ __('whatsapp.fields.meta_template') }}"><input name="templates[{{ $type }}][language]" class="pos-input" value="{{ data_get($settings, 'templates.'.$type.'.language', current_store()?->locale ?: 'en') }}" placeholder="en"></div></div>
    @endforeach
</div>
</div>
