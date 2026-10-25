<x-admin-layout
    active="settings"
    :title="__('plugins.title')"
    :crumbs="[
        ['label' => __('settings.crumb_parent')],
        ['label' => __('settings.title'), 'href' => route('admin.settings.index')],
        ['label' => __('plugins.title')],
    ]">

    <div class="page-wide">
        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.settings.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('settings.title') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">{{ __('plugins.title') }}</h1>
                    <p class="page-sub">{{ __('plugins.sub') }}</p>
                </div>
            </div>
        </div>

        <x-admin.demo-lock-banner>{{ __('plugins.demo_locked') }}</x-admin.demo-lock-banner>

        <div class="alert alert-warning mb-6">
            <span class="alert-icon"><x-icon name="alert" class="w-5 h-5" /></span>
            <div class="alert-body">
                <div class="alert-title">{{ __('plugins.trust_title') }}</div>
                <div class="alert-msg">{{ __('plugins.trust_message') }}</div>
            </div>
        </div>

        @unless ($platformReady)
            <div class="alert alert-danger mb-5">
                <span class="alert-icon"><x-icon name="alert" class="w-5 h-5" /></span>
                <div class="alert-body"><div class="alert-msg">{{ __('plugins.database_unavailable') }}</div></div>
            </div>
        @endunless

        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-6 items-start">
            <div id="plugins-list" class="card card-pad-0 min-w-0">
                <div class="card-header">
                    <div>
                        <div class="card-title">{{ __('plugins.installed_title') }}</div>
                        <div class="card-title-sub">{{ __('plugins.installed_sub') }}</div>
                    </div>
                </div>

                @if ($plugins->isEmpty())
                    <div class="dt-empty">
                        <span class="dt-empty-icon"><x-icon name="box" class="w-5 h-5" /></span>
                        <div class="dt-empty-title">{{ __('plugins.empty_title') }}</div>
                        <div class="dt-empty-sub">{{ __('plugins.empty_sub') }}</div>
                    </div>
                @else
                    <table class="dt-table">
                        <thead><tr>
                            <th>{{ __('plugins.columns.plugin') }}</th>
                            <th>{{ __('plugins.columns.status') }}</th>
                            <th class="num">{{ __('plugins.columns.actions') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($plugins as $plugin)
                            @php
                                $settingsRoute = data_get($plugin->manifest, 'settings_route');
                                $hasSettings = is_string($settingsRoute) && \Illuminate\Support\Facades\Route::has($settingsRoute);
                                $pluginSupported = $pluginPolicy->allows($plugin->slug, (array) $plugin->manifest);
                            @endphp
                            <tr>
                                <td>
                                    <div class="font-semibold">{{ $plugin->name }}</div>
                                    <div class="fg-tertiary text-xs mt-1">{{ __('plugins.meta', ['version' => $plugin->version, 'author' => $plugin->author]) }}</div>
                                    @if ($plugin->last_error)
                                        <div class="alert alert-danger mt-3"><div class="alert-body"><div class="alert-msg">{{ $plugin->last_error }}</div></div></div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $pluginSupported && $plugin->is_enabled ? 'badge-positive' : 'badge-danger' }}">
                                        <span class="badge-dot" aria-hidden="true"></span>
                                        {{ ! $pluginSupported ? __('plugins.status.blocked') : ($plugin->is_enabled ? __('plugins.status.enabled') : __('plugins.status.disabled')) }}
                                    </span>
                                    @unless($pluginSupported)
                                        <div class="fg-tertiary text-xs mt-2">{{ __('plugins.saas_only_record') }}</div>
                                    @endunless
                                </td>
                                <td class="num">
                                    <div class="flex justify-end gap-2 flex-wrap">
                                        @if ($pluginSupported && $hasSettings && $plugin->is_enabled)
                                            <a href="{{ route($settingsRoute) }}" class="pos-btn pos-btn-xs">
                                                <x-icon name="settings" class="w-3.5 h-3.5" />
                                                {{ __('plugins.actions.settings') }}
                                            </a>
                                        @endif
                                        @if ($plugin->is_enabled)
                                            <form method="POST" action="{{ route('admin.settings.plugins.disable', $plugin) }}" data-ajax-form novalidate>@csrf
                                                <button class="pos-btn pos-btn-xs pos-btn-secondary" @disabled(pos_is_demo())>{{ __('plugins.actions.disable') }}</button>
                                            </form>
                                        @else
                                            @if ($pluginSupported)
                                            <form method="POST" action="{{ route('admin.settings.plugins.enable', $plugin) }}" data-ajax-form novalidate>@csrf
                                                <div class="mb-2">
                                                    <x-admin.password-input name="current_password" required autocomplete="current-password" :placeholder="__('plugins.current_password')" :aria-label="__('plugins.current_password')" />
                                                </div>
                                                <button class="pos-btn pos-btn-xs pos-btn-primary" @disabled(pos_is_demo())>{{ __('plugins.actions.enable') }}</button>
                                            </form>
                                            @endif
                                            <form method="POST" action="{{ route('admin.settings.plugins.destroy', $plugin) }}" data-ajax-form novalidate
                                                  x-data="{ confirmed: false }"
                                                  @submit="
                                                      if (!confirmed) {
                                                          $event.preventDefault();
                                                          const form = $el;
                                                          $store.confirm.show({
                                                              title: @js(__('plugins.actions.uninstall')),
                                                              message: @js(__('plugins.uninstall_confirm', ['name' => $plugin->name])),
                                                              intent: 'danger',
                                                              confirmLabel: @js(__('plugins.actions.uninstall')),
                                                              cancelLabel: @js(__('plugins.actions.cancel')),
                                                              onConfirm: () => new Promise(resolve => {
                                                                  form.addEventListener('ajax-form:end', resolve, { once: true });
                                                                  confirmed = true;
                                                                  form.requestSubmit();
                                                              }),
                                                          });
                                                      } else {
                                                          confirmed = false;
                                                      }
                                                  ">
                                                @csrf @method('DELETE')
                                                <input type="hidden" name="confirm_slug" value="{{ $plugin->slug }}">
                                                <div class="mb-2">
                                                    <x-admin.password-input name="current_password" required autocomplete="current-password" :placeholder="__('plugins.current_password')" :aria-label="__('plugins.current_password')" />
                                                </div>
                                                <button class="pos-btn pos-btn-xs pos-btn-danger" @disabled(pos_is_demo())>{{ __('plugins.actions.uninstall') }}</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="card">
                <div class="card-header"><div>
                    <div class="card-title">{{ __('plugins.upload_title') }}</div>
                    <div class="card-title-sub">{{ __('plugins.upload_sub') }}</div>
                </div></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.settings.plugins.install') }}" enctype="multipart/form-data" class="form-stack" novalidate
                          data-ajax-form x-data
                          @ajax-form:end="if ($event.detail.ok) { $el.reset(); $dispatch('plugin-upload-reset'); }">
                        @csrf
                        <div class="field">
                            <label for="plugin-package" class="form-label">{{ __('plugins.package_label') }}</label>
                            <div class="img-upload" x-data="{ fileName: '', dragOver: false }"
                                 @plugin-upload-reset.window="fileName = ''; dragOver = false">
                                <input id="plugin-package" type="file" name="plugin" accept=".zip,application/zip"
                                       class="img-upload-input" x-ref="packageInput" required
                                       @change="fileName = $event.target.files[0]?.name || ''"
                                       @invalid="$refs.packagePicker.focus()"
                                       @disabled(pos_is_demo() || ! $platformReady)>
                                <button type="button" class="img-upload-zone is-empty w-full" x-ref="packagePicker"
                                        :class="{ 'is-dragover': dragOver }"
                                        @click="$refs.packageInput.click()"
                                        @dragover.prevent="dragOver = !$refs.packageInput.disabled"
                                        @dragleave.prevent="dragOver = false"
                                        @drop.prevent="
                                            dragOver = false;
                                            if (!$refs.packageInput.disabled && $event.dataTransfer.files.length) {
                                                const transfer = new DataTransfer();
                                                transfer.items.add($event.dataTransfer.files[0]);
                                                $refs.packageInput.files = transfer.files;
                                                $refs.packageInput.dispatchEvent(new Event('change', { bubbles: true }));
                                            }
                                        "
                                        @disabled(pos_is_demo() || ! $platformReady)>
                                    <span class="img-upload-empty">
                                        <span class="img-upload-empty-icon"><x-icon name="upload" class="w-4 h-4" /></span>
                                        <span class="img-upload-empty-title break-all"
                                              x-text="fileName || @js(__('plugins.dropzone'))">{{ __('plugins.dropzone') }}</span>
                                        <span class="img-upload-empty-sub">{{ __('plugins.dropzone_hint') }}</span>
                                    </span>
                                </button>
                            </div>
                            @error('plugin') <div class="field-error mt-1">{{ $message }}</div> @enderror
                        </div>
                        <div class="field">
                            <label for="plugin-current-password" class="form-label">{{ __('plugins.current_password') }}</label>
                            <x-admin.password-input id="plugin-current-password" name="current_password" required autocomplete="current-password" :disabled="pos_is_demo() || ! $platformReady" />
                            <div class="field-help mt-1">{{ __('plugins.current_password_help') }}</div>
                            @error('current_password') <div class="field-error mt-1">{{ $message }}</div> @enderror
                        </div>
                        <button class="pos-btn pos-btn-sm pos-btn-primary w-full" @disabled(pos_is_demo() || ! $platformReady)>
                            <x-icon name="upload" class="w-4 h-4" /> {{ __('plugins.actions.install') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
