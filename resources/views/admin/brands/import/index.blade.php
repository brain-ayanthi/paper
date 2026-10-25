<x-admin-layout
    active="brands"
    :title="__('brands.import.title')"
    :crumbs="[
        ['label' => __('brands.crumb_parent')],
        ['label' => __('brands.title'), 'href' => route('admin.brands.index')],
        ['label' => __('brands.import.title')],
    ]">

    <div class="page-wide">
        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.brands.index') }}"
                   class="pos-btn pos-btn-sm pos-btn-ghost"
                   aria-label="{{ __('brands.import.back') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">{{ __('brands.import.title') }}</h1>
                    <p class="page-sub">{{ __('brands.import.sub') }}</p>
                </div>
            </div>
        </div>

        @if (session('error'))
            <x-alert type="danger" class="mb-4">{{ session('error') }}</x-alert>
        @endif

        {{-- Sample template + format guidance. --}}
        <div class="card mb-4">
            <div class="card-body">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="card-title">{{ __('brands.import.sample_title') }}</div>
                        <p class="card-title-sub">{{ __('brands.import.sample_sub') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                        <a href="{{ route('admin.brands.import.template', ['format' => 'csv']) }}"
                           class="pos-btn pos-btn-sm pos-btn-ghost">
                            <x-icon name="download" class="w-4 h-4" />
                            {{ __('brands.import.sample_csv') }}
                        </a>
                        <a href="{{ route('admin.brands.import.template', ['format' => 'xlsx']) }}"
                           class="pos-btn pos-btn-sm pos-btn-ghost">
                            <x-icon name="download" class="w-4 h-4" />
                            {{ __('brands.import.sample_xlsx') }}
                        </a>
                    </div>
                </div>

                <div class="import-guide">
                    <div class="import-guide-title">{{ __('brands.import.guide_title') }}</div>
                    <ul class="import-guide-list">
                        <li>{{ __('brands.import.guide_required') }}</li>
                        <li>{{ __('brands.import.guide_identity') }}</li>
                        <li>{{ __('brands.import.guide_logo') }}</li>
                        <li>{{ __('brands.import.guide_bool') }}</li>
                        <li>{{ __('brands.import.guide_extra') }}</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST"
                      action="{{ route('admin.brands.import.preview') }}"
                      enctype="multipart/form-data">
                    @csrf
                    <div class="form-stack">
                        <label class="field">
                            <span class="field-label is-required">{{ __('brands.import.upload_label') }}</span>
                            <x-admin.file-upload
                                name="file"
                                accept=".csv,.xlsx"
                                :max-size-kb="5120"
                                :hint="__('brands.import.upload_hint')" />
                            @error('file')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </label>

                        <div class="flex items-center justify-end gap-2">
                            <a href="{{ route('admin.brands.index') }}"
                               class="pos-btn pos-btn-sm pos-btn-ghost">
                                {{ __('brands.import.cancel') }}
                            </a>
                            <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                                <x-icon name="upload" class="w-4 h-4" />
                                {{ __('brands.import.upload_button') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-admin-layout>
