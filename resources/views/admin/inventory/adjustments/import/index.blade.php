<x-admin-layout
    active="stock-adjustments"
    :title="__('inventory.adjustments.import.title')"
    :crumbs="[
        ['label' => __('inventory.adjustments.crumb_parent')],
        ['label' => __('inventory.adjustments.title'), 'href' => route('admin.inventory.adjustments.index')],
        ['label' => __('inventory.adjustments.import.title')],
    ]">

    <div class="page-wide">
        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.inventory.adjustments.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost"
                   aria-label="{{ __('inventory.adjustments.import.back') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">{{ __('inventory.adjustments.import.title') }}</h1>
                    <p class="page-sub">{{ __('inventory.adjustments.import.sub') }}</p>
                </div>
            </div>
        </div>

        @if (session('error'))
            <x-alert type="danger" class="mb-4">{{ session('error') }}</x-alert>
        @endif

        <div class="card mb-4">
            <div class="card-body">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="card-title">{{ __('inventory.adjustments.import.sample_title') }}</div>
                        <p class="card-title-sub">{{ __('inventory.adjustments.import.sample_sub') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('admin.inventory.adjustments.import.template', ['format' => 'csv']) }}" class="pos-btn pos-btn-sm pos-btn-ghost">
                            <x-icon name="download" class="w-4 h-4" />
                            {{ __('inventory.adjustments.import.sample_csv') }}
                        </a>
                        <a href="{{ route('admin.inventory.adjustments.import.template', ['format' => 'xlsx']) }}" class="pos-btn pos-btn-sm pos-btn-ghost">
                            <x-icon name="download" class="w-4 h-4" />
                            {{ __('inventory.adjustments.import.sample_xlsx') }}
                        </a>
                    </div>
                </div>
                <div class="import-guide">
                    <div class="import-guide-title">{{ __('inventory.adjustments.import.guide_title') }}</div>
                    <ul class="import-guide-list">
                        <li>{{ __('inventory.adjustments.import.guide_document') }}</li>
                        <li>{{ __('inventory.adjustments.import.guide_direction') }}</li>
                        <li>{{ __('inventory.adjustments.import.guide_draft') }}</li>
                        <li>{{ __('inventory.adjustments.import.guide_batch') }}</li>
                        <li>{{ __('inventory.adjustments.import.guide_cost') }}</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.inventory.adjustments.import.preview') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="form-stack">
                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="field">
                                <span class="field-label is-required">{{ __('inventory.adjustments.fields.store') }}</span>
                                <select name="store_id" class="pos-input" x-data="enhancedSelect()" required>
                                    @foreach ($stores as $store)
                                        <option value="{{ $store->id }}" @selected((int) old('store_id', $defaultStoreId) === $store->id)>{{ $store->name }}</option>
                                    @endforeach
                                </select>
                                @error('store_id')<p class="field-error">{{ $message }}</p>@enderror
                            </label>
                            <label class="field">
                                <span class="field-label is-required">{{ __('inventory.adjustments.fields.adjustment_date') }}</span>
                                <input type="text" name="adjustment_date" value="{{ old('adjustment_date', now()->toDateString()) }}"
                                       class="pos-input js-datepicker" placeholder="YYYY-MM-DD" required>
                                @error('adjustment_date')<p class="field-error">{{ $message }}</p>@enderror
                            </label>
                            <label class="field">
                                <span class="field-label">{{ __('inventory.adjustments.fields.reason_code') }}</span>
                                <select name="reason_code_id" class="pos-input" x-data="enhancedSelect()">
                                    <option value="">{{ __('inventory.adjustments.fields.reason_code_ph') }}</option>
                                    @foreach ($reasons as $reason)
                                        <option value="{{ $reason->id }}" @selected((int) old('reason_code_id') === $reason->id)>{{ $reason->name }}</option>
                                    @endforeach
                                </select>
                                @error('reason_code_id')<p class="field-error">{{ $message }}</p>@enderror
                            </label>
                            <label class="field">
                                <span class="field-label">{{ __('inventory.adjustments.fields.reason') }}</span>
                                <input type="text" name="reason" value="{{ old('reason') }}" class="pos-input" maxlength="191">
                                @error('reason')<p class="field-error">{{ $message }}</p>@enderror
                            </label>
                        </div>

                        <label class="field">
                            <span class="field-label">{{ __('inventory.adjustments.fields.notes') }}</span>
                            <textarea name="notes" class="pos-input" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
                            @error('notes')<p class="field-error">{{ $message }}</p>@enderror
                        </label>

                        <label class="field">
                            <span class="field-label is-required">{{ __('inventory.adjustments.import.upload_label') }}</span>
                            <x-admin.file-upload name="file" accept=".csv,.xlsx" :max-size-kb="5120"
                                :hint="__('inventory.adjustments.import.upload_hint')" />
                            @error('file')<p class="field-error">{{ $message }}</p>@enderror
                        </label>

                        <div class="flex items-center justify-end gap-2">
                            <a href="{{ route('admin.inventory.adjustments.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost">
                                {{ __('inventory.adjustments.import.cancel') }}
                            </a>
                            <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                                <x-icon name="upload" class="w-4 h-4" />
                                {{ __('inventory.adjustments.import.upload_button') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-admin-layout>
