<x-admin-layout
    active="settings"
    :title="__('settings.security.title')"
    :crumbs="[
        ['label' => __('settings.crumb_parent')],
        ['label' => __('settings.title'), 'href' => route('admin.settings.index')],
        ['label' => __('settings.security.title')],
    ]">

    <div class="page-wide">
        <form method="POST" action="{{ route('admin.settings.security.update') }}" data-ajax-form>
            @csrf
            @method('PATCH')

            <div class="page-header mb-6">
                <div class="flex items-start gap-3">
                    <a href="{{ route('admin.settings.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('settings.title') }}">
                        <x-icon name="back" class="w-4 h-4" />
                    </a>
                    <div>
                        <h1 class="page-title">{{ __('settings.security.title') }}</h1>
                        <p class="page-sub">{{ __('settings.security.sub') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary" @disabled(pos_is_demo())>
                        <x-icon name="check" class="w-4 h-4" />
                        {{ __('settings.security.actions.save') }}
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><div>
                    <div class="card-title">{{ __('settings.security.sections.sessions') }}</div>
                    <div class="card-title-sub">{{ __('settings.security.sections.sessions_sub') }}</div>
                </div></div>
                <div class="card-body">
                    @unless ($sessionsSupported)
                        {{-- The policy works by deleting rows from the `sessions`
                             table, so it needs SESSION_DRIVER=database. --}}
                        <x-alert type="warning" class="mb-4">{{ __('settings.security.driver_required') }}</x-alert>
                    @endunless

                    <label class="field-toggle">
                        <input type="hidden" name="enforce_single_session" value="0">
                        <input type="checkbox" name="enforce_single_session" value="1"
                               @checked(old('enforce_single_session', $company?->enforce_single_session))
                               @disabled(! $sessionsSupported)>
                        <span>{{ __('settings.security.fields.single_session') }}</span>
                    </label>
                    <p class="field-help mt-2">{{ __('settings.security.fields.single_session_help') }}</p>
                </div>
            </div>
        </form>
    </div>
</x-admin-layout>
