@php
    $statusClass = $connection->status === \App\Models\WhatsAppConnection::STATUS_CONNECTED
        ? 'badge-positive'
        : ($connection->status === \App\Models\WhatsAppConnection::STATUS_ERROR ? 'badge-danger' : '');
@endphp

<x-admin-layout active="settings" :title="__('whatsapp.settings.edit_connection')"
    :crumbs="[
        ['label' => __('settings.title'), 'href' => route('admin.settings.index')],
        ['label' => __('whatsapp.settings.title'), 'href' => route('admin.settings.whatsapp.edit')],
        ['label' => $connection->name],
    ]">
    <div id="whatsapp-connection-shell" class="page-wide">
        <x-admin.demo-lock-banner>{{ __('settings.demo.locked_banner_generic') }}</x-admin.demo-lock-banner>

        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.settings.whatsapp.edit') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('whatsapp.actions.back') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">{{ $connection->name }}</h1>
                    <p class="page-sub">{{ __('whatsapp.settings.edit_connection_sub') }}</p>
                </div>
            </div>
            <span class="badge {{ $statusClass }}"><span class="badge-dot"></span>{{ __('whatsapp.statuses.'.$connection->status) }}</span>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,2fr)_minmax(340px,1fr)] gap-5 items-start">
            <aside class="space-y-5 xl:order-2">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">{{ __('whatsapp.settings.connection_test') }}</div>
                            <div class="card-title-sub">{{ __('whatsapp.settings.connection_test_sub') }}</div>
                        </div>
                    </div>
                    <div class="card-body form-stack">
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <div class="field-label">{{ __('whatsapp.fields.status') }}</div>
                                <span class="badge {{ $statusClass }}"><span class="badge-dot"></span>{{ __('whatsapp.statuses.'.$connection->status) }}</span>
                            </div>
                            <div>
                                <div class="field-label">{{ __('whatsapp.fields.last_tested') }}</div>
                                <div>{{ $connection->last_tested_at?->diffForHumans() ?: __('whatsapp.values.never') }}</div>
                            </div>
                        </div>

                        @if($connection->last_error)
                            <x-alert type="danger">{{ $connection->last_error }}</x-alert>
                        @endif

                        <form method="POST" action="{{ route('admin.settings.whatsapp.connections.test', $connection) }}" data-ajax-form>
                            @csrf
                            <button class="pos-btn pos-btn-sm pos-btn-primary w-full justify-center" @disabled(pos_is_demo())>
                                <x-icon name="link" class="w-4 h-4" /> {{ __('whatsapp.actions.test') }}
                            </button>
                        </form>

                        <label class="field">
                            <span class="field-label">{{ __('whatsapp.fields.webhook_url') }}</span>
                            <input class="pos-input mono text-xs" value="{{ route('webhooks.whatsapp.receive', $connection) }}" readonly>
                            <span class="field-help">{{ __('whatsapp.settings.webhook_help') }}</span>
                        </label>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">{{ __('whatsapp.settings.test_message') }}</div>
                            <div class="card-title-sub">{{ __('whatsapp.settings.test_message_sub') }}</div>
                        </div>
                    </div>
                    <div class="card-body">
                        @if($connection->status === \App\Models\WhatsAppConnection::STATUS_CONNECTED)
                            <form method="POST" action="{{ route('admin.settings.whatsapp.connections.test-message', $connection) }}" data-ajax-form class="form-stack">
                                @csrf
                                <fieldset @disabled(pos_is_demo()) class="contents">
                                    <label class="field"><span class="field-label is-required">{{ __('whatsapp.fields.test_phone') }}</span><input name="phone" class="pos-input" value="{{ old('phone') }}" placeholder="+919876543210" required></label>
                                    <label class="field"><span class="field-label is-required">{{ __('whatsapp.fields.meta_template') }}</span><input name="template_name" class="pos-input" value="{{ old('template_name', 'hello_world') }}" required></label>
                                    <label class="field"><span class="field-label is-required">{{ __('whatsapp.fields.locale') }}</span><input name="locale" class="pos-input" value="{{ old('locale', 'en_US') }}" required></label>
                                    <button class="pos-btn pos-btn-sm pos-btn-secondary justify-center"><x-icon name="arrow-right" class="w-4 h-4" /> {{ __('whatsapp.actions.send_test') }}</button>
                                </fieldset>
                            </form>
                        @else
                            <x-alert type="info">{{ __('whatsapp.settings.test_message_locked') }}</x-alert>
                        @endif
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="button" class="pos-btn pos-btn-sm pos-btn-danger" @disabled(pos_is_demo())
                            @click="$store.confirm.show({
                                title: {{ \Illuminate\Support\Js::from(__('whatsapp.confirm_delete.title', ['name' => $connection->name])) }},
                                message: {{ \Illuminate\Support\Js::from(__('whatsapp.confirm_delete.message')) }},
                                intent: 'danger',
                                confirmLabel: {{ \Illuminate\Support\Js::from(__('whatsapp.confirm_delete.confirm')) }},
                                onConfirm: () => $deleteForm({{ \Illuminate\Support\Js::from(route('admin.settings.whatsapp.connections.destroy', $connection)) }}),
                            })">
                        <x-icon name="trash" class="w-4 h-4" />
                        {{ __('whatsapp.actions.delete') }}
                    </button>
                </div>
            </aside>

            <div class="card xl:order-1">
                <div class="card-header">
                    <div>
                        <div class="card-title">{{ __('whatsapp.settings.edit_connection') }}</div>
                        <div class="card-title-sub">{{ $connection->store?->name ?: __('whatsapp.settings.company_default') }} · {{ ($providers[$connection->provider] ?? null)?->label() ?: $connection->provider }}</div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.settings.whatsapp.connections.update', $connection) }}" data-ajax-form class="form-stack">
                        @csrf @method('PATCH')
                        <fieldset @disabled(pos_is_demo()) class="contents">
                            @include('admin.settings._whatsapp-connection-fields', ['connection' => $connection])
                            <div class="flex gap-2 justify-end">
                                <a href="{{ route('admin.settings.whatsapp.edit') }}" class="pos-btn pos-btn-sm pos-btn-ghost">{{ __('whatsapp.actions.back') }}</a>
                                <button class="pos-btn pos-btn-sm pos-btn-primary">{{ __('whatsapp.actions.save') }}</button>
                            </div>
                        </fieldset>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
