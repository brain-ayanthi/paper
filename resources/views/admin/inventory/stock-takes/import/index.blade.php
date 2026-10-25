<x-admin-layout
    active="stock-takes"
    :title="__('stock_takes.import.title')"
    :crumbs="[
        ['label' => __('stock_takes.crumb_parent')],
        ['label' => __('stock_takes.title'), 'href' => route('admin.inventory.stock-takes.index')],
        ['label' => __('stock_takes.import.title')],
    ]">

    <div class="page-wide">
        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.inventory.stock-takes.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost"
                   aria-label="{{ __('stock_takes.import.back') }}"><x-icon name="back" class="w-4 h-4" /></a>
                <div>
                    <h1 class="page-title">{{ __('stock_takes.import.title') }}</h1>
                    <p class="page-sub">{{ __('stock_takes.import.sub') }}</p>
                </div>
            </div>
        </div>

        @if (session('error'))
            <x-alert type="danger" class="mb-4">{{ session('error') }}</x-alert>
        @endif

        <div class="card mb-4">
            <div class="card-body">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><div class="card-title">{{ __('stock_takes.import.sample_title') }}</div><p class="card-title-sub">{{ __('stock_takes.import.sample_sub') }}</p></div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('admin.inventory.stock-takes.import.template', ['format' => 'csv']) }}" class="pos-btn pos-btn-sm pos-btn-ghost">
                            <x-icon name="download" class="w-4 h-4" />{{ __('stock_takes.import.sample_csv') }}
                        </a>
                        <a href="{{ route('admin.inventory.stock-takes.import.template', ['format' => 'xlsx']) }}" class="pos-btn pos-btn-sm pos-btn-ghost">
                            <x-icon name="download" class="w-4 h-4" />{{ __('stock_takes.import.sample_xlsx') }}
                        </a>
                    </div>
                </div>
                <div class="import-guide">
                    <div class="import-guide-title">{{ __('stock_takes.import.guide_title') }}</div>
                    <ul class="import-guide-list">
                        <li>{{ __('stock_takes.import.guide_count') }}</li>
                        <li>{{ __('stock_takes.import.guide_document') }}</li>
                        <li>{{ __('stock_takes.import.guide_unlisted') }}</li>
                        <li>{{ __('stock_takes.import.guide_levels') }}</li>
                        <li>{{ __('stock_takes.import.guide_draft') }}</li>
                        <li>{{ __('stock_takes.import.guide_batch') }}</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card"><div class="card-body">
            <form method="POST" action="{{ route('admin.inventory.stock-takes.import.preview') }}" enctype="multipart/form-data">
                @csrf
                <div class="form-stack">
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="field">
                            <span class="field-label is-required">{{ __('stock_takes.fields.store') }}</span>
                            <select name="store_id" class="pos-input" x-data="enhancedSelect()" required>
                                @foreach ($stores as $store)
                                    <option value="{{ $store->id }}" @selected((int) old('store_id', $defaultStoreId) === $store->id)>{{ $store->name }}</option>
                                @endforeach
                            </select>
                            @error('store_id')<p class="field-error">{{ $message }}</p>@enderror
                        </label>
                        <label class="field">
                            <span class="field-label is-required">{{ __('stock_takes.fields.take_date') }}</span>
                            <input type="text" name="take_date" value="{{ old('take_date', now()->toDateString()) }}" class="pos-input js-datepicker" placeholder="YYYY-MM-DD" required>
                            @error('take_date')<p class="field-error">{{ $message }}</p>@enderror
                        </label>
                        <label class="field md:col-span-2">
                            <span class="field-label">{{ __('stock_takes.fields.name') }}</span>
                            <input type="text" name="name" value="{{ old('name') }}" class="pos-input" maxlength="191" placeholder="{{ __('stock_takes.fields.name_placeholder') }}">
                            @error('name')<p class="field-error">{{ $message }}</p>@enderror
                        </label>
                    </div>
                    <label class="field">
                        <span class="field-label">{{ __('stock_takes.fields.notes') }}</span>
                        <textarea name="notes" class="pos-input" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
                        @error('notes')<p class="field-error">{{ $message }}</p>@enderror
                    </label>
                    <label class="field">
                        <span class="field-label is-required">{{ __('stock_takes.import.upload_label') }}</span>
                        <x-admin.file-upload name="file" accept=".csv,.xlsx" :max-size-kb="5120" :hint="__('stock_takes.import.upload_hint')" />
                        @error('file')<p class="field-error">{{ $message }}</p>@enderror
                    </label>
                    <div class="flex items-center justify-end gap-2">
                        <a href="{{ route('admin.inventory.stock-takes.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost">{{ __('stock_takes.import.cancel') }}</a>
                        <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary"><x-icon name="upload" class="w-4 h-4" />{{ __('stock_takes.import.upload_button') }}</button>
                    </div>
                </div>
            </form>
        </div></div>
    </div>
</x-admin-layout>
