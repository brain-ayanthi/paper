@extends('installer.layout')

@section('content')
    <h2 class="text-[22px] font-semibold fg-primary tracking-[-0.01em]">{{ __('installer.saas_admin.heading') }}</h2>
    <p class="mt-2 text-[13.5px] fg-secondary leading-relaxed">{{ __('installer.saas_admin.subheading') }}</p>

    <form method="POST" action="{{ route('install.saas.admin.save') }}" class="mt-8 space-y-8"
          data-ajax-form novalidate
          x-data="installerForm({ genericError: @js(__('installer.ajax.failed')) })"
          @submit.prevent="submit($event)">
        @csrf
        @include('installer.partials.ajax-errors')
        @include('installer.partials.admin-credentials', ['passwordHint' => __('installer.saas_admin.password_hint')])

        <div class="flex justify-between gap-4">
            <a href="{{ route('install.saas.package') }}" class="pos-btn pos-btn-ghost">{{ __('installer.back') }}</a>
            <x-installer.submit-button :busy-label="__('installer.saas_admin.saving')">
                {{ __('installer.continue') }}
            </x-installer.submit-button>
        </div>
    </form>
@endsection
