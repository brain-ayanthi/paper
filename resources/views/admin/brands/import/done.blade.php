<x-admin-layout
    active="brands"
    :title="__('brands.import.done_title')"
    :crumbs="[
        ['label' => __('brands.crumb_parent')],
        ['label' => __('brands.title'), 'href' => route('admin.brands.index')],
        ['label' => __('brands.import.title'), 'href' => route('admin.brands.import')],
        ['label' => __('brands.import.done_title')],
    ]">

    <div class="page-wide">
        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">{{ __('brands.import.done_title') }}</h1>
                <p class="page-sub">
                    {{ __('brands.import.done_sub', [
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
                        <div class="prod-import-count-label">{{ __('brands.import.count_created') }}</div>
                    </div>
                    <div class="prod-import-count">
                        <div class="prod-import-count-value">{{ $summary['updated'] }}</div>
                        <div class="prod-import-count-label">{{ __('brands.import.count_updated') }}</div>
                    </div>
                    <div class="prod-import-count is-warning">
                        <div class="prod-import-count-value">{{ $summary['skipped'] }}</div>
                        <div class="prod-import-count-label">{{ __('brands.import.count_skipped') }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- The half of the import that still needs doing. A sheet names
             logo files but can't carry them, so send the admin straight to
             the uploader rather than leaving them to find it. --}}
        @if (($summary['logos'] ?? 0) > 0)
            <div class="card mb-5">
                <div class="card-body">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="card-title">{{ __('brands.import.logos_title') }}</div>
                            <p class="card-title-sub">
                                {{ __('brands.import.logos_sub', ['count' => $summary['logos']]) }}
                            </p>
                        </div>
                        {{-- If the sheet carried links rather than filenames,
                             go straight to the tab that can act on them. --}}
                        <a href="{{ route('admin.brands.bulk-logos', ($pendingLogoUrls ?? 0) > 0 ? ['tab' => 'urls'] : []) }}"
                           class="pos-btn pos-btn-sm pos-btn-primary shrink-0">
                            <x-icon name="{{ ($pendingLogoUrls ?? 0) > 0 ? 'link' : 'image' }}" class="w-4 h-4" />
                            {{ __('brands.import.logos_button') }}
                        </a>
                    </div>
                </div>
            </div>
        @endif

        @if (!empty($summary['errors']))
            <div class="card mb-5">
                <div class="card-header">
                    <div>
                        <div class="card-title">{{ __('brands.import.errors_heading') }} ({{ count($summary['errors']) }})</div>
                    </div>
                </div>
                <div class="card-body">
                    <ul class="prod-import-errors">
                        @foreach ($summary['errors'] as $err)
                            <li>
                                <span class="prod-import-error-row">{{ __('brands.import.error_row', ['row' => $err['row']]) }}</span>
                                <span>{{ $err['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('admin.brands.import') }}"
               class="pos-btn pos-btn-sm pos-btn-ghost">
                {{ __('brands.import.title') }}
            </a>
            <a href="{{ route('admin.brands.index') }}"
               class="pos-btn pos-btn-sm pos-btn-primary">
                {{ __('brands.import.done_back') }}
            </a>
        </div>
    </div>
</x-admin-layout>
