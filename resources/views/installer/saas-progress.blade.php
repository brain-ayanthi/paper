@extends('installer.layout')

@section('content')
    @php
        $progressOptions = [
            'url' => route('install.saas.progress.advance'),
            'csrf' => csrf_token(),
            'unavailable' => __('installer.saas_progress.unavailable'),
            'checking' => __('installer.saas_progress.checking'),
            'complete' => __('installer.saas_progress.complete'),
            'healthHelp' => __('installer.saas_progress.health_help'),
            'contactHost' => __('installer.saas_progress.contact_host'),
            'errors' => __('installer.saas_progress.errors'),
            'steps' => __('installer.saas_progress.steps'),
        ];
    @endphp
    <div x-data="saasPlatformProgress(@js($progressOptions))">
        <h2 class="text-[22px] font-semibold fg-primary tracking-[-0.01em]">{{ __('installer.saas_progress.heading') }}</h2>
        <p class="mt-2 text-[13.5px] fg-secondary leading-relaxed">{{ __('installer.saas_progress.subheading') }}</p>

        <div class="mt-7" role="status" aria-live="polite">
            <div class="flex items-center justify-between gap-4">
                <span class="font-medium fg-primary" x-text="label">{{ __('installer.saas_progress.checking') }}</span>
                <span class="text-[12px] fg-tertiary" x-text="count">0 / 6</span>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                <div class="h-full rounded-full accent-bg transition-all duration-300" style="width: 0%" :style="`width: ${percent}%`"></div>
            </div>
            <p class="mt-4 text-[12.5px] fg-secondary" x-show="message" x-text="message"></p>
            <p class="mt-2 text-[12px] fg-tertiary" x-show="errorCode" x-cloak>
                {{ __('installer.saas_progress.error_code') }}: <code x-text="errorCode"></code>
            </p>
        </div>

        <div class="alert alert-danger mt-6" x-show="needsAttention" x-cloak>
            <div class="alert-body"><div class="alert-msg">{{ __('installer.saas_progress.needs_attention') }}</div></div>
        </div>
        <div class="mt-6" x-show="needsAttention" x-cloak>
            <button type="button" class="pos-btn pos-btn-primary" @click="retry()">
                {{ __('installer.saas_progress.retry') }}
            </button>
        </div>
        <div class="alert alert-success mt-6" x-show="ready" x-cloak>
            <div class="alert-body"><div class="alert-msg">{{ __('installer.saas_progress.ready') }}</div></div>
        </div>
        @if ($platformLoginUrl)
            <div class="mt-6" x-show="ready" x-cloak>
                <a href="{{ $platformLoginUrl }}" class="pos-btn pos-btn-primary">
                    {{ __('installer.saas_progress.open_admin') }}
                </a>
            </div>
        @endif
    </div>

@endsection
