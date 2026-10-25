        {{-- Section: Super-admin user --}}
        <section class="space-y-4">
            <div class="section-head"><div>{{ __('installer.admin.section_user') }}</div></div>

            <label class="block">
                <span class="field-label">{{ __('installer.admin.name') }}</span>
                <input type="text" name="admin_name" required value="{{ old('admin_name') }}" class="pos-input">
            </label>

            <label class="block">
                <span class="field-label">{{ __('installer.admin.email') }}</span>
                <input type="email" name="admin_email" required value="{{ old('admin_email') }}" class="pos-input">
            </label>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="field-label">{{ __('installer.admin.password') }}</span>
                    <x-admin.password-input name="admin_password" required autocomplete="new-password" />
                    <p class="mt-2 text-[12px] fg-tertiary">{{ $passwordHint ?? __('installer.admin.password_hint') }}</p>
                </label>
                <label class="block">
                    <span class="field-label">{{ __('installer.admin.password_confirm') }}</span>
                    <x-admin.password-input name="admin_password_confirmation" required autocomplete="new-password" />
                </label>
            </div>
        </section>
