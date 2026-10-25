<x-admin-layout
    active="stock-adjustments"
    :title="__('inventory.adjustments.import.review_title')"
    :crumbs="[
        ['label' => __('inventory.adjustments.crumb_parent')],
        ['label' => __('inventory.adjustments.title'), 'href' => route('admin.inventory.adjustments.index')],
        ['label' => __('inventory.adjustments.import.title'), 'href' => route('admin.inventory.adjustments.import')],
        ['label' => __('inventory.adjustments.import.review_title')],
    ]">

    <div class="page-wide">
        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.inventory.adjustments.import') }}" class="pos-btn pos-btn-sm pos-btn-ghost"
                   aria-label="{{ __('inventory.adjustments.import.back') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">{{ __('inventory.adjustments.import.review_title') }}</h1>
                    <p class="page-sub">{{ __('inventory.adjustments.import.review_sub') }}</p>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.inventory.adjustments.import.commit') }}">
            @csrf
            <input type="hidden" name="staged_path" value="{{ $stagedPath }}">
            @foreach ($document as $field => $value)
                <input type="hidden" name="{{ $field }}" value="{{ $value }}">
            @endforeach

            <div class="card mb-5">
                <div class="card-body grid gap-4 md:grid-cols-3">
                    <div><div class="field-label">{{ __('inventory.adjustments.fields.store') }}</div><div>{{ $documentStore->name }}</div></div>
                    <div><div class="field-label">{{ __('inventory.adjustments.fields.adjustment_date') }}</div><div class="tnum">{{ $document['adjustment_date'] }}</div></div>
                    <div><div class="field-label">{{ __('inventory.adjustments.fields.reason_code') }}</div><div>{{ $documentReason?->name ?? $document['reason'] ?? __('inventory.adjustments.fields.reason_code_ph') }}</div></div>
                </div>
            </div>

            <div class="card mb-5">
                <div class="card-body">
                    <table class="prod-import-map">
                        <thead><tr><th>{{ __('inventory.adjustments.import.col_header') }}</th><th>{{ __('inventory.adjustments.import.col_maps_to') }}</th></tr></thead>
                        <tbody>
                            @foreach ($preview['headers'] as $i => $header)
                                <tr>
                                    <td class="prod-import-header">{{ $header !== '' ? $header : __('inventory.adjustments.import.unnamed_column', ['number' => $i + 1]) }}</td>
                                    <td>
                                        <select name="mapping[{{ $i }}]" class="pos-input" x-data="enhancedSelect()">
                                            <option value="">{{ __('inventory.adjustments.import.ignore_column') }}</option>
                                            @foreach ($targetFields as $field)
                                                <option value="{{ $field }}" @selected(($preview['suggested_map'][$header] ?? null) === $field)>
                                                    {{ __("inventory.adjustments.import.fields.{$field}") }}
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
                <div class="card-header"><div class="card-title">{{ __('inventory.adjustments.import.preview_table', ['count' => count($preview['rows'])]) }}</div></div>
                <div class="card-body">
                    @if (empty($preview['rows']))
                        <p class="fg-tertiary">{{ __('inventory.adjustments.import.no_data') }}</p>
                    @else
                        <div class="prod-import-preview-wrap">
                            <table class="prod-import-preview">
                                <thead><tr>@foreach ($preview['headers'] as $i => $header)<th>{{ $header !== '' ? $header : __('inventory.adjustments.import.unnamed_column', ['number' => $i + 1]) }}</th>@endforeach</tr></thead>
                                <tbody>
                                    @foreach ($preview['rows'] as $row)
                                        <tr>@for ($i = 0; $i < $preview['total_columns']; $i++)<td>{{ $row[$i] ?? '' }}</td>@endfor</tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('admin.inventory.adjustments.import') }}" class="pos-btn pos-btn-sm pos-btn-ghost">{{ __('inventory.adjustments.import.cancel') }}</a>
                <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                    <x-icon name="check" class="w-4 h-4" />
                    {{ __('inventory.adjustments.import.commit_button') }}
                </button>
            </div>
        </form>
    </div>
</x-admin-layout>
