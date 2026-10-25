<x-admin-layout
    active="customers"
    :title="__('customers.import.title')"
    :crumbs="[
        ['label' => __('customers.crumb_parent')],
        ['label' => __('customers.title'), 'href' => route('admin.customers.index')],
        ['label' => __('customers.import.title'), 'href' => route('admin.customers.import')],
        ['label' => __('customers.import.review_title')],
    ]">

    <div class="page-wide">
        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.customers.import') }}"
                   class="pos-btn pos-btn-sm pos-btn-ghost"
                   aria-label="{{ __('customers.import.back') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">{{ __('customers.import.review_title') }}</h1>
                    <p class="page-sub">{{ __('customers.import.review_sub') }}</p>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.customers.import.commit') }}">
            @csrf
            <input type="hidden" name="staged_path" value="{{ $stagedPath }}">

            <div class="card mb-5">
                <div class="card-body">
                    <table class="prod-import-map">
                        <thead>
                            <tr>
                                <th>{{ __('customers.import.col_header') }}</th>
                                <th>{{ __('customers.import.col_maps_to') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview['headers'] as $i => $header)
                                <tr>
                                    <td class="prod-import-header">
                                        {{ $header !== '' ? $header : __('customers.import.unnamed_column', ['number' => $i + 1]) }}
                                    </td>
                                    <td>
                                        <select name="mapping[{{ $i }}]" class="pos-input" x-data="enhancedSelect()">
                                            <option value="">{{ __('customers.import.ignore_column') }}</option>
                                            @foreach ($targetFields as $field)
                                                <option value="{{ $field }}"
                                                    @selected(($preview['suggested_map'][$header] ?? null) === $field)>
                                                    {{ __("customers.import.fields.{$field}") }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mb-5">
                <div class="card-header">
                    <div class="card-title">
                        {{ __('customers.import.preview_table', ['count' => count($preview['rows'])]) }}
                    </div>
                </div>
                <div class="card-body">
                    @if (empty($preview['rows']))
                        <p class="fg-tertiary">{{ __('customers.import.no_data') }}</p>
                    @else
                        <div class="prod-import-preview-wrap">
                            <table class="prod-import-preview">
                                <thead>
                                    <tr>
                                        @foreach ($preview['headers'] as $i => $header)
                                            <th>{{ $header !== '' ? $header : __('customers.import.unnamed_column', ['number' => $i + 1]) }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($preview['rows'] as $row)
                                        <tr>
                                            @for ($i = 0; $i < $preview['total_columns']; $i++)
                                                <td>{{ $row[$i] ?? '' }}</td>
                                            @endfor
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('admin.customers.import') }}"
                   class="pos-btn pos-btn-sm pos-btn-ghost">
                    {{ __('customers.import.cancel') }}
                </a>
                <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                    <x-icon name="check" class="w-4 h-4" />
                    {{ __('customers.import.commit_button') }}
                </button>
            </div>
        </form>
    </div>
</x-admin-layout>
