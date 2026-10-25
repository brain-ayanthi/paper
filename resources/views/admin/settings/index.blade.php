<x-admin-layout
    active="settings"
    :title="__('settings.title')"
    :crumbs="[
        ['label' => __('settings.crumb_parent')],
        ['label' => __('settings.title')],
    ]">

    @php
        /**
         * Settings groups. Add a new settings section by appending one
         * row here (label / desc / icon / route) — the card grid + the
         * detail page wiring pick it up automatically.
         */
        $groups = [
            [
                'label' => __('settings.groups.company.title'),
                'desc'  => __('settings.groups.company.desc'),
                'icon'  => 'store',
                'route' => 'admin.settings.company.edit',
            ],
            [
                'label' => __('settings.groups.branding.title'),
                'desc'  => __('settings.groups.branding.desc'),
                'icon'  => 'image',
                'route' => 'admin.settings.branding.edit',
            ],
            [
                'label' => __('settings.groups.regional.title'),
                'desc'  => __('settings.groups.regional.desc'),
                'icon'  => 'globe',
                'route' => 'admin.settings.regional.edit',
            ],
            [
                'label' => __('settings.groups.currency.title'),
                'desc'  => __('settings.groups.currency.desc'),
                'icon'  => 'cash',
                'route' => 'admin.settings.currency.edit',
            ],
            [
                'label' => __('settings.groups.receipt.title'),
                'desc'  => __('settings.groups.receipt.desc'),
                'icon'  => 'receipt',
                'route' => 'admin.settings.receipt.edit',
            ],
            [
                'label' => __('settings.groups.cashier.title'),
                'desc'  => __('settings.groups.cashier.desc'),
                'icon'  => 'pos',
                'route' => 'admin.settings.cashier.edit',
            ],
            [
                'label' => __('settings.groups.security.title'),
                'desc'  => __('settings.groups.security.desc'),
                'icon'  => 'lock',
                'route' => 'admin.settings.security.edit',
            ],
            [
                'label' => __('settings.groups.custom_scripts.title'),
                'desc'  => __('settings.groups.custom_scripts.desc'),
                'icon'  => 'shield',
                'route' => 'admin.settings.custom-scripts.edit',
            ],
            [
                'label' => __('settings.groups.scale.title'),
                'desc'  => __('settings.groups.scale.desc'),
                'icon'  => 'barcode',
                'route' => 'admin.settings.scale.edit',
            ],
            [
                'label' => __('settings.groups.locations.title'),
                'desc'  => __('settings.groups.locations.desc'),
                'icon'  => 'box',
                'route' => 'admin.settings.locations.edit',
            ],
            [
                'label' => __('settings.groups.loyalty.title'),
                'desc'  => __('settings.groups.loyalty.desc'),
                'icon'  => 'customers',
                'route' => 'admin.settings.loyalty.edit',
            ],
            [
                'label' => __('settings.groups.pricing.title'),
                'desc'  => __('settings.groups.pricing.desc'),
                'icon'  => 'tag',
                'route' => 'admin.settings.pricing.edit',
            ],
            [
                'label' => __('settings.groups.numbering.title'),
                'desc'  => __('settings.groups.numbering.desc'),
                'icon'  => 'tag',
                'route' => 'admin.settings.numbering.edit',
            ],
            [
                'label' => __('settings.groups.email.title'),
                'desc'  => __('settings.groups.email.desc'),
                'icon'  => 'mail',
                'route' => 'admin.settings.email.edit',
            ],
            [
                'label' => __('whatsapp.settings.title'),
                'desc'  => __('whatsapp.settings.sub'),
                'icon'  => 'external',
                'route' => 'admin.settings.whatsapp.edit',
            ],
            [
                'label' => __('settings.groups.payment_methods.title'),
                'desc'  => __('settings.groups.payment_methods.desc'),
                'icon'  => 'cash',
                'route' => 'admin.settings.payment-methods.edit',
            ],
            [
                'label' => __('settings.groups.payment_gateways.title'),
                'desc'  => __('settings.groups.payment_gateways.desc'),
                'icon'  => 'card',
                'route' => 'admin.settings.payment-gateways.edit',
            ],
            [
                'label' => __('settings.groups.backup.title'),
                'desc'  => __('settings.groups.backup.desc'),
                'icon'  => 'database',
                'route' => 'admin.settings.backup.edit',
            ],
            [
                'label' => __('settings.groups.scheduler.title'),
                'desc'  => __('settings.groups.scheduler.desc'),
                'icon'  => 'clock',
                'route' => 'admin.settings.scheduler.index',
            ],
            [
                'label' => __('settings.groups.updates.title'),
                'desc'  => __('settings.groups.updates.desc'),
                'icon'  => 'refresh',
                'route' => 'admin.settings.updates.index',
            ],
            [
                'label' => __('settings.groups.plugins.title'),
                'desc'  => __('settings.groups.plugins.desc'),
                'icon'  => 'box',
                'route' => 'admin.settings.plugins.index',
                'owner_only' => true,
            ],
            [
                'label' => __('settings.groups.license.title'),
                'desc'  => __('settings.groups.license.desc'),
                'icon'  => 'key',
                'route' => 'admin.settings.license.index',
            ],
            [
                'label' => __('settings.groups.legal_pages.title'),
                'desc'  => __('settings.groups.legal_pages.desc'),
                'icon'  => 'lock',
                'route' => 'admin.settings.legal-pages.edit',
            ],
        ];

        // The License screen exists only to display + re-verify the purchase
        // code. With panel-side checks off (config/pos.php → license.recheck)
        // there's nothing to show, so drop the tile entirely.
        if (! config('pos.license.recheck')) {
            $groups = array_values(array_filter(
                $groups,
                fn ($g) => ($g['route'] ?? null) !== 'admin.settings.license.index',
            ));
        }

        // Raw browser code is an owner-level trust decision. Do not advertise
        // this surface to ordinary users who merely hold settings permissions.
        if (! auth()->user()?->is_super_admin) {
            $groups = array_values(array_filter(
                $groups,
                fn ($g) => empty($g['owner_only'])
                    && ($g['route'] ?? null) !== 'admin.settings.custom-scripts.edit',
            ));
        }

        $groups = apply_filters('settings.pages', $groups, auth()->user());
    @endphp

    <div class="page-wide">
        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">{{ __('settings.title') }}</h1>
                <p class="page-sub">{{ __('settings.sub') }}</p>
            </div>
        </div>

        <div class="settings-grid">
            @foreach ($groups as $g)
                @continue(! empty($g['hidden']))
                <a href="{{ route($g['route']) }}" class="settings-card">
                    <span class="settings-card-icon"><x-icon :name="$g['icon']" class="w-5 h-5" /></span>
                    <span class="settings-card-text">
                        <span class="settings-card-title">{{ $g['label'] }}</span>
                        <span class="settings-card-desc">{{ $g['desc'] }}</span>
                    </span>
                    <x-icon name="chevron-right" class="w-4 h-4 settings-card-arrow" />
                </a>
            @endforeach
        </div>
    </div>
</x-admin-layout>
