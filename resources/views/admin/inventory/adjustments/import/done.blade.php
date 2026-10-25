<x-admin-layout active="stock-adjustments" :title="__('inventory.adjustments.import.done_title')">
    <div class="page-wide">
        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">{{ __('inventory.adjustments.import.done_title') }}</h1>
                <p class="page-sub">{{ __('inventory.adjustments.import.done_sub', ['imported' => $summary['imported'], 'skipped' => $summary['skipped']]) }}</p>
            </div>
        </div>

        @if (! $summary['adjustment'])
            <x-alert type="warning" class="mb-5">{{ __('inventory.adjustments.import.no_draft') }}</x-alert>
        @endif

        <div class="card mb-5"><div class="card-body"><div class="prod-import-counts">
            <div class="prod-import-count is-positive"><div class="prod-import-count-value">{{ $summary['imported'] }}</div><div class="prod-import-count-label">{{ __('inventory.adjustments.import.count_imported') }}</div></div>
            <div class="prod-import-count is-warning"><div class="prod-import-count-value">{{ $summary['skipped'] }}</div><div class="prod-import-count-label">{{ __('inventory.adjustments.import.count_skipped') }}</div></div>
        </div></div></div>

        @if ($summary['errors'])
            <div class="card mb-5">
                <div class="card-header"><div class="card-title">{{ __('inventory.adjustments.import.errors_heading') }} ({{ count($summary['errors']) }})</div></div>
                <div class="card-body"><ul class="prod-import-errors">
                    @foreach ($summary['errors'] as $error)
                        <li><span class="prod-import-error-row">{{ __('inventory.adjustments.import.error_row', ['row' => $error['row']]) }}</span><span>{{ $error['message'] }}</span></li>
                    @endforeach
                </ul></div>
            </div>
        @endif

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('admin.inventory.adjustments.import') }}" class="pos-btn pos-btn-sm pos-btn-ghost">{{ __('inventory.adjustments.import.import_again') }}</a>
            @if ($summary['adjustment'])
                <a href="{{ route('admin.inventory.adjustments.edit', $summary['adjustment']) }}" class="pos-btn pos-btn-sm pos-btn-primary">{{ __('inventory.adjustments.import.open_draft') }}</a>
            @endif
        </div>
    </div>
</x-admin-layout>
