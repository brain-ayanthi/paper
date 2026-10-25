<x-admin-layout
    active="settings"
    :title="__('settings.locations.title')"
    :crumbs="[
        ['label' => __('settings.crumb_parent')],
        ['label' => __('settings.title'), 'href' => route('admin.settings.index')],
        ['label' => __('settings.locations.title')],
    ]">

    @php
        // Current configuration, keyed for easy lookup. `old()` wins on a
        // validation-error reload so nothing the owner typed is lost.
        $fields = collect($company->locationFields())->keyBy('key');

        $fieldVal = function (string $key, string $col) use ($fields) {
            $oldKey = "fields.{$key}.{$col}";
            if (old($oldKey) !== null) return old($oldKey);

            // `label` is shown as a placeholder-style default: we only
            // pre-fill the box when the owner actually overrode it, so
            // clearing it goes back to the translated name.
            $row = $fields->get($key);
            if (! $row) return $col === 'enabled' ? false : '';

            return $col === 'enabled' ? $row['enabled'] : '';
        };
    @endphp

    <div class="page-wide">
        {{-- AJAX submit via lib/ajax-form.js. --}}
        <form method="POST" action="{{ route('admin.settings.locations.update') }}" data-ajax-form>
            @csrf
            @method('PATCH')

            <div class="page-header mb-6">
                <div class="flex items-start gap-3">
                    <a href="{{ route('admin.settings.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('settings.title') }}">
                        <x-icon name="back" class="w-4 h-4" />
                    </a>
                    <div>
                        <h1 class="page-title">{{ __('settings.locations.title') }}</h1>
                        <p class="page-sub">{{ __('settings.locations.sub') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                        <x-icon name="check" class="w-4 h-4" />
                        {{ __('settings.locations.actions.save') }}
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
                {{-- The four coordinate fields --}}
                <div class="card">
                    <div class="card-header"><div>
                        <div class="card-title">{{ __('settings.locations.sections.fields') }}</div>
                        <div class="card-title-sub">{{ __('settings.locations.sections.fields_sub') }}</div>
                    </div></div>
                    <div class="card-body">
                        <div class="form-stack">
                            @foreach ($coordinates as $key)
                                <div class="loc-field-row">
                                    <label class="field-toggle">
                                        <input type="hidden" name="fields[{{ $key }}][enabled]" value="0">
                                        <input type="checkbox"
                                               name="fields[{{ $key }}][enabled]"
                                               value="1"
                                               @checked($fieldVal($key, 'enabled'))>
                                        <span>{{ __('products.locations.fields.'.$key) }}</span>
                                    </label>

                                    <label class="field loc-field-label">
                                        <span class="field-label">{{ __('settings.locations.fields.rename') }}</span>
                                        <input type="text"
                                               class="pos-input"
                                               name="fields[{{ $key }}][label]"
                                               autocomplete="off"
                                               value="{{ $fieldVal($key, 'label') }}"
                                               placeholder="{{ __('products.locations.fields.'.$key) }}">
                                    </label>
                                </div>
                            @endforeach
                        </div>

                        <p class="field-help mt-4">{{ __('settings.locations.fields.disable_help') }}</p>
                    </div>
                </div>

                {{-- What the kinds mean — read-only reference so the owner
                     knows what they're picking in the product editor. --}}
                <div class="card">
                    <div class="card-header"><div>
                        <div class="card-title">{{ __('settings.locations.sections.kinds') }}</div>
                        <div class="card-title-sub">{{ __('settings.locations.sections.kinds_sub') }}</div>
                    </div></div>
                    <div class="card-body">
                        <dl class="loc-kind-list">
                            @foreach ($kinds as $kind)
                                <div class="loc-kind-item">
                                    <dt>{{ __('products.locations.kinds.'.$kind) }}</dt>
                                    <dd>{{ __('settings.locations.kind_help.'.$kind) }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        <x-alert type="info" class="mt-4">
                            {{ __('settings.locations.no_quantities') }}
                        </x-alert>
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-admin-layout>
