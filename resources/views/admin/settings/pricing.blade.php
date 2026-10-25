<x-admin-layout
    active="settings"
    :title="__('settings.pricing.title')"
    :crumbs="[
        ['label' => __('settings.crumb_parent')],
        ['label' => __('settings.title'), 'href' => route('admin.settings.index')],
        ['label' => __('settings.pricing.title')],
    ]">

    <div class="page-wide">
        <form method="POST" action="{{ route('admin.settings.pricing.update') }}" data-ajax-form>
            @csrf
            @method('PATCH')

            <div class="page-header mb-6">
                <div class="flex items-start gap-3">
                    <a href="{{ route('admin.settings.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('settings.title') }}">
                        <x-icon name="back" class="w-4 h-4" />
                    </a>
                    <div>
                        <h1 class="page-title">{{ __('settings.pricing.title') }}</h1>
                        <p class="page-sub">{{ __('settings.pricing.sub') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                        <x-icon name="check" class="w-4 h-4" />
                        {{ __('settings.pricing.actions.save') }}
                    </button>
                </div>
            </div>

            <div class="max-w-3xl">
                <div class="card mb-5">
                    <div class="card-header"><div>
                        <div class="card-title">{{ __('settings.pricing.sections.method') }}</div>
                        <div class="card-title-sub">{{ __('settings.pricing.sections.method_sub') }}</div>
                    </div></div>
                    <div class="card-body">
                        <div class="form-stack">
                            @foreach (['product_based', 'batch_wise'] as $method)
                                <label class="field-toggle items-start">
                                    <input type="radio"
                                           name="pricing_method"
                                           value="{{ $method }}"
                                           class="pos-radio mt-0.5"
                                           @checked(old('pricing_method', $company->pricing_method ?: 'product_based') === $method)>
                                    <span>
                                        <span class="block font-medium">{{ __('settings.pricing.methods.'.$method.'.label') }}</span>
                                        <span class="field-help block mt-1">{{ __('settings.pricing.methods.'.$method.'.help') }}</span>
                                    </span>
                                </label>
                            @endforeach
                            @error('pricing_method')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><div>
                        <div class="card-title">{{ __('settings.pricing.sections.auto_markup') }}</div>
                        <div class="card-title-sub">{{ __('settings.pricing.sections.auto_markup_sub') }}</div>
                    </div></div>
                    <div class="card-body">
                        <div class="form-stack">
                            <label class="field-toggle">
                                <input type="hidden" name="auto_apply_markup_on_receive" value="0">
                                <input type="checkbox"
                                       name="auto_apply_markup_on_receive"
                                       value="1"
                                       @checked(old('auto_apply_markup_on_receive', $company->auto_apply_markup_on_receive))>
                                <span>{{ __('settings.pricing.fields.auto_apply_markup_on_receive') }}</span>
                            </label>
                            <p class="field-help">{{ __('settings.pricing.fields.auto_apply_markup_on_receive_help') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-admin-layout>
