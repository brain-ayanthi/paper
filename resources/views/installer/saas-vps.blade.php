@extends('installer.layout')

@section('content')
    <h2 class="text-[22px] font-semibold fg-primary tracking-[-0.01em]">{{ __('installer.vps.heading') }}</h2>
    <p class="mt-2 text-[13.5px] fg-secondary leading-relaxed">{{ __('installer.vps.subheading') }}</p>

    <div class="alert alert-info mt-6">
        <span class="alert-icon" aria-hidden="true">i</span>
        <div class="alert-body"><div class="alert-title">{{ __('installer.vps.command_title') }}</div>
            <div class="alert-msg">{{ __('installer.vps.command_help') }}</div></div>
    </div>

    @if (($setup['configured'] ?? false) && $runtimeReady)
        <div class="alert alert-success mt-6"><span class="alert-icon" aria-hidden="true">✓</span>
            <div class="alert-body"><div class="alert-title">{{ __('installer.vps.ready_title') }}</div>
                <div class="alert-msg">{{ __('installer.vps.ready_message') }}</div></div></div>
        <div class="mt-7 flex items-center justify-between gap-3">
            <a href="{{ route('install.saas.hosting') }}" class="pos-btn pos-btn-ghost">{{ __('installer.back') }}</a>
            <a href="{{ route('install.requirements') }}" class="pos-btn pos-btn-primary">{{ __('installer.continue') }}</a>
        </div>
    @else
        <form method="POST" action="{{ route('install.saas.vps.store') }}" class="mt-7 space-y-7"
              data-ajax-form novalidate x-data="installerForm({ genericError: @js(__('installer.ajax.failed')) })"
              @submit.prevent="submit($event)">
            @csrf
            @include('installer.partials.ajax-errors')
            <fieldset class="space-y-5" :disabled="submitting">
                <div><h3 class="text-[14px] font-semibold fg-primary">{{ __('installer.vps.domains_title') }}</h3>
                    <p class="mt-1 text-[12px] fg-secondary">{{ __('installer.vps.domains_help') }}</p></div>
                @foreach (['platform_domain', 'workspace_domain', 'platform_database'] as $field)
                    <label class="block"><span class="field-label">{{ __('installer.vps.'.$field) }}</span>
                        <input type="text" name="{{ $field }}" value="{{ old($field, $setup[$field] ?? '') }}"
                               class="pos-input" maxlength="{{ $field === 'platform_database' ? 64 : 253 }}" required autocomplete="off"></label>
                @endforeach
                <div class="border-t border-[var(--border-subtle)] pt-6">
                    <h3 class="text-[14px] font-semibold fg-primary">{{ __('installer.vps.mail_title') }}</h3>
                    <p class="mt-1 text-[12px] fg-secondary">{{ __('installer.vps.mail_help') }}</p></div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="block sm:col-span-2"><span class="field-label">{{ __('installer.vps.mail_host') }}</span>
                        <input type="text" name="mail_host" value="{{ old('mail_host') }}" class="pos-input" maxlength="253" required></label>
                    <label class="block"><span class="field-label">{{ __('installer.vps.mail_port') }}</span>
                        <input type="number" name="mail_port" value="{{ old('mail_port', 587) }}" class="pos-input" min="1" max="65535" required></label>
                </div>
                <label class="block"><span class="field-label">{{ __('installer.vps.mail_scheme') }}</span>
                    <select name="mail_scheme" class="pos-input" required x-data="enhancedSelect()">
                        <option value="smtp">{{ __('installer.vps.mail_scheme_starttls') }}</option>
                        <option value="smtps">{{ __('installer.vps.mail_scheme_tls') }}</option></select></label>
                <label class="block"><span class="field-label">{{ __('installer.vps.mail_username') }}</span>
                    <input type="text" name="mail_username" value="{{ old('mail_username') }}" class="pos-input" maxlength="255"></label>
                <label class="block"><span class="field-label">{{ __('installer.vps.mail_password') }}</span>
                    <div class="pos-input-wrap has-suffix" x-data="{ shown: false }">
                        <input :type="shown ? 'text' : 'password'" name="mail_password" class="pos-input" maxlength="4096" autocomplete="new-password">
                        <button type="button" class="pos-input-suffix" @click="shown = !shown" :aria-pressed="shown"
                                :aria-label="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))">
                            <span x-show="!shown"><x-icon name="eye" class="w-[18px] h-[18px]" /></span>
                            <span x-show="shown" x-cloak><x-icon name="eye-off" class="w-[18px] h-[18px]" /></span></button></div></label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block"><span class="field-label">{{ __('installer.vps.mail_from_address') }}</span>
                        <input type="email" name="mail_from_address" value="{{ old('mail_from_address') }}" class="pos-input" maxlength="254" required></label>
                    <label class="block"><span class="field-label">{{ __('installer.vps.mail_from_name') }}</span>
                        <input type="text" name="mail_from_name" value="{{ old('mail_from_name', config('pos.name')) }}" class="pos-input" maxlength="120" required></label>
                </div>
                <label class="field-toggle"><input type="checkbox" name="automatic_provisioning_acknowledged" value="1" required>
                    <span>{{ __('installer.vps.automatic_ack') }}</span></label>
                <p class="text-[12px] fg-secondary">{{ __('installer.vps.automatic_ack_help') }}</p>
            </fieldset>
            <div class="flex items-center justify-between gap-3">
                <a href="{{ route('install.saas.hosting') }}" class="pos-btn pos-btn-ghost">{{ __('installer.back') }}</a>
                <x-installer.submit-button>{{ __('installer.vps.save') }}</x-installer.submit-button>
            </div>
        </form>
    @endif
@endsection
