@php
    $editing = $connection->exists;
    $selectedProvider = old('provider', $connection->provider ?: 'woocommerce');
    $credentials = array_replace($displayCredentials, old('credentials', []));
    $settings = array_replace([
        'sync_products' => true,
        'publish_online_store' => true,
        'sync_customers' => true,
        'import_orders' => true,
        'payment_method_id' => null,
        'inventory_master' => 'pos',
    ], $connection->settings ?? [], old('settings', []));
    $locationNames = collect($remoteLocations)->pluck('name', 'id')->all();
@endphp

<x-admin-layout
    active="sales-channels"
    :title="$editing ? __('commerce.form.edit_title') : __('commerce.form.add_title')"
    :crumbs="[
        ['label' => __('admin.shell.brand_sub')],
        ['label' => __('commerce.title'), 'href' => route('admin.commerce.connections.index')],
        ['label' => $editing ? $connection->name : __('commerce.form.add_crumb')],
    ]">

    <div id="commerce-connection-shell" class="page-wide" x-data="{ provider: @js($selectedProvider), importOrders: @js((bool) ($settings['import_orders'] ?? false)) }">
        <x-admin.demo-lock-banner>{{ __('settings.demo.locked_banner') }}</x-admin.demo-lock-banner>

        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.commerce.connections.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('commerce.actions.back') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">{{ $editing ? $connection->name : __('commerce.form.add_title') }}</h1>
                    <p class="page-sub">
                        @if ($editing)
                            {{ __('commerce.form.edit_sub') }}
                        @else
                            {{ __('commerce.form.add_sub') }}
                        @endif
                    </p>
                </div>
            </div>
            @if ($editing)
                <span class="badge {{ $connection->isConnected() ? 'badge-positive' : ($connection->status === 'error' ? 'badge-danger' : '') }}">
                    <span class="badge-dot"></span>{{ __('commerce.status.'.$connection->status) }}
                </span>
            @endif
        </div>

        <form method="POST" action="{{ $editing ? route('admin.commerce.connections.update', $connection) : route('admin.commerce.connections.store') }}"
              data-ajax-form data-ajax-refresh="#commerce-connection-shell">
            @csrf
            @if ($editing) @method('PATCH') @endif

            <fieldset @disabled(pos_is_demo()) class="contents">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">{{ __('commerce.form.connection.title') }}</div>
                            <div class="card-sub">{{ __('commerce.form.connection.sub') }}</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="form-stack">
                            <label class="field">
                                <span class="field-label is-required">{{ __('commerce.form.connection.provider') }}</span>
                                @if ($editing)
                                    <input type="hidden" name="provider" value="{{ $connection->provider }}">
                                    <input class="pos-input" value="{{ $providers[$connection->provider]->label() }}" disabled>
                                @else
                                    <select name="provider" class="pos-input" x-model="provider">
                                        @foreach ($providers as $code => $provider)
                                            <option value="{{ $code }}">{{ $provider->label() }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <span class="field-help">{{ __('commerce.form.connection.provider_help') }}</span>
                            </label>

                            <label class="field">
                                <span class="field-label is-required">{{ __('commerce.form.connection.name') }}</span>
                                <input type="text" name="name" class="pos-input" maxlength="255" required
                                       value="{{ old('name', $connection->name) }}" placeholder="{{ __('commerce.form.connection.name_placeholder') }}">
                            </label>

                            <label class="field">
                                <span class="field-label is-required">{{ __('commerce.form.connection.shop_url') }}</span>
                                <input type="text" name="shop_url" class="pos-input mono" maxlength="2048" required
                                       value="{{ old('shop_url', $connection->shop_url) }}"
                                       :placeholder="provider === 'shopify' ? @js(__('commerce.form.connection.shopify_url_placeholder')) : @js(__('commerce.form.connection.woocommerce_url_placeholder'))">
                                <span class="field-help" x-show="provider === 'shopify'">{{ __('commerce.form.connection.shopify_url_help') }}</span>
                            </label>

                            <div x-show="provider === 'woocommerce'" x-cloak class="form-stack">
                                <label class="field">
                                    <span class="field-label is-required">{{ __('commerce.form.connection.consumer_key') }}</span>
                                    <input type="text" name="credentials[consumer_key]" class="pos-input mono" maxlength="191"
                                           value="{{ $credentials['consumer_key'] ?? '' }}" placeholder="ck_..." autocomplete="off">
                                </label>
                                <label class="field">
                                    <span class="field-label is-required">{{ __('commerce.form.connection.consumer_secret') }}</span>
                                    <input type="password" name="credentials[consumer_secret]" class="pos-input mono" maxlength="255"
                                           value="{{ $credentials['consumer_secret'] ?? '' }}" placeholder="cs_..." autocomplete="new-password">
                                </label>
                                <label class="field">
                                    <span class="field-label">{{ __('commerce.form.connection.webhook_secret') }}</span>
                                    <input type="password" name="credentials[webhook_secret]" class="pos-input mono" maxlength="255"
                                           value="{{ $credentials['webhook_secret'] ?? '' }}" autocomplete="new-password">
                                    <span class="field-help">{{ __('commerce.form.connection.webhook_secret_help') }}</span>
                                </label>
                            </div>

                            <div x-show="provider === 'shopify'" x-cloak class="form-stack">
                                <label class="field">
                                    <span class="field-label is-required">{{ __('commerce.form.connection.client_id') }}</span>
                                    <input type="text" name="credentials[client_id]" class="pos-input mono" maxlength="191"
                                           value="{{ $credentials['client_id'] ?? '' }}" autocomplete="off">
                                </label>
                                <label class="field">
                                    <span class="field-label is-required">{{ __('commerce.form.connection.client_secret') }}</span>
                                    <input type="password" name="credentials[client_secret]" class="pos-input mono" maxlength="255"
                                           value="{{ $credentials['client_secret'] ?? '' }}" autocomplete="new-password">
                                    <span class="field-help">{{ __('commerce.form.connection.client_secret_help') }}</span>
                                </label>
                                <label class="field">
                                    <span class="field-label">{{ __('commerce.form.connection.api_version') }}</span>
                                    <input type="text" name="api_version" class="pos-input mono" maxlength="7"
                                           :disabled="provider !== 'shopify'"
                                           value="{{ old('api_version', $connection->api_version ?: config('commerce.shopify.api_version')) }}">
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">{{ __('commerce.form.sync.title') }}</div>
                            <div class="card-sub">{{ __('commerce.form.sync.sub') }}</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="form-stack">
                            <label class="field">
                                <span class="field-label">{{ __('commerce.form.sync.inventory_authority') }}</span>
                                <input type="hidden" name="settings[inventory_master]" value="pos">
                                <input class="pos-input" value="{{ __('commerce.form.sync.inventory_authority_value') }}" disabled>
                                <span class="field-help">{{ __('commerce.form.sync.inventory_authority_help') }}</span>
                            </label>

                            @foreach ([
                                'sync_products' => [__('commerce.form.sync.sync_products'), __('commerce.form.sync.sync_products_help')],
                                'sync_customers' => [__('commerce.form.sync.sync_customers'), __('commerce.form.sync.sync_customers_help')],
                                'import_orders' => [__('commerce.form.sync.import_orders'), __('commerce.form.sync.import_orders_help')],
                            ] as $key => [$label, $help])
                                <label class="field-toggle">
                                    <input type="hidden" name="settings[{{ $key }}]" value="0">
                                    <input type="checkbox" name="settings[{{ $key }}]" value="1" @checked((bool) ($settings[$key] ?? false))
                                           @if ($key === 'import_orders') x-model="importOrders" @endif>
                                    <span>{{ $label }}</span>
                                </label>
                                <span class="field-help">{{ $help }}</span>
                            @endforeach

                            <div x-show="provider === 'shopify'" x-cloak>
                                <label class="field-toggle">
                                    <input type="hidden" name="settings[publish_online_store]" value="0"
                                           :disabled="provider !== 'shopify'">
                                    <input type="checkbox" name="settings[publish_online_store]" value="1"
                                           :disabled="provider !== 'shopify'"
                                           @checked((bool) ($settings['publish_online_store'] ?? true))>
                                    <span>{{ __('commerce.form.sync.publish_online_store') }}</span>
                                </label>
                                <span class="field-help">{{ __('commerce.form.sync.publish_online_store_help') }}</span>
                            </div>

                            <label class="field" x-show="importOrders" x-cloak>
                                <span class="field-label is-required">{{ __('commerce.form.sync.payment_method') }}</span>
                                <select name="settings[payment_method_id]" class="pos-input"
                                        x-data="enhancedSelect({ maxOptions: 50 })" :required="importOrders">
                                    <option value="">{{ __('commerce.form.sync.payment_method_placeholder') }}</option>
                                    @foreach ($paymentMethods as $paymentMethod)
                                        <option value="{{ $paymentMethod->id }}" @selected((string) old('settings.payment_method_id', $settings['payment_method_id'] ?? '') === (string) $paymentMethod->id)>
                                            {{ $paymentMethod->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="field-help">{{ __('commerce.form.sync.payment_method_help') }}</span>
                                @error('settings.payment_method_id')<p class="field-error">{{ $message }}</p>@enderror
                            </label>

                            <div class="alert alert-info mt-3">
                                <span class="alert-icon">
                                    <x-icon name="info" class="w-5 h-5" />
                                </span>
                                <div class="alert-body">
                                    <div class="alert-title">{{ __('commerce.form.sync.outbox_title') }}</div>
                                    <p class="alert-msg">{{ __('commerce.form.sync.outbox_help') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-5">
                <a href="{{ route('admin.commerce.connections.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost">{{ __('commerce.actions.cancel') }}</a>
                <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                    <x-icon name="check" class="w-4 h-4" /> {{ __('commerce.actions.save_connection') }}
                </button>
            </div>
            </fieldset>
        </form>

        @if ($editing)
            <div class="card mt-6">
                <div class="card-header">
                    <div>
                        <div class="card-title">{{ __('commerce.health.title') }}</div>
                        <div class="card-sub">{{ __('commerce.health.sub') }}</div>
                    </div>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('admin.commerce.connections.sync', $connection) }}"
                              data-ajax-form data-ajax-refresh="#commerce-connection-shell">
                            @csrf
                            <button type="submit" class="pos-btn pos-btn-sm pos-btn-secondary" @disabled(pos_is_demo())>
                                <x-icon name="refresh" class="w-4 h-4" />
                                {{ __('commerce.actions.sync_now') }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.commerce.connections.test', $connection) }}"
                              data-ajax-form data-ajax-refresh="#commerce-connection-shell">
                            @csrf
                            <button type="submit" class="pos-btn pos-btn-sm pos-btn-secondary" @disabled(pos_is_demo())>
                                <x-icon name="link" class="w-4 h-4" />
                                {{ __('commerce.actions.test_connection') }}
                            </button>
                        </form>
                    </div>
                </div>
                @if ($connection->last_error)
                    <div class="card-body">
                        <x-alert type="danger">{{ $connection->last_error }}</x-alert>
                    </div>
                @endif
                <div class="card-body">
                    @php
                        $pendingSync = $connection->pending_outbox_count + $connection->pending_webhook_count;
                        $failedSync = $connection->failed_outbox_count + $connection->failed_webhook_count;
                    @endphp
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                        <div class="field">
                            <span class="field-label">{{ __('commerce.health.pending_work') }}</span>
                            <span class="font-medium">{{ $pendingSync }}</span>
                            <span class="field-help">{{ __('commerce.health.pending_breakdown', [
                                'realtime' => $connection->realtime_outbox_count + $connection->pending_webhook_count,
                                'bulk' => $connection->bulk_outbox_count,
                            ]) }}</span>
                        </div>
                        <div class="field">
                            <span class="field-label">{{ __('commerce.health.failed_work') }}</span>
                            <span class="font-medium">{{ $failedSync }}</span>
                        </div>
                        <div class="field">
                            <span class="field-label">{{ __('commerce.health.last_sync') }}</span>
                            <span class="font-medium">{{ $connection->last_reconciled_at?->diffForHumans() ?? __('commerce.status.never') }}</span>
                        </div>
                        <div class="field">
                            <span class="field-label">{{ __('commerce.health.failed_orders') }}</span>
                            <span class="font-medium">{{ $connection->failed_channel_orders_count }}</span>
                        </div>
                    </div>
                    <label class="field">
                        <span class="field-label">{{ __('commerce.health.webhook_url') }}</span>
                        <input class="pos-input mono" readonly value="{{ route('webhooks.commerce', ['provider' => $connection->provider, 'connection' => $connection]) }}">
                        <span class="field-help">
                            @if ($connection->provider === 'shopify')
                                {{ __('commerce.health.shopify_webhook_help') }}
                            @else
                                {{ __('commerce.health.woocommerce_webhook_help') }}
                            @endif
                        </span>
                    </label>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.commerce.connections.mappings', $connection) }}" class="card mt-6"
                  data-ajax-form data-ajax-refresh="#commerce-connection-shell">
                @csrf
                @method('PUT')
                <fieldset @disabled(pos_is_demo()) class="contents">
                <div class="card-header">
                    <div>
                        <div class="card-title">{{ __('commerce.mappings.title') }}</div>
                        <div class="card-sub">{{ __('commerce.mappings.sub') }}</div>
                    </div>
                </div>

                @if ($stores->isEmpty())
                    <div class="card-body"><p class="fg-tertiary">{{ __('commerce.mappings.empty') }}</p></div>
                @else
                    <div class="dt-scroll">
                        <table class="dt-table">
                            <thead>
                                <tr>
                                    <th>{{ __('commerce.mappings.columns.store') }}</th>
                                    <th>{{ __('commerce.mappings.columns.remote_location') }}</th>
                                    <th>{{ __('commerce.mappings.columns.mode') }}</th>
                                    <th>{{ __('commerce.mappings.columns.priority') }}</th>
                                    <th>{{ __('commerce.mappings.columns.safety_stock') }}</th>
                                    <th>{{ __('commerce.mappings.columns.inventory') }}</th>
                                    <th>{{ __('commerce.mappings.columns.orders') }}</th>
                                    <th>{{ __('commerce.mappings.columns.active') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($stores as $index => $store)
                                    @php
                                        $mapping = $mappings->get($store->id);
                                        $remoteId = old("mappings.$index.remote_location_id", $mapping?->remote_location_id);
                                    @endphp
                                    <tr x-data="{ location: @js($remoteId ?: ''), names: @js($locationNames) }">
                                        <td>
                                            <input type="hidden" name="mappings[{{ $index }}][store_id]" value="{{ $store->id }}">
                                            <div class="font-medium">{{ $store->name }}</div>
                                            <div class="text-xs fg-tertiary">{{ $store->code }}</div>
                                        </td>
                                        <td>
                                            <select name="mappings[{{ $index }}][remote_location_id]" class="pos-input" x-model="location">
                                                <option value="">{{ __('commerce.mappings.not_mapped') }}</option>
                                                @foreach ($remoteLocations as $location)
                                                    <option value="{{ $location['id'] }}">{{ $location['name'] }}{{ ($location['active'] ?? true) ? '' : __('commerce.status.inactive_suffix') }}</option>
                                                @endforeach
                                            </select>
                                            <input type="hidden" name="mappings[{{ $index }}][remote_location_name]" :value="names[location] || ''">
                                        </td>
                                        <td>
                                            <select name="mappings[{{ $index }}][inventory_mode]" class="pos-input">
                                                @foreach (['location', 'aggregate', 'disabled'] as $value)
                                                    <option value="{{ $value }}" @selected(old("mappings.$index.inventory_mode", $mapping?->inventory_mode ?? 'location') === $value)>{{ __('commerce.mappings.modes.'.$value) }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td><input type="number" name="mappings[{{ $index }}][fulfillment_priority]" class="pos-input" min="1" max="9999" value="{{ old("mappings.$index.fulfillment_priority", $mapping?->fulfillment_priority ?? (($index + 1) * 100)) }}"></td>
                                        <td><input type="number" name="mappings[{{ $index }}][safety_stock]" class="pos-input" min="0" step="0.0001" value="{{ old("mappings.$index.safety_stock", $mapping?->safety_stock ?? 0) }}"></td>
                                        @foreach (['sync_inventory' => ['inventory', true], 'fulfill_orders' => ['orders', true], 'is_active' => ['active', false]] as $field => [$column, $default])
                                            <td class="text-center">
                                                <input type="hidden" name="mappings[{{ $index }}][{{ $field }}]" value="0">
                                                <input type="checkbox"
                                                       class="pos-check"
                                                       name="mappings[{{ $index }}][{{ $field }}]"
                                                       value="1"
                                                       @checked((bool) old("mappings.$index.$field", $mapping?->{$field} ?? $default))
                                                       aria-label="{{ __('commerce.mappings.columns.'.$column) }} — {{ $store->name }}">
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer flex justify-end">
                        <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">{{ __('commerce.actions.save_mappings') }}</button>
                    </div>
                @endif
                </fieldset>
            </form>

            <div class="card mt-6">
                <div class="card-header">
                    <div>
                        <div class="card-title">{{ __('commerce.disconnect.title') }}</div>
                        <div class="card-sub">{{ __('commerce.disconnect.sub') }}</div>
                    </div>
                    <form method="POST" action="{{ route('admin.commerce.connections.destroy', $connection) }}" data-ajax-form
                          onsubmit="return confirm(@js(__('commerce.disconnect.confirm')))">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="pos-btn pos-btn-sm pos-btn-danger" @disabled(pos_is_demo())>{{ __('commerce.actions.disconnect') }}</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</x-admin-layout>
