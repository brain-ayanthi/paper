<div x-show="errors.length" x-cloak class="alert alert-danger" role="alert" aria-live="polite">
    <span class="alert-icon"><x-icon name="alert" class="w-5 h-5" /></span>
    <div class="alert-body">
        <div class="alert-title">{{ __('installer.error_heading') }}</div>
        <ul class="alert-msg list-disc ps-5">
            <template x-for="(message, index) in errors" :key="index"><li x-text="message"></li></template>
        </ul>
    </div>
</div>
