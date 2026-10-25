@extends('installer.layout')

@section('content')
    <h2 class="text-[22px] font-semibold fg-primary tracking-[-0.01em]">{{ __('installer.mode.heading') }}</h2>
    <p class="mt-2 text-[13.5px] fg-secondary leading-relaxed">{{ __('installer.mode.subheading') }}</p>

    <form method="POST" action="{{ route('install.mode.choose') }}" class="mt-8 space-y-6" data-ajax-form novalidate
          x-data="installerForm({ genericError: @js(__('installer.ajax.failed')) })" @submit.prevent="submit($event)">
        @csrf
        @include('installer.partials.ajax-errors')

        <label class="block">
            <span class="field-label">{{ __('installer.mode.label') }}</span>
            <select name="mode" required class="pos-input" x-data="enhancedSelect()">
                <option value="standard" @selected(! request()->session()->get('installer_mode') || request()->session()->get('installer_mode') === 'standard')>{{ __('installer.mode.standard') }}</option>
                <option value="saas" @selected(request()->session()->get('installer_mode') === 'saas')>{{ __('installer.mode.saas') }}</option>
            </select>
        </label>
        <p class="text-[12px] fg-tertiary">{{ __('installer.mode.saas_hint') }}</p>

        <div class="flex justify-end">
            <x-installer.submit-button>{{ __('installer.continue') }}</x-installer.submit-button>
        </div>
    </form>
@endsection
