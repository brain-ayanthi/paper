@extends('installer.layout')

@section('content')
    <h2 class="text-[22px] font-semibold fg-primary tracking-[-0.01em]">{{ __('installer.saas_purchase.heading') }}</h2>
    <p class="mt-2 text-[13.5px] fg-secondary leading-relaxed">{{ __('installer.saas_purchase.subheading') }}</p>

    <form method="POST" action="{{ route('install.saas.purchase.submit') }}" class="mt-8 space-y-6" novalidate
          x-data="installerForm({ genericError: @js(__('installer.ajax.failed')) })" @submit.prevent="submit($event)">
        @csrf
        @include('installer.partials.ajax-errors')
        @if ($testPurchaseBypass)
            <div class="alert alert-warning" role="alert">
                <div class="alert-body">
                    <div class="alert-title">{{ __('installer.saas_purchase.test_bypass_title') }}</div>
                    <div class="alert-msg">{{ __('installer.saas_purchase.test_bypass_notice') }}</div>
                </div>
            </div>
            <input type="hidden" name="saas_purchase_code" value="">
        @else
            <label class="block">
                <span class="field-label">{{ __('installer.saas_purchase.code_label') }}</span>
                <input type="text" name="saas_purchase_code" autocomplete="off" required maxlength="128"
                       class="pos-input mono">
                <p class="mt-2 text-[12px] fg-tertiary">{{ __('installer.saas_purchase.code_hint') }}</p>
            </label>
        @endif
        <div class="flex justify-end">
            <x-installer.submit-button :busy-label="__('installer.saas_purchase.verifying')">
                {{ __($testPurchaseBypass ? 'installer.saas_purchase.test_bypass_continue' : 'installer.saas_purchase.continue') }}
            </x-installer.submit-button>
        </div>
    </form>
@endsection
