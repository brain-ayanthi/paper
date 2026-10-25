<x-admin-layout
    active="brands"
    :title="__('brands.import.title')"
    :crumbs="[
        ['label' => __('brands.crumb_parent')],
        ['label' => __('brands.title'), 'href' => route('admin.brands.index')],
        ['label' => __('brands.import.title'), 'href' => route('admin.brands.import')],
        ['label' => __('brands.import.review_title')],
    ]">

    <div class="page-wide">
        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.brands.import') }}"
                   class="pos-btn pos-btn-sm pos-btn-ghost"
                   aria-label="{{ __('brands.import.back') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">{{ __('brands.import.review_title') }}</h1>
                    <p class="page-sub">{{ __('brands.import.review_sub') }}</p>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.brands.import.commit') }}">
            @csrf
            <input type="hidden" name="staged_path" value="{{ $stagedPath }}">

            <div class="card mb-5">
                <div class="card-body">
                    <table class="prod-import-map">
                        <thead>
                            <tr>
                                <th>{{ __('brands.import.col_header') }}</th>
                                <th>{{ __('brands.import.col_maps_to') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview['headers'] as $i => $header)
                                <tr>
                                    <td class="prod-import-header">{{ $header !== '' ? $header : 'Column '.($i + 1) }}</td>
                                    <td>
                                        <select name="mapping[{{ $i }}]" class="pos-input" x-data="enhancedSelect()">
                                            <option value="">{{ __('brands.import.ignore_column') }}</option>
                                            @foreach ($targetFields as $field)
                                                <option value="{{ $field }}"
                                                    @selected(($preview['suggested_map'][$header] ?? null) === $field)>
                                                    {{ __("brands.import.fields.{$field}") }}
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
                    <div>
                        <div class="card-title">
                            {{ __('brands.import.preview_table', ['count' => count($preview['rows'])]) }}
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if (empty($preview['rows']))
                        <p class="fg-tertiary">{{ __('brands.import.no_data') }}</p>
                    @else
                        <div class="prod-import-preview-wrap">
                            <table class="prod-import-preview">
                                <thead>
                                    <tr>
                                        @foreach ($preview['headers'] as $h)
                                            <th>{{ $h }}</th>
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
                <a href="{{ route('admin.brands.import') }}"
                   class="pos-btn pos-btn-sm pos-btn-ghost">
                    {{ __('brands.import.cancel') }}
                </a>
                <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                    <x-icon name="check" class="w-4 h-4" />
                    {{ __('brands.import.commit_button') }}
                </button>
            </div>
        </form>
    </div>
</x-admin-layout>
