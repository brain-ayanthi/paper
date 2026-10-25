<x-admin-layout
    active="customers"
    :title="__('customers.import.done_title')"
    :crumbs="[
        ['label' => __('customers.crumb_parent')],
        ['label' => __('customers.title'), 'href' => route('admin.customers.index')],
        ['label' => __('customers.import.title'), 'href' => route('admin.customers.import')],
        ['label' => __('customers.import.done_title')],
    ]">

    <div class="page-wide">
        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">{{ __('customers.import.done_title') }}</h1>
                <p class="page-sub">
                    {{ __('customers.import.done_sub', [
                        'created' => $summary['created'],
                        'updated' => $summary['updated'],
                        'skipped' => $summary['skipped'],
                    ]) }}
                </p>
            </div>
        </div>

        <div class="card mb-5">
            <div class="card-body">
                <div class="prod-import-counts">
                    <div class="prod-import-count is-positive">
                        <div class="prod-import-count-value">{{ $summary['created'] }}</div>
                        <div class="prod-import-count-label">{{ __('customers.import.count_created') }}</div>
                    </div>
                    <div class="prod-import-count">
                        <div class="prod-import-count-value">{{ $summary['updated'] }}</div>
                        <div class="prod-import-count-label">{{ __('customers.import.count_updated') }}</div>
                    </div>
                    <div class="prod-import-count is-warning">
                        <div class="prod-import-count-value">{{ $summary['skipped'] }}</div>
                        <div class="prod-import-count-label">{{ __('customers.import.count_skipped') }}</div>
                    </div>
                </div>
            </div>
        </div>

        @if (! empty($summary['errors']))
            <div class="card mb-5">
                <div class="card-header">
                    <div class="card-title">
                        {{ __('customers.import.errors_heading') }} ({{ count($summary['errors']) }})
                    </div>
                </div>
                <div class="card-body">
                    <ul class="prod-import-errors">
                        @foreach ($summary['errors'] as $error)
                            <li>
                                <span class="prod-import-error-row">
                                    {{ __('customers.import.error_row', ['row' => $error['row']]) }}
                                </span>
                                <span>{{ $error['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('admin.customers.import') }}"
               class="pos-btn pos-btn-sm pos-btn-ghost">
                {{ __('customers.import.title') }}
            </a>
            <a href="{{ route('admin.customers.index') }}"
               class="pos-btn pos-btn-sm pos-btn-primary">
                {{ __('customers.import.done_back') }}
            </a>
        </div>
    </div>
</x-admin-layout>
