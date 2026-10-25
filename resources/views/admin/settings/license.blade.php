<x-admin-layout
    active="settings"
    :title="__('settings.license.title')"
    :crumbs="[
        ['label' => __('settings.crumb_parent')],
        ['label' => __('settings.title'), 'href' => route('admin.settings.index')],
        ['label' => __('settings.license.title')],
    ]">

    @php
        $status = $license['status'] ?? 'unverified';
        $alertVariant = match ($status) {
            'valid'   => 'alert-success',
            'invalid' => 'alert-danger',
            default   => 'alert-info',
        };
        $statusIcon = match ($status) {
            'valid'   => 'check',
            'invalid' => 'alert',
            default   => 'info',
        };
    @endphp

    <div class="page-wide">
        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.settings.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('settings.title') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">{{ __('settings.license.title') }}</h1>
                    <p class="page-sub">{{ __('settings.license.sub') }}</p>
                </div>
            </div>

            @if (auth()->user()?->hasPermission('settings.update'))
                <form method="POST" action="{{ route('admin.settings.license.recheck') }}" data-ajax-form>
                    @csrf
                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-secondary" @disabled(pos_is_demo())>
                        <x-icon name="refresh" class="w-4 h-4" />
                        {{ __('settings.license.recheck.button') }}
                    </button>
                </form>
            @endif
        </div>

        @if (auth()->user()?->is_super_admin && auth()->user()?->hasPermission('settings.update'))
            <div class="card mb-5">
                <div class="card-body">
                    <h2 class="card-title">{{ __('settings.license.activation.title') }}</h2>
                    <p class="page-sub mb-4">{{ __('settings.license.activation.help') }}</p>
                    <form method="POST" action="{{ route('admin.settings.license.activate') }}" data-ajax-form>
                        @csrf
                        <fieldset @disabled(pos_is_demo())>
                            <label class="field-label is-required" for="license_key">{{ __('installer.license.key_label') }}</label>
                            <input class="pos-input" id="license_key" name="license_key" type="text" maxlength="64" required autocomplete="off" spellcheck="false">
                            <button class="pos-btn pos-btn-primary mt-3" type="submit">{{ __('settings.license.activation.button') }}</button>
                        </fieldset>
                    </form>
                </div>
            </div>
            <div class="card mb-5">
                <div class="card-body">
                    <h2 class="card-title">{{ __('settings.license.deregister.title') }}</h2>
                    <p class="page-sub mb-4">{{ __('settings.license.deregister.help', ['domain' => config('app.url')]) }}</p>
                    @if (! config('pos.license.registration_token'))
                        <div class="alert alert-warning mb-3" role="alert">
                            <span class="alert-icon"><x-icon name="alert" class="w-5 h-5" /></span>
                            <div class="alert-body">
                                <p class="alert-msg">{{ __('installer.license.errors.registration_token_missing') }}</p>
                            </div>
                        </div>
                    @endif
                    <form method="POST" action="{{ route('admin.settings.license.deregister') }}" data-ajax-form>
                        @csrf
                        <fieldset @disabled(pos_is_demo() || ! config('pos.license.registration_token') || $status !== 'valid')>
                            <label class="field-toggle mb-3">
                                <input type="checkbox" name="confirm" value="1" required>
                                <span>{{ __('settings.license.deregister.confirm') }}</span>
                            </label>
                            <button class="pos-btn pos-btn-danger" type="submit">{{ __('settings.license.deregister.button') }}</button>
                        </fieldset>
                    </form>
                </div>
            </div>
        @endif

        {{-- Status banner --}}
        <div class="alert {{ $alertVariant }} mb-5">
            <span class="alert-icon"><x-icon :name="$statusIcon" class="w-5 h-5" /></span>
            <div class="alert-body">
                <div class="alert-title">{{ __('settings.license.status.'.$status) }}</div>
                <div class="alert-msg">{{ __('settings.license.status_help.'.$status) }}</div>
            </div>
        </div>

        {{-- Last verification error, if any --}}
        @if (! empty($license['last_error']))
            <div class="alert alert-warning mb-5">
                <span class="alert-icon"><x-icon name="alert" class="w-5 h-5" /></span>
                <div class="alert-body">
                    <div class="alert-title">{{ __('settings.license.error_label') }}</div>
                    <div class="alert-msg">{{ $license['last_error'] }}</div>
                </div>
            </div>
        @endif

        {{-- Purchase details --}}
        <div class="card">
            <div class="card-header"><div>
                <div class="card-title">{{ __('settings.license.section.details') }}</div>
            </div></div>
            <div class="card-body">
                <dl class="license-detail-list">
                    <div class="license-detail-row">
                        <dt>{{ __('settings.license.fields.key') }}</dt>
                        <dd class="mono">
                            @if ($keyLast4)
                                {{ __('settings.license.fields.key_masked', ['last4' => $keyLast4]) }}
                            @else
                                <span class="fg-tertiary">{{ __('settings.license.fields.no_key') }}</span>
                            @endif
                        </dd>
                    </div>

                    @if (! empty($license['type']))
                        <div class="license-detail-row">
                            <dt>{{ __('settings.license.fields.type') }}</dt>
                            <dd>{{ __('settings.license.type.'.$license['type']) }}</dd>
                        </div>
                    @endif

                    @if (! empty($license['buyer_name']))
                        <div class="license-detail-row">
                            <dt>{{ __('settings.license.fields.buyer_name') }}</dt>
                            <dd>{{ $license['buyer_name'] }}</dd>
                        </div>
                    @endif

                    @if (! empty($license['buyer_email']))
                        <div class="license-detail-row">
                            <dt>{{ __('settings.license.fields.buyer_email') }}</dt>
                            <dd>{{ $license['buyer_email'] }}</dd>
                        </div>
                    @endif

                    <div class="license-detail-row">
                        <dt>{{ __('settings.license.fields.support_until') }}</dt>
                        <dd>{{ ! empty($license['support_until']) ? format_date($license['support_until']) : '—' }}</dd>
                    </div>

                    <div class="license-detail-row">
                        <dt>{{ __('settings.license.fields.expires_at') }}</dt>
                        <dd>{{ ! empty($license['expires_at']) ? format_date($license['expires_at']) : __('settings.license.fields.no_expiry') }}</dd>
                    </div>

                    <div class="license-detail-row">
                        <dt>{{ __('settings.license.fields.last_checked') }}</dt>
                        <dd>{{ ! empty($license['last_checked_at']) ? format_datetime($license['last_checked_at']) : __('settings.license.fields.never') }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</x-admin-layout>
