<x-admin-layout
    active="settings"
    :title="__('settings.loyalty.title')"
    :crumbs="[
        ['label' => __('settings.crumb_parent')],
        ['label' => __('settings.title'), 'href' => route('admin.settings.index')],
        ['label' => __('settings.loyalty.title')],
    ]">

    @php
        $l = $company->loyalty();
        $v = [
            'enabled'            => (bool) old('enabled',            $l['enabled']),
            'earn_points'        => (int) old('earn_points',         $l['earn_points']),
            'earn_per_amount'    => old('earn_per_amount',           $l['earn_per_amount']),
            'earn_on'            => old('earn_on',                   $l['earn_on']),
            'redeem_points'      => (int) old('redeem_points',       $l['redeem_points']),
            'redeem_value'       => old('redeem_value',              $l['redeem_value']),
            'min_redeem_points'  => (int) old('min_redeem_points',   $l['min_redeem_points']),
            'max_redeem_percent' => old('max_redeem_percent',        $l['max_redeem_percent']),
            'expiry_months'      => (int) old('expiry_months',       $l['expiry_months']),
        ];
    @endphp

    <div class="page-wide" x-data="loyaltySettingsPage({{ Js::from($v) }})">
        {{-- AJAX submit via lib/ajax-form.js. --}}
        <form method="POST" action="{{ route('admin.settings.loyalty.update') }}" data-ajax-form>
            @csrf
            @method('PATCH')

            <div class="page-header mb-6">
                <div class="flex items-start gap-3">
                    <a href="{{ route('admin.settings.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('settings.title') }}">
                        <x-icon name="back" class="w-4 h-4" />
                    </a>
                    <div>
                        <h1 class="page-title">{{ __('settings.loyalty.title') }}</h1>
                        <p class="page-sub">{{ __('settings.loyalty.sub') }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                        <x-icon name="check" class="w-4 h-4" />
                        {{ __('settings.loyalty.actions.save') }}
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
                {{-- Enable --}}
                <div class="card lg:col-span-2">
                    <div class="card-header"><div>
                        <div class="card-title">{{ __('settings.loyalty.sections.enable') }}</div>
                        <div class="card-title-sub">{{ __('settings.loyalty.sections.enable_sub') }}</div>
                    </div></div>
                    <div class="card-body">
                        <label class="field-toggle">
                            <input type="hidden" name="enabled" value="0">
                            <input type="checkbox" name="enabled" value="1" x-model="cfg.enabled" @checked($v['enabled'])>
                            <span>{{ __('settings.loyalty.fields.enabled') }}</span>
                        </label>
                    </div>
                </div>

                {{-- Earning --}}
                <div class="card">
                    <div class="card-header"><div>
                        <div class="card-title">{{ __('settings.loyalty.sections.earn') }}</div>
                        <div class="card-title-sub">{{ __('settings.loyalty.sections.earn_sub') }}</div>
                    </div></div>
                    <div class="card-body">
                        <div class="form-stack">
                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.loyalty.fields.earn_points') }}</span>
                                <input type="number" name="earn_points" class="pos-input tnum"
                                       x-model.number="cfg.earn_points"
                                       min="0" step="1" inputmode="numeric">
                                <span class="field-help">{{ __('settings.loyalty.fields.earn_points_help') }}</span>
                            </label>

                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.loyalty.fields.earn_per_amount') }}</span>
                                <input type="number" name="earn_per_amount" class="pos-input tnum"
                                       x-model="cfg.earn_per_amount"
                                       min="0.01" step="0.01" inputmode="decimal">
                                <span class="field-help">{{ __('settings.loyalty.fields.earn_per_amount_help') }}</span>
                            </label>

                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.loyalty.fields.earn_on') }}</span>
                                {{-- x-effect keeps TomSelect's chip in step when `cfg` changes in JS. --}}
                                <select name="earn_on" class="pos-input"
                                        x-data="enhancedSelect()"
                                        x-effect="ts && ts.setValue(cfg.earn_on ?? '', true)"
                                        x-model="cfg.earn_on">
                                    @foreach ($earnBases as $base)
                                        <option value="{{ $base }}" @selected($v['earn_on'] === $base)>{{ __('settings.loyalty.earn_bases.'.$base) }}</option>
                                    @endforeach
                                </select>
                                <span class="field-help">{{ __('settings.loyalty.fields.earn_on_help') }}</span>
                            </label>

                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.loyalty.fields.expiry_months') }}</span>
                                <input type="number" name="expiry_months" class="pos-input tnum"
                                       x-model.number="cfg.expiry_months"
                                       min="0" max="120" step="1" inputmode="numeric">
                                <span class="field-help">{{ __('settings.loyalty.fields.expiry_months_help') }}</span>
                            </label>
                        </div>
                    </div>
                </div>

                {{-- Redeeming --}}
                <div class="card">
                    <div class="card-header"><div>
                        <div class="card-title">{{ __('settings.loyalty.sections.redeem') }}</div>
                        <div class="card-title-sub">{{ __('settings.loyalty.sections.redeem_sub') }}</div>
                    </div></div>
                    <div class="card-body">
                        <div class="form-stack">
                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.loyalty.fields.redeem_points') }}</span>
                                <input type="number" name="redeem_points" class="pos-input tnum"
                                       x-model.number="cfg.redeem_points"
                                       min="1" step="1" inputmode="numeric">
                                <span class="field-help">{{ __('settings.loyalty.fields.redeem_points_help') }}</span>
                            </label>

                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.loyalty.fields.redeem_value') }}</span>
                                <input type="number" name="redeem_value" class="pos-input tnum"
                                       x-model="cfg.redeem_value"
                                       min="0" step="0.01" inputmode="decimal">
                                <span class="field-help">{{ __('settings.loyalty.fields.redeem_value_help') }}</span>
                            </label>

                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.loyalty.fields.min_redeem_points') }}</span>
                                <input type="number" name="min_redeem_points" class="pos-input tnum"
                                       x-model.number="cfg.min_redeem_points"
                                       min="0" step="1" inputmode="numeric">
                                <span class="field-help">{{ __('settings.loyalty.fields.min_redeem_points_help') }}</span>
                            </label>

                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.loyalty.fields.max_redeem_percent') }}</span>
                                <input type="number" name="max_redeem_percent" class="pos-input tnum"
                                       x-model="cfg.max_redeem_percent"
                                       min="0" max="100" step="1" inputmode="decimal">
                                <span class="field-help">{{ __('settings.loyalty.fields.max_redeem_percent_help') }}</span>
                            </label>
                        </div>
                    </div>
                </div>

                {{-- Live preview --}}
                <div class="card lg:col-span-2">
                    <div class="card-header"><div>
                        <div class="card-title">{{ __('settings.loyalty.preview.title') }}</div>
                        <div class="card-title-sub">{{ __('settings.loyalty.preview.help') }}</div>
                    </div></div>
                    <div class="card-body">
                        <div class="form-stack">
                            <label class="field">
                                <span class="field-label">{{ __('settings.loyalty.preview.basket') }}</span>
                                <input type="number" class="pos-input tnum"
                                       x-model="sample"
                                       min="0" step="1" inputmode="decimal">
                            </label>

                            <div class="loyalty-preview">
                                <div class="loyalty-preview-stat">
                                    <div class="loyalty-preview-label">{{ __('settings.loyalty.preview.earns') }}</div>
                                    <div class="loyalty-preview-value tnum" x-text="earnedPoints.toLocaleString()">0</div>
                                    <div class="loyalty-preview-sub">{{ __('settings.loyalty.preview.points') }}</div>
                                </div>
                                <div class="loyalty-preview-stat">
                                    <div class="loyalty-preview-label">{{ __('settings.loyalty.preview.worth') }}</div>
                                    <div class="loyalty-preview-value tnum" x-text="earnedValue">—</div>
                                    <div class="loyalty-preview-sub">{{ __('settings.loyalty.preview.worth_sub') }}</div>
                                </div>
                                <div class="loyalty-preview-stat">
                                    <div class="loyalty-preview-label">{{ __('settings.loyalty.preview.point_value') }}</div>
                                    <div class="loyalty-preview-value tnum" x-text="pointValue">—</div>
                                    <div class="loyalty-preview-sub">{{ __('settings.loyalty.preview.point_value_sub') }}</div>
                                </div>
                                <div class="loyalty-preview-stat">
                                    <div class="loyalty-preview-label">{{ __('settings.loyalty.preview.rate') }}</div>
                                    <div class="loyalty-preview-value tnum"><span x-text="effectiveRate">0</span>%</div>
                                    <div class="loyalty-preview-sub">{{ __('settings.loyalty.preview.rate_sub') }}</div>
                                </div>
                            </div>

                            <x-alert type="info">{{ __('settings.loyalty.preview.tier_note') }}</x-alert>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-admin-layout>
