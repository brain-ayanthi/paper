@extends('installer.layout')

@section('content')
    <h2 class="text-[22px] font-semibold fg-primary tracking-[-0.01em]">{{ __('installer.shared_hosting.heading') }}</h2>
    <p class="mt-2 text-[13.5px] fg-secondary leading-relaxed">{{ __('installer.shared_hosting.subheading') }}</p>

    @unless ($directInstall)
    <div class="alert alert-warning mt-6">
        <span class="alert-icon" aria-hidden="true">!</span>
        <div class="alert-body">
            <div class="alert-title">{{ __('installer.hosting.shared_warning_title') }}</div>
            <div class="alert-msg">{{ __('installer.hosting.shared_warning') }}</div>
        </div>
    </div>
    @endunless

    @if (($setup['configured'] ?? false) && $runtimeReady)
        <div class="alert alert-success mt-6">
            <span class="alert-icon" aria-hidden="true">✓</span>
            <div class="alert-body">
                <div class="alert-title">{{ __('installer.shared_hosting.ready_title') }}</div>
                <div class="alert-msg">{{ __('installer.shared_hosting.ready_message') }}</div>
            </div>
        </div>
        <div class="mt-7 flex items-center justify-between gap-3">
            <a href="{{ route('install.saas.hosting') }}" class="pos-btn pos-btn-ghost">{{ __('installer.back') }}</a>
            <a href="{{ route('install.requirements') }}" class="pos-btn pos-btn-primary">{{ __('installer.continue') }}</a>
        </div>
    @elseif (($setup['configured'] ?? false) && ! $runtimeReady)
        <div class="alert alert-warning mt-6" role="status">
            <span class="alert-icon" aria-hidden="true">!</span>
            <div class="alert-body">
                <div class="alert-title">{{ __('installer.shared_hosting.pending_title') }}</div>
                <div class="alert-msg">{{ __('installer.shared_hosting.pending_message') }}</div>
            </div>
        </div>
        <div class="mt-7">
            <a href="{{ route('install.saas.hosting') }}" class="pos-btn pos-btn-ghost">{{ __('installer.back') }}</a>
        </div>
    @else
        <form method="POST" action="{{ route('install.saas.shared-hosting.store') }}"
              class="mt-7 space-y-7" data-ajax-form novalidate
              x-data="installerForm({ genericError: @js(__('installer.ajax.failed')) })"
              @submit.prevent="submit($event)">
            @csrf
            @include('installer.partials.ajax-errors')

            <fieldset class="space-y-5" :disabled="submitting">
                <div>
                    <h3 class="text-[14px] font-semibold fg-primary">{{ __('installer.shared_hosting.domains_title') }}</h3>
                    <p class="mt-1 text-[12px] fg-secondary">{{ __('installer.shared_hosting.domains_help') }}</p>
                </div>
                @foreach (['platform_domain', 'workspace_domain'] as $field)
                    <label class="block">
                        <span class="field-label">{{ __('installer.shared_hosting.'.$field) }}</span>
                        <input type="text" name="{{ $field }}" value="{{ old($field, $setup[$field] ?? '') }}"
                               class="pos-input" maxlength="253" required autocomplete="off"
                               autocapitalize="none" spellcheck="false">
                    </label>
                @endforeach

                <div class="border-t border-[var(--border-subtle)] pt-6">
                    <h3 class="text-[14px] font-semibold fg-primary">{{ __('installer.shared_hosting.platform_root_title') }}</h3>
                    <p class="mt-1 text-[12px] fg-secondary">{{ __('installer.shared_hosting.platform_root_help') }}</p>
                </div>
                <label class="block">
                    <span class="field-label">{{ __('installer.shared_hosting.platform_root') }}</span>
                    <input type="text" name="platform_root" value="{{ old('platform_root', $setup['platform_root'] ?? '') }}"
                           class="pos-input" maxlength="1024" required autocomplete="off"
                           autocapitalize="none" spellcheck="false"
                           placeholder="{{ $platformRootExample }}">
                </label>
                <p class="text-[12px] fg-secondary">{{ __('installer.shared_hosting.platform_root_example', ['path' => $platformRootExample]) }}</p>
                <p class="text-[12px] fg-secondary">{{ __('installer.shared_hosting.platform_public_help') }}</p>

                <div class="border-t border-[var(--border-subtle)] pt-6">
                    <h3 class="text-[14px] font-semibold fg-primary">{{ __('installer.shared_hosting.database_title') }}</h3>
                    <p class="mt-1 text-[12px] fg-secondary">{{ __('installer.shared_hosting.database_help') }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="block sm:col-span-2">
                        <span class="field-label">{{ __('installer.shared_hosting.database_host') }}</span>
                        <input type="text" name="database_host" value="{{ old('database_host', 'localhost') }}"
                               class="pos-input" maxlength="253" required autocomplete="off">
                    </label>
                    <label class="block">
                        <span class="field-label">{{ __('installer.shared_hosting.database_port') }}</span>
                        <input type="number" name="database_port" value="{{ old('database_port', 3306) }}"
                               class="pos-input" min="1" max="65535" required>
                    </label>
                </div>
                <label class="block">
                    <span class="field-label">{{ __('installer.shared_hosting.platform_database') }}</span>
                    <input type="text" name="platform_database" value="{{ old('platform_database', $setup['platform_database'] ?? '') }}"
                           class="pos-input" maxlength="64" required autocomplete="off">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('installer.shared_hosting.database_username') }}</span>
                    <input type="text" name="database_username" value="{{ old('database_username') }}"
                           class="pos-input" maxlength="128" required autocomplete="off">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('installer.shared_hosting.database_password') }}</span>
                    <div class="pos-input-wrap has-suffix" x-data="{ shown: false }">
                        <input :type="shown ? 'text' : 'password'" name="database_password"
                               class="pos-input" maxlength="4096" autocomplete="new-password">
                        <button type="button" class="pos-input-suffix" @click="shown = !shown"
                                :aria-label="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))"
                                :aria-pressed="shown">
                            <span x-show="!shown"><x-icon name="eye" class="w-[18px] h-[18px]" /></span>
                            <span x-show="shown" x-cloak><x-icon name="eye-off" class="w-[18px] h-[18px]" /></span>
                        </button>
                    </div>
                </label>

                @unless ($directInstall)
                <div class="border-t border-[var(--border-subtle)] pt-6">
                    <h3 class="text-[14px] font-semibold fg-primary">{{ __('installer.shared_hosting.mail_title') }}</h3>
                    <p class="mt-1 text-[12px] fg-secondary">{{ __('installer.shared_hosting.mail_help') }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="block sm:col-span-2">
                        <span class="field-label">{{ __('installer.shared_hosting.mail_host') }}</span>
                        <input type="text" name="mail_host" value="{{ old('mail_host') }}"
                               class="pos-input" maxlength="253" required autocomplete="off">
                    </label>
                    <label class="block">
                        <span class="field-label">{{ __('installer.shared_hosting.mail_port') }}</span>
                        <input type="number" name="mail_port" value="{{ old('mail_port', 587) }}"
                               class="pos-input" min="1" max="65535" required>
                    </label>
                </div>
                <label class="block">
                    <span class="field-label">{{ __('installer.shared_hosting.mail_scheme') }}</span>
                    <select name="mail_scheme" class="pos-input" required x-data="enhancedSelect()">
                        <option value="smtp" @selected(old('mail_scheme', 'smtp') === 'smtp')>{{ __('installer.shared_hosting.mail_scheme_starttls') }}</option>
                        <option value="smtps" @selected(old('mail_scheme') === 'smtps')>{{ __('installer.shared_hosting.mail_scheme_tls') }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="field-label">{{ __('installer.shared_hosting.mail_username') }}</span>
                    <input type="text" name="mail_username" value="{{ old('mail_username') }}"
                           class="pos-input" maxlength="255" autocomplete="off">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('installer.shared_hosting.mail_password') }}</span>
                    <div class="pos-input-wrap has-suffix" x-data="{ shown: false }">
                        <input :type="shown ? 'text' : 'password'" name="mail_password"
                               class="pos-input" maxlength="4096" autocomplete="new-password">
                        <button type="button" class="pos-input-suffix" @click="shown = !shown"
                                :aria-label="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))"
                                :aria-pressed="shown">
                            <span x-show="!shown"><x-icon name="eye" class="w-[18px] h-[18px]" /></span>
                            <span x-show="shown" x-cloak><x-icon name="eye-off" class="w-[18px] h-[18px]" /></span>
                        </button>
                    </div>
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="field-label">{{ __('installer.shared_hosting.mail_from_address') }}</span>
                        <input type="email" name="mail_from_address" value="{{ old('mail_from_address') }}"
                               class="pos-input" maxlength="254" required autocomplete="off">
                    </label>
                    <label class="block">
                        <span class="field-label">{{ __('installer.shared_hosting.mail_from_name') }}</span>
                        <input type="text" name="mail_from_name" value="{{ old('mail_from_name', config('pos.name')) }}"
                               class="pos-input" maxlength="120" required>
                    </label>
                </div>

                <div class="border-t border-[var(--border-subtle)] pt-6">
                    <h3 class="text-[14px] font-semibold fg-primary">{{ __('installer.shared_hosting.cron_title') }}</h3>
                    <p class="mt-1 text-[12px] fg-secondary">{{ __('installer.shared_hosting.cron_help') }}</p>
                    <pre class="mt-3 overflow-x-auto rounded-[8px] bg-[var(--surface-muted)] p-3 text-[11px] fg-primary"><code>{{ $schedulerCommand }}</code></pre>
                    <button type="button" class="pos-btn pos-btn-ghost mt-3"
                            x-data="{ copied: false }"
                            @click="navigator.clipboard.writeText(@js($schedulerCommand)).then(() => copied = true)">
                        <span x-text="copied ? @js(__('installer.shared_hosting.copied')) : @js(__('installer.shared_hosting.copy_command'))"></span>
                    </button>
                </div>

                <label class="field-toggle">
                    <input type="checkbox" name="manual_provisioning_acknowledged" value="1" required>
                    <span>{{ __('installer.shared_hosting.manual_ack') }}</span>
                </label>
                <p class="text-[12px] fg-secondary">{{ __('installer.shared_hosting.manual_ack_help') }}</p>

                <label class="field-toggle">
                    <input type="checkbox" name="cron_acknowledged" value="1" required>
                    <span>{{ __('installer.shared_hosting.cron_ack') }}</span>
                </label>
                <p class="text-[12px] fg-secondary">{{ __('installer.shared_hosting.cron_ack_help') }}</p>
                @endunless
            </fieldset>

            <div class="flex items-center justify-between gap-3">
                <a href="{{ route('install.saas.hosting') }}" class="pos-btn pos-btn-ghost">{{ __('installer.back') }}</a>
                <x-installer.submit-button>{{ __('installer.shared_hosting.save') }}</x-installer.submit-button>
            </div>
        </form>
    @endif
@endsection
