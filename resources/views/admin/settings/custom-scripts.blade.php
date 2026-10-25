<x-admin-layout
    active="settings"
    :title="__('settings.custom_scripts.title')"
    :crumbs="[
        ['label' => __('settings.crumb_parent')],
        ['label' => __('settings.title'), 'href' => route('admin.settings.index')],
        ['label' => __('settings.custom_scripts.title')],
    ]">

    @php
        $enabled = (bool) old('enabled', $scripts['enabled']);
        $includeAuthenticated = (bool) old('include_authenticated', $scripts['include_authenticated']);
    @endphp

    <div class="page-wide" x-data="{ enabled: @js($enabled) }">
        <x-admin.demo-lock-banner>{{ __('settings.demo.locked_banner_generic') }}</x-admin.demo-lock-banner>

        <form method="POST" action="{{ route('admin.settings.custom-scripts.update') }}" data-ajax-form>
            @csrf
            @method('PATCH')

            <fieldset @disabled(pos_is_demo()) class="contents">
                <div class="page-header mb-6">
                    <div class="flex items-start gap-3">
                        <a href="{{ route('admin.settings.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('settings.title') }}">
                            <x-icon name="back" class="w-4 h-4" />
                        </a>
                        <div>
                            <h1 class="page-title">{{ __('settings.custom_scripts.title') }}</h1>
                            <p class="page-sub">{{ __('settings.custom_scripts.sub') }}</p>
                        </div>
                    </div>
                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                        <x-icon name="check" class="w-4 h-4" />
                        {{ __('settings.custom_scripts.actions.save') }}
                    </button>
                </div>

                <x-alert type="danger" class="mb-5">
                    <strong>{{ __('settings.custom_scripts.warning.title') }}</strong>
                    {{ __('settings.custom_scripts.warning.body') }}
                </x-alert>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
                    <div class="form-stack">
                        <div class="card">
                            <div class="card-header"><div>
                                <div class="card-title">{{ __('settings.custom_scripts.sections.status') }}</div>
                                <div class="card-title-sub">{{ __('settings.custom_scripts.sections.status_sub') }}</div>
                            </div></div>
                            <div class="card-body form-stack">
                                <label class="field-toggle">
                                    <input type="hidden" name="enabled" value="0">
                                    <input type="checkbox" name="enabled" value="1" x-model="enabled" @checked($enabled)>
                                    <span>{{ __('settings.custom_scripts.fields.enabled') }}</span>
                                </label>
                                <p class="field-help">{{ __('settings.custom_scripts.fields.enabled_help') }}</p>

                                <label class="field-toggle">
                                    <input type="hidden" name="include_authenticated" value="0">
                                    <input type="checkbox" name="include_authenticated" value="1" @checked($includeAuthenticated)>
                                    <span>{{ __('settings.custom_scripts.fields.include_authenticated') }}</span>
                                </label>
                                <p class="field-help">{{ __('settings.custom_scripts.fields.include_authenticated_help') }}</p>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header"><div>
                                <div class="card-title">{{ __('settings.custom_scripts.sections.scope') }}</div>
                                <div class="card-title-sub">{{ __('settings.custom_scripts.sections.scope_sub') }}</div>
                            </div></div>
                            <div class="card-body form-stack">
                                <div>
                                    <div class="field-label">{{ __('settings.custom_scripts.scope.public') }}</div>
                                    <p class="field-help">{{ __('settings.custom_scripts.scope.public_help') }}</p>
                                </div>
                                <div>
                                    <div class="field-label">{{ __('settings.custom_scripts.scope.excluded') }}</div>
                                    <p class="field-help">{{ __('settings.custom_scripts.scope.excluded_help') }}</p>
                                </div>
                                <x-alert type="info">{{ __('settings.custom_scripts.scope.recovery') }}</x-alert>
                            </div>
                        </div>
                    </div>

                    <div class="form-stack">
                        <div class="card">
                            <div class="card-header"><div>
                                <div class="card-title">{{ __('settings.custom_scripts.sections.code') }}</div>
                                <div class="card-title-sub">{{ __('settings.custom_scripts.sections.code_sub') }}</div>
                            </div></div>
                            <div class="card-body form-stack">
                                <label class="field">
                                    <span class="field-label">{{ __('settings.custom_scripts.fields.header') }}</span>
                                    <textarea name="header_scripts" rows="9" maxlength="50000" class="pos-input font-mono text-xs" spellcheck="false" placeholder="{{ __('settings.custom_scripts.fields.header_placeholder') }}">{{ old('header_scripts', $scripts['header']) }}</textarea>
                                    <span class="field-help">{{ __('settings.custom_scripts.fields.header_help') }}</span>
                                    @error('header_scripts')<span class="field-error">{{ $message }}</span>@enderror
                                </label>

                                <label class="field">
                                    <span class="field-label">{{ __('settings.custom_scripts.fields.footer') }}</span>
                                    <textarea name="footer_scripts" rows="9" maxlength="50000" class="pos-input font-mono text-xs" spellcheck="false" placeholder="{{ __('settings.custom_scripts.fields.footer_placeholder') }}">{{ old('footer_scripts', $scripts['footer']) }}</textarea>
                                    <span class="field-help">{{ __('settings.custom_scripts.fields.footer_help') }}</span>
                                    @error('footer_scripts')<span class="field-error">{{ $message }}</span>@enderror
                                </label>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header"><div>
                                <div class="card-title">{{ __('settings.custom_scripts.sections.confirmation') }}</div>
                                <div class="card-title-sub">{{ __('settings.custom_scripts.sections.confirmation_sub') }}</div>
                            </div></div>
                            <div class="card-body form-stack">
                                <label class="field">
                                    <span class="field-label is-required">{{ __('settings.custom_scripts.fields.current_password') }}</span>
                                    <input type="password" name="current_password" class="pos-input" required autocomplete="current-password">
                                    <span class="field-help">{{ __('settings.custom_scripts.fields.current_password_help') }}</span>
                                    @error('current_password')<span class="field-error">{{ $message }}</span>@enderror
                                </label>

                                <div x-show="enabled" x-cloak>
                                    <label class="field-toggle">
                                        <input type="checkbox" name="acknowledge_risk" value="1" :required="enabled" @checked(old('acknowledge_risk'))>
                                        <span>{{ __('settings.custom_scripts.fields.acknowledge') }}</span>
                                    </label>
                                    @error('acknowledge_risk')<p class="field-error mt-2">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
</x-admin-layout>
