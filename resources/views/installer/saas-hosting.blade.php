@extends('installer.layout')

@section('content')
    @php
        $recommended = $capabilities['recommended_mode'] ?? null;
        $current = old('hosting_mode', $selectedMode ?? $recommended ?? 'shared_manual');
    @endphp

    <h2 class="text-[22px] font-semibold fg-primary tracking-[-0.01em]">{{ __('installer.hosting.heading') }}</h2>
    <p class="mt-2 text-[13.5px] fg-secondary leading-relaxed">{{ __('installer.hosting.subheading') }}</p>

    @if (($capabilities['status'] ?? '') === 'blocked')
        <div class="alert alert-warning mt-6">
            <span class="alert-icon" aria-hidden="true">!</span>
            <div class="alert-body">
                <div class="alert-title">{{ __('installer.hosting.blocked_title') }}</div>
                <div class="alert-msg">{{ __('installer.hosting.blocked_message') }}</div>
            </div>
        </div>
    @elseif (($capabilities['status'] ?? '') === 'awaiting_configuration')
        <div class="alert alert-info mt-6">
            <span class="alert-icon" aria-hidden="true">i</span>
            <div class="alert-body">
                <div class="alert-title">{{ __('installer.hosting.pending_title') }}</div>
                <div class="alert-msg">{{ __('installer.hosting.pending_message') }}</div>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('install.saas.hosting.choose') }}" class="mt-7 space-y-6"
          data-ajax-form novalidate
          x-data="installerForm({ genericError: @js(__('installer.ajax.failed')) })"
          @submit.prevent="submit($event)">
        @csrf
        @include('installer.partials.ajax-errors')

        <fieldset class="space-y-3" :disabled="submitting">
            <legend class="field-label mb-3">{{ __('installer.hosting.label') }}</legend>

            @foreach (['shared_manual', 'vps_automatic'] as $mode)
                <label class="block cursor-pointer rounded-[10px] border border-[var(--border-subtle)] p-4 transition hover:border-[var(--accent)] has-[:checked]:border-[var(--accent)] has-[:checked]:bg-[var(--accent-soft)]">
                    <span class="flex items-start gap-3">
                        <input type="radio" name="hosting_mode" value="{{ $mode }}"
                               class="mt-1 accent-[var(--accent)]" required @checked($current === $mode)>
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold fg-primary">{{ __('installer.hosting.'.$mode.'.title') }}</span>
                                @if ($recommended === $mode)
                                    <span class="rounded-full bg-[var(--accent-soft)] px-2 py-0.5 text-[10px] font-semibold accent">{{ __('installer.hosting.recommended') }}</span>
                                @endif
                                @if ($selectedMode === $mode)
                                    <span class="rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-[10px] font-semibold fg-secondary">{{ __('installer.hosting.selected') }}</span>
                                @endif
                            </span>
                            <span class="mt-1 block text-[12.5px] fg-secondary leading-relaxed">{{ __('installer.hosting.'.$mode.'.description') }}</span>
                        </span>
                    </span>
                </label>
            @endforeach
        </fieldset>

        <div class="alert alert-warning">
            <span class="alert-icon" aria-hidden="true">!</span>
            <div class="alert-body">
                <div class="alert-title">{{ __('installer.hosting.shared_warning_title') }}</div>
                <div class="alert-msg">{{ __('installer.hosting.shared_warning') }}</div>
            </div>
        </div>

        @if ($selectedMode && ! $runtimeReady)
            <div class="alert alert-info">
                <span class="alert-icon" aria-hidden="true">i</span>
                <div class="alert-body">
                    <div class="alert-title">{{ __('installer.hosting.saved_pending_title') }}</div>
                    <div class="alert-msg">{{ __('installer.hosting.saved_pending') }}</div>
                </div>
            </div>
        @endif

        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('install.mode') }}" class="pos-btn pos-btn-ghost">{{ __('installer.back') }}</a>
            <x-installer.submit-button>{{ __('installer.hosting.continue') }}</x-installer.submit-button>
        </div>
    </form>
@endsection
