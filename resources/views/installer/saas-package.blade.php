@extends('installer.layout')

@section('content')
    <h2 class="text-[22px] font-semibold fg-primary tracking-[-0.01em]">{{ __('installer.saas_package.heading') }}</h2>
    <p class="mt-2 text-[13.5px] fg-secondary leading-relaxed">{{ __('installer.saas_package.subheading') }}</p>

    <form method="POST" action="{{ route('install.saas.package.store') }}"
          enctype="multipart/form-data" data-ajax-form novalidate
          data-chunk-url="{{ route('install.saas.package.chunk') }}"
          class="mt-8 space-y-6"
          x-data="saasPackageForm({ genericError: @js(__('installer.ajax.failed')), invalidFile: @js(__('installer.saas_package.invalid_file')), tooLarge: @js(__('installer.saas_package.too_large')), rateLimited: @js(__('installer.saas_package.rate_limited')) })"
          @submit.prevent="submit($event)">
        @csrf
        @include('installer.partials.ajax-errors')

        <label class="block">
            <span class="field-label">{{ __('installer.saas_package.file_label') }}</span>
            <span class="mt-2 flex min-h-[150px] cursor-pointer flex-col items-center justify-center rounded-[12px] border-2 border-dashed px-5 py-6 text-center transition-colors"
                  :class="dragging ? 'border-orange-500 bg-orange-50 dark:bg-orange-950/20' : 'border-gray-200 dark:border-gray-700'"
                  @dragover.prevent="dragging = true"
                  @dragleave.prevent="dragging = false"
                  @drop.prevent="dragging = false; $refs.delivery.files = $event.dataTransfer.files; fileName = $refs.delivery.files[0]?.name || ''">
                <svg class="mb-3 h-7 w-7 fg-tertiary" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true"><path d="M12 16V4m0 0L8 8m4-4 4 4"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
                <span class="text-[14px] font-medium fg-primary" x-text="fileName || @js(__('installer.saas_package.drop_hint'))"></span>
                <span class="mt-1 text-[12px] fg-tertiary">{{ __('installer.saas_package.browse_hint') }}</span>
                <input x-ref="delivery" type="file" name="delivery" accept=".zip,application/zip,application/x-zip-compressed"
                       required class="sr-only"
                       @change="fileName = $event.target.files[0]?.name || ''">
            </span>
        </label>
        <p class="text-[12px] fg-tertiary">{{ __('installer.saas_package.verify_hint') }}</p>
        <p x-show="submitting" x-cloak class="text-[12px] fg-secondary" role="status" x-text="@js(__('installer.saas_package.upload_progress')).replace(':percent', progress)"></p>

        <div class="flex items-center justify-between gap-4">
            <a href="{{ route('install.mode') }}" class="text-[13px] fg-secondary hover:underline">{{ __('installer.saas_package.back') }}</a>
            <x-installer.submit-button>{{ __('installer.saas_package.continue') }}</x-installer.submit-button>
        </div>
    </form>
@endsection
