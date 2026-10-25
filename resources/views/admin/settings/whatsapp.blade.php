<x-admin-layout active="settings" :title="__('whatsapp.settings.title')"
    :crumbs="[['label' => __('settings.title'), 'href' => route('admin.settings.index')], ['label' => __('whatsapp.settings.title')]]">
    <div class="page-wide space-y-5">
        <div class="page-header">
            <div>
                <h1 class="page-title">{{ __('whatsapp.settings.title') }}</h1>
                <p class="page-sub">{{ __('whatsapp.settings.sub') }}</p>
            </div>
            @can('whatsapp.logs.view')
                <a class="pos-btn pos-btn-sm pos-btn-ghost" href="{{ route('admin.whatsapp.logs.index') }}">{{ __('whatsapp.logs.title') }}</a>
            @endcan
        </div>

        <x-admin.demo-lock-banner>{{ __('settings.demo.locked_banner_generic') }}</x-admin.demo-lock-banner>
        <x-alert type="info">{{ __('whatsapp.settings.click_help') }}</x-alert>

        @foreach($connections as $connection)
            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">{{ $connection->name }}</div>
                        <div class="card-title-sub">
                            {{ $connection->store?->name ?: __('whatsapp.settings.company_default') }} ·
                            {{ $connection->provider }} · {{ __('whatsapp.statuses.'.$connection->status) }}
                        </div>
                    </div>
                    @can('settings.whatsapp.credentials')
                        <x-admin.row-actions>
                            <x-admin.row-action
                                :href="route('admin.settings.whatsapp.connections.edit', $connection)"
                                icon="edit"
                                :label="__('whatsapp.actions.edit')" />
                            <div class="row-action-sep"></div>
                            <x-admin.row-action
                                icon="trash"
                                variant="danger"
                                :label="__('whatsapp.actions.delete')"
                                :disabled="pos_is_demo()"
                                @click="$store.confirm.show({
                                    title: {{ \Illuminate\Support\Js::from(__('whatsapp.confirm_delete.title', ['name' => $connection->name])) }},
                                    message: {{ \Illuminate\Support\Js::from(__('whatsapp.confirm_delete.message')) }},
                                    intent: 'danger',
                                    confirmLabel: {{ \Illuminate\Support\Js::from(__('whatsapp.confirm_delete.confirm')) }},
                                    onConfirm: () => $deleteForm({{ \Illuminate\Support\Js::from(route('admin.settings.whatsapp.connections.destroy', $connection)) }}),
                                })" />
                        </x-admin.row-actions>
                    @endcan
                </div>
            </div>
        @endforeach

        @can('settings.whatsapp.credentials')
            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">{{ __('whatsapp.settings.add_connection') }}</div>
                        <div class="card-title-sub">{{ __('whatsapp.settings.add_connection_sub') }}</div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.settings.whatsapp.connections.store') }}" data-ajax-form class="form-stack">
                        @csrf
                        <fieldset @disabled(pos_is_demo()) class="contents">
                            @include('admin.settings._whatsapp-connection-fields', ['connection' => null])
                            <div class="flex justify-end"><button class="pos-btn pos-btn-sm pos-btn-primary">{{ __('whatsapp.actions.save') }}</button></div>
                        </fieldset>
                    </form>
                </div>
            </div>
        @endcan

        @can('settings.whatsapp.update')
            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">{{ __('whatsapp.settings.message_templates') }}</div>
                        <div class="card-title-sub">{{ __('whatsapp.settings.message_templates_sub') }}</div>
                    </div>
                </div>
                <div class="card-body grid grid-cols-1 lg:grid-cols-2 gap-4">
                    @foreach($documentTypes as $type)
                        @php
                            $saved = $templates->first(fn($t) => $t->document_type === $type && $t->store_id === current_store_id())
                                ?? $templates->first(fn($t) => $t->document_type === $type && $t->store_id === null);
                            $scopeValue = old('document_type') === $type ? old('store_id') : $saved?->store_id;
                            $templateEntries = $templates->where('document_type', $type)->mapWithKeys(fn($template) => [
                                (string) ($template->store_id ?? '') => ['locale' => $template->locale, 'body' => $template->body],
                            ])->all();
                        @endphp
                        <form method="POST" action="{{ route('admin.settings.whatsapp.templates.store') }}" data-ajax-form class="card p-4 form-stack"
                              x-data="{
                                  scope: @js((string) ($scopeValue ?? '')),
                                  entries: @js($templateEntries),
                                  defaultLocale: @js(current_store()?->locale ?: 'en'),
                                  defaultBody: @js(__('whatsapp.templates.'.$type)),
                                  loadScope() {
                                      const entry = this.entries[this.scope];
                                      this.$refs.locale.value = entry?.locale ?? this.defaultLocale;
                                      this.$refs.body.value = entry?.body ?? this.defaultBody;
                                  }
                              }">
                            @csrf
                            <fieldset @disabled(pos_is_demo()) class="contents">
                                <input type="hidden" name="document_type" value="{{ $type }}">
                                <div class="font-medium">{{ __('whatsapp.document_types.'.$type) }}</div>
                                <label class="field">
                                    <span class="field-label">{{ __('whatsapp.fields.template_scope') }}</span>
                                    <select name="store_id" class="pos-input" x-model="scope" @change="loadScope()">
                                        @if(auth()->user()->is_super_admin)
                                            <option value="" @selected($scopeValue === null || $scopeValue === '')>{{ __('whatsapp.settings.company_default') }}</option>
                                        @endif
                                        @foreach($stores as $store)
                                            <option value="{{ $store->id }}" @selected((string) $scopeValue === (string) $store->id)>{{ $store->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="field"><span class="field-label">{{ __('whatsapp.fields.locale') }}</span><input x-ref="locale" name="locale" class="pos-input" value="{{ old('document_type') === $type ? old('locale') : ($saved?->locale ?: (current_store()?->locale ?: 'en')) }}" required></label>
                                <label class="field"><span class="field-label">{{ __('whatsapp.fields.body') }}</span><textarea x-ref="body" name="body" class="pos-input min-h-40" required>{{ old('document_type') === $type ? old('body') : ($saved?->body ?: __('whatsapp.templates.'.$type)) }}</textarea><span class="field-help">@foreach($templateVariables[$type] as $key)<code>&#123;&#123; {{ $key }} &#125;&#125;</code>@unless($loop->last) &middot; @endunless @endforeach</span></label>
                                <div class="flex justify-end">
                                    <button class="pos-btn pos-btn-sm pos-btn-primary">
                                        <x-icon name="check" class="w-4 h-4" />
                                        {{ __('whatsapp.actions.save_template') }}
                                    </button>
                                </div>
                            </fieldset>
                        </form>
                    @endforeach
                </div>
            </div>
        @endcan
    </div>
</x-admin-layout>
