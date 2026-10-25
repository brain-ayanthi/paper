<x-admin-layout active="stock-takes" :title="__('stock_takes.import.done_title')">
    <div class="page-wide">
        <div class="page-header mb-6"><div>
            <h1 class="page-title">{{ __('stock_takes.import.done_title') }}</h1>
            <p class="page-sub">{{ __('stock_takes.import.done_sub', ['imported' => $summary['imported'], 'skipped' => $summary['skipped']]) }}</p>
        </div></div>

        @if (! $summary['take'])<x-alert type="warning" class="mb-5">{{ __('stock_takes.import.no_draft') }}</x-alert>@endif

        <div class="card mb-5"><div class="card-body"><div class="prod-import-counts">
            <div class="prod-import-count is-positive"><div class="prod-import-count-value">{{ $summary['imported'] }}</div><div class="prod-import-count-label">{{ __('stock_takes.import.count_imported') }}</div></div>
            <div class="prod-import-count is-warning"><div class="prod-import-count-value">{{ $summary['skipped'] }}</div><div class="prod-import-count-label">{{ __('stock_takes.import.count_skipped') }}</div></div>
        </div></div></div>

        @if ($summary['errors'])
            <div class="card mb-5">
                <div class="card-header"><div class="card-title">{{ __('stock_takes.import.errors_heading') }} ({{ count($summary['errors']) }})</div></div>
                <div class="card-body"><ul class="prod-import-errors">
                    @foreach ($summary['errors'] as $error)
                        <li><span class="prod-import-error-row">{{ __('stock_takes.import.error_row', ['row' => $error['row']]) }}</span><span>{{ $error['message'] }}</span></li>
                    @endforeach
                </ul></div>
            </div>
        @endif

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('admin.inventory.stock-takes.import') }}" class="pos-btn pos-btn-sm pos-btn-ghost">{{ __('stock_takes.import.import_again') }}</a>
            @if ($summary['take'])<a href="{{ route('admin.inventory.stock-takes.edit', $summary['take']) }}" class="pos-btn pos-btn-sm pos-btn-primary">{{ __('stock_takes.import.open_draft') }}</a>@endif
        </div>
    </div>
</x-admin-layout>
