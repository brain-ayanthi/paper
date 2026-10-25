<x-admin-layout
    active=""
    :title="__('account.title')"
    :crumbs="[
        ['label' => __('account.title')],
    ]">

    <div class="page-wide">
        <x-admin.demo-lock-banner>{{ __('account.demo.locked_banner') }}</x-admin.demo-lock-banner>

        {{-- Profile + preferences (one form, one save). AJAX via lib/ajax-form.js. --}}
        <form method="POST" action="{{ route('admin.profile.update') }}" enctype="multipart/form-data" data-ajax-form>
            @csrf
            @method('PATCH')
            {{-- On the demo, `disabled` makes the whole form read-only; `contents`
                 keeps the existing layout intact. --}}
            <fieldset @disabled(pos_is_demo()) class="contents">

            <div class="page-header mb-6">
                <div>
                    <h1 class="page-title">{{ __('account.title') }}</h1>
                    <p class="page-sub">{{ __('account.sub') }}</p>
                </div>
                <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                    <x-icon name="check" class="w-4 h-4" />
                    {{ __('account.actions.save') }}
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
                {{-- Profile --}}
                <div class="card">
                    <div class="card-header"><div>
                        <div class="card-title">{{ __('account.sections.profile') }}</div>
                        <div class="card-title-sub">{{ __('account.sections.profile_sub') }}</div>
                    </div></div>
                    <div class="card-body">
                        <div class="form-stack">
                            <x-admin.image-upload
                                name="avatar"
                                variant="inline"
                                :max-size-kb="8192"
                                :initial-url="$user->avatar_url"
                                :tile-label="__('account.fields.avatar')"
                                :tile-hint="__('account.fields.avatar_hint')" />

                            <label class="field">
                                <span class="field-label is-required">{{ __('account.fields.name') }}</span>
                                <input type="text" name="name" value="{{ old('name', $user->name) }}" maxlength="191" class="pos-input">
                                @error('name')<p class="field-error">{{ $message }}</p>@enderror
                            </label>

                            <label class="field">
                                <span class="field-label is-required">{{ __('account.fields.email') }}</span>
                                <input type="email" name="email" value="{{ old('email', $user->email) }}" maxlength="191" class="pos-input">
                                @error('email')<p class="field-error">{{ $message }}</p>@enderror
                                {{-- A change is parked until it's confirmed from the new
                                     address (SEC #10) — say so, or the field looks like it
                                     silently ignored the edit. --}}
                                @if ($user->pending_email)
                                    <x-alert type="info" class="mt-2">
                                        {{ __('account.email_change.pending', ['email' => $user->pending_email]) }}
                                    </x-alert>
                                @endif
                            </label>

                            <label class="field">
                                <span class="field-label">{{ __('account.fields.phone') }}</span>
                                <input type="text" name="phone" value="{{ old('phone', $user->phone) }}" maxlength="32" class="pos-input">
                                @error('phone')<p class="field-error">{{ $message }}</p>@enderror
                            </label>
                        </div>
                    </div>
                </div>

                {{-- Preferences --}}
                <div class="card">
                    <div class="card-header"><div>
                        <div class="card-title">{{ __('account.sections.preferences') }}</div>
                        <div class="card-title-sub">{{ __('account.sections.preferences_sub') }}</div>
                    </div></div>
                    <div class="card-body">
                        <div class="form-stack">
                            <label class="field">
                                <span class="field-label">{{ __('account.fields.language') }}</span>
                                <select name="locale" class="pos-input" x-data="enhancedSelect()">
                                    <option value="">{{ __('account.fields.language_system') }}</option>
                                    @foreach ($languages as $lang)
                                        <option value="{{ $lang->code }}" @selected(old('locale', $user->locale) === $lang->code)>{{ $lang->name }}</option>
                                    @endforeach
                                </select>
                                @error('locale')<p class="field-error">{{ $message }}</p>@enderror
                            </label>

                            <label class="field">
                                <span class="field-label">{{ __('account.fields.default_store') }}</span>
                                <select name="default_store_id" class="pos-input" x-data="enhancedSelect()">
                                    <option value="">{{ __('account.fields.default_store_none') }}</option>
                                    @foreach ($stores as $store)
                                        <option value="{{ $store->id }}" @selected((int) old('default_store_id', $user->default_store_id) === $store->id)>{{ $store->name }}</option>
                                    @endforeach
                                </select>
                                @error('default_store_id')<p class="field-error">{{ $message }}</p>@enderror
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            </fieldset>
        </form>

        {{-- Password (separate form) --}}
        <div class="card mt-5">
            <div class="card-header"><div>
                <div class="card-title">{{ __('account.sections.password') }}</div>
                <div class="card-title-sub">{{ __('account.sections.password_sub') }}</div>
            </div></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.profile.password') }}" data-ajax-form>
                    @csrf
                    @method('PUT')
                    {{-- Read-only on the demo — the published demo credentials
                         must keep working for the next visitor. --}}
                    <fieldset @disabled(pos_is_demo()) class="contents">

                    <div class="form-stack max-w-[420px]">
                        <label class="field" x-data="{ shown: false }">
                            <span class="field-label is-required">{{ __('account.fields.current_password') }}</span>
                            <div class="secret-input-wrap">
                                <input :type="shown ? 'text' : 'password'"
                                       name="current_password"
                                       autocomplete="current-password"
                                       class="pos-input">
                                <button type="button"
                                        class="secret-input-eye"
                                        @click="shown = !shown"
                                        :aria-label="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))"
                                        :title="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))">
                                    <x-icon name="eye" class="w-4 h-4" x-show="!shown" />
                                    <x-icon name="eye-off" class="w-4 h-4" x-show="shown" x-cloak />
                                </button>
                            </div>
                            @error('current_password')<p class="field-error">{{ $message }}</p>@enderror
                        </label>

                        <label class="field" x-data="{ shown: false }">
                            <span class="field-label is-required">{{ __('account.fields.new_password') }}</span>
                            <div class="secret-input-wrap">
                                <input :type="shown ? 'text' : 'password'"
                                       name="password"
                                       autocomplete="new-password"
                                       class="pos-input">
                                <button type="button"
                                        class="secret-input-eye"
                                        @click="shown = !shown"
                                        :aria-label="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))"
                                        :title="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))">
                                    <x-icon name="eye" class="w-4 h-4" x-show="!shown" />
                                    <x-icon name="eye-off" class="w-4 h-4" x-show="shown" x-cloak />
                                </button>
                            </div>
                            @error('password')<p class="field-error">{{ $message }}</p>@enderror
                        </label>

                        <label class="field" x-data="{ shown: false }">
                            <span class="field-label is-required">{{ __('account.fields.confirm_password') }}</span>
                            <div class="secret-input-wrap">
                                <input :type="shown ? 'text' : 'password'"
                                       name="password_confirmation"
                                       autocomplete="new-password"
                                       class="pos-input">
                                <button type="button"
                                        class="secret-input-eye"
                                        @click="shown = !shown"
                                        :aria-label="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))"
                                        :title="shown ? @js(__('auth.hide_password')) : @js(__('auth.show_password'))">
                                    <x-icon name="eye" class="w-4 h-4" x-show="!shown" />
                                    <x-icon name="eye-off" class="w-4 h-4" x-show="shown" x-cloak />
                                </button>
                            </div>
                        </label>

                        <div>
                            <button type="submit" class="pos-btn pos-btn-sm pos-btn-secondary">
                                <x-icon name="lock" class="w-4 h-4" />
                                {{ __('account.actions.change_password') }}
                            </button>
                        </div>
                    </div>

                    </fieldset>
                </form>
            </div>
        </div>

        {{-- Active devices (SEC #40). docs/features/auth-users.md §Account
             security specifies this list, "sign out other devices", and the
             admin force-logout — none of which existed, so an account signed
             in on a shop-floor tablet could only be evicted by changing its
             password. --}}
        <div class="card mt-5">
            <div class="card-header"><div>
                <div class="card-title">{{ __('account.sections.sessions') }}</div>
                <div class="card-title-sub">{{ __('account.sections.sessions_sub') }}</div>
            </div></div>
            <div class="card-body">
                @unless ($sessionsSupported)
                    <x-alert type="info">{{ __('account.sessions.driver_required') }}</x-alert>
                @else
                    <div class="dt-scroll">
                        <table class="pos-table">
                            <thead>
                                <tr>
                                    <th class="text-start">{{ __('account.sessions.columns.device') }}</th>
                                    <th class="text-start">{{ __('account.sessions.columns.ip') }}</th>
                                    <th class="text-start">{{ __('account.sessions.columns.last_active') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($sessions as $session)
                                    <tr>
                                        <td>
                                            {{ $session->device }}
                                            @if ($session->is_current)
                                                <span class="pos-badge pos-badge-success ms-2">{{ __('account.sessions.this_device') }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $session->ip_address ?: '—' }}</td>
                                        <td>{{ format_datetime($session->last_activity) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="fg-tertiary">{{ __('account.sessions.none') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Password re-entry, Laravel's standard pattern: the point
                         of this button is to evict somebody holding a session
                         they shouldn't, so a session alone must not authorise it. --}}
                    <form method="POST" action="{{ route('admin.profile.sessions.revoke') }}" data-ajax-form class="mt-4">
                        @csrf
                        <div class="form-stack" style="max-width:22rem">
                            <label class="field">
                                <span class="field-label is-required">{{ __('account.sessions.confirm_password') }}</span>
                                <input type="password" name="password" class="pos-input" autocomplete="current-password">
                            </label>
                            <div>
                                <button type="submit" class="pos-btn pos-btn-sm pos-btn-secondary">
                                    <x-icon name="lock" class="w-4 h-4" />
                                    {{ __('account.actions.sign_out_others') }}
                                </button>
                            </div>
                        </div>
                    </form>
                @endunless
            </div>
        </div>
    </div>
</x-admin-layout>
