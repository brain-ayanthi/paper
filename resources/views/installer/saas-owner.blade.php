@extends('installer.layout')

@section('content')
    <h2 class="text-[22px] font-semibold fg-primary tracking-[-0.01em]">{{ __('installer.saas_owner.heading') }}</h2>
    <p class="mt-2 text-[13.5px] fg-secondary leading-relaxed">{{ __('installer.saas_owner.subheading') }}</p>

    @if ($ownerVerified)
        <div class="alert alert-success mt-6" role="status">
            <div class="alert-body"><div class="alert-msg">{{ __('installer.saas_owner.verified') }}</div></div>
        </div>
        <form method="POST" action="{{ route('install.saas.owner.handoff') }}" class="mt-8" novalidate
              x-data="installerForm({ genericError: @js(__('installer.ajax.failed')) })" @submit.prevent="submit($event)">
            @csrf
            @include('installer.partials.ajax-errors')
            <div class="flex justify-end">
                <x-installer.submit-button :busy-label="__('installer.saas_owner.continuing')">
                    {{ __('installer.saas_owner.continue') }}
                </x-installer.submit-button>
            </div>
        </form>
    @else
        @if (session('installer_saas_owner_email_sent'))
            <div class="alert alert-info mt-6" role="status">
                <div class="alert-body"><div class="alert-msg">{{ __('installer.saas_owner.sent') }}</div></div>
            </div>
        @endif

        <form method="POST" action="{{ route('install.saas.owner.request') }}" class="mt-8 space-y-6" novalidate
              x-data="installerForm({ genericError: @js(__('installer.ajax.failed')) })" @submit.prevent="submit($event)">
            @csrf
            @include('installer.partials.ajax-errors')
            <label class="block">
                <span class="field-label">{{ __('installer.saas_owner.email_label') }}</span>
                <input type="email" name="saas_owner_email" autocomplete="email" maxlength="254" class="pos-input">
                <p class="mt-2 text-[12px] fg-tertiary">{{ __('installer.saas_owner.email_hint') }}</p>
            </label>
            <div class="flex justify-end">
                <x-installer.submit-button :busy-label="__('installer.saas_owner.sending')">
                    {{ __('installer.saas_owner.request') }}
                </x-installer.submit-button>
            </div>
        </form>

        <form method="POST" action="{{ route('install.saas.owner.confirm') }}" class="mt-8 space-y-6" novalidate
              x-data="installerForm({ genericError: @js(__('installer.ajax.failed')) })" @submit.prevent="submit($event)">
            @csrf
            @include('installer.partials.ajax-errors')
            <label class="block">
                <span class="field-label">{{ __('installer.saas_owner.token_label') }}</span>
                <input type="text" name="saas_owner_token" autocomplete="one-time-code" maxlength="64" class="pos-input mono">
                <p class="mt-2 text-[12px] fg-tertiary">{{ __('installer.saas_owner.token_hint') }}</p>
            </label>
            <div class="flex justify-end">
                <x-installer.submit-button :busy-label="__('installer.saas_owner.confirming')">
                    {{ __('installer.saas_owner.confirm') }}
                </x-installer.submit-button>
            </div>
        </form>
    @endif
@endsection
