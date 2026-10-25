{{--
    Public marketing landing page — rendered at "/" ONLY when demo mode
    is on (POS_DEMO_MODE=true). Self-contained document (its own <html>),
    so it doesn't pull in the admin chrome. Branding + accent color come
    from the company singleton via LandingController; theme (light/dark)
    is driven by the shared $store.ui store from app.js.
--}}
@php
    $isRtl    = in_array(app()->getLocale(), ['ar', 'he', 'ur', 'fa']);
    $logo     = $company?->app_logo_url;
    $logoDark = $company?->app_logo_dark_url;
    $favicon  = $company?->favicon_url;
    $brand    = $company?->brand_color;
    $brandFg  = $company?->brand_text_color;

    // When the visitor is already signed in, every "sign in / try demo"
    // action should take them into the app — pointing at /login would just
    // bounce off its `guest` middleware back to this page. Guests go to login.
    $isAuthed = auth()->check();
    $appUrl   = $isAuthed ? route('admin.dashboard') : route('login');

    // Decorative host shown in the faux browser address bar of the
    // screenshot frames (e.g. "hyper-pos-dev.infinitietech.in").
    $mockHost = parse_url(config('app.url'), PHP_URL_HOST) ?: 'your-store.com';

    // The screenshot shown inside the hero laptop frame: prefer a purpose-made
    // hero shot (images/landing/hero/monitor.*), else the cashier screenshot.
    // Either way it's framed as a real device — not shown raw.
    $heroShot    = $heroMonitor ?: $cashierShot;
    $heroArtwork = $heroMain ?: $heroShot;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="index, follow">
    <meta name="description" content="{{ __('landing.meta.description') }}">
    <meta name="pos-theme-default" content="{{ $company?->theme_default ?: 'light' }}">
    @if ($favicon)
        <link rel="icon" href="{{ $favicon }}">
    @endif
    <title>{{ $appName }} — {{ __('landing.meta.title') }}</title>

    {{-- Google Analytics — self-gates on demo mode + a configured GA4 ID,
         so it never loads on a real customer install. --}}
    @include('partials.google-analytics')
    @include('partials.custom-scripts', ['position' => 'head', 'surface' => 'public'])

    @vite(['resources/css/landing.css', 'resources/js/app.js', 'resources/js/landing.js'])

    {{-- Runtime brand-color theming — the same CSS-variable override layer
         the auth + admin shells use so a customer's accent color carries
         onto the demo landing page. --}}
    @if ($brand || $brandFg)
        <style>
            :root {
                @if ($brand)
                --accent: {{ $brand }};
                --accent-hover: color-mix(in srgb, {{ $brand }} 86%, #000);
                --accent-soft: color-mix(in srgb, {{ $brand }} 12%, transparent);
                --accent-ring: color-mix(in srgb, {{ $brand }} 30%, transparent);
                @endif
                --accent-fg: {{ $brandFg ?: '#fff' }};
            }
        </style>
    @endif
</head>
<body class="landing font-sans antialiased" x-data x-init="$store.ui.init()">
    <a href="#landing-main" class="landing-skip-link">{{ __('landing.nav.skip_content') }}</a>
    <div class="landing-bg"></div>

    {{-- ── Top navigation ─────────────────────────────────── --}}
    <header class="landing-shell">
        <nav class="landing-nav" aria-label="{{ __('landing.nav.primary') }}">
            <a href="{{ route('landing') }}" class="landing-brand">
                @if ($logo && $logoDark)
                    {{-- both variants: swap via CSS on the dark class --}}
                    <img src="{{ $logo }}"     alt="{{ $appName }}" class="landing-brand-logo landing-brand-logo-light">
                    <img src="{{ $logoDark }}" alt="{{ $appName }}" class="landing-brand-logo landing-brand-logo-dark">
                @elseif ($logo || $logoDark)
                    {{-- single logo: always visible in both themes --}}
                    <img src="{{ $logo ?: $logoDark }}" alt="{{ $appName }}" class="landing-brand-logo">
                @else
                    <span class="landing-brand-mark">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($appName, 0, 1)) }}</span>
                    <span>{{ $appName }}</span>
                @endif
            </a>

            <div class="landing-nav-links">
                <a href="#features" class="landing-nav-link">{{ __('landing.nav.features') }}</a>
                <a href="#integrations" class="landing-nav-link">{{ __('landing.nav.integrations') }}</a>
                <a href="#industries" class="landing-nav-link">{{ __('landing.nav.businesses') }}</a>
                <a href="#pricing" class="landing-nav-link">{{ __('landing.nav.pricing') }}</a>
                <a href="{{ $appUrl }}" class="landing-nav-link" target="_blank" rel="noopener">{{ __('landing.nav.demo') }}</a>
            </div>

            <div class="landing-nav-actions">
                <button type="button"
                        class="landing-theme-btn"
                        aria-label="{{ __('landing.nav.toggle_theme') }}"
                        @click="$store.ui.toggle()">
                    <span class="dark:hidden"><x-icon name="moon" class="w-[18px] h-[18px]" /></span>
                    <span class="hidden dark:inline-flex"><x-icon name="sun" class="w-[18px] h-[18px]" /></span>
                </button>
                <a href="{{ $appUrl }}" target="_blank" rel="noopener" class="landing-btn landing-btn-ghost landing-btn-sm">
                    {{ $isAuthed ? __('landing.nav.dashboard') : __('landing.nav.sign_in') }}
                </a>
                @if ($purchaseUrl)
                    <a href="{{ $purchaseUrl }}" target="_blank" rel="noopener"
                       class="landing-btn landing-btn-primary landing-btn-sm">
                        {{ __('landing.nav.buy') }}
                    </a>
                @endif
                <button type="button" class="landing-v3-menu-btn" data-landing-menu-toggle aria-expanded="false" aria-controls="landing-mobile-menu" aria-label="{{ __('landing.v3.mobile_menu.open') }}">
                    <x-icon name="menu" class="w-[19px] h-[19px]" />
                </button>
            </div>
        </nav>
        <div id="landing-mobile-menu" class="landing-v3-mobile-menu" data-landing-mobile-menu hidden>
            <a href="#features">{{ __('landing.nav.features') }}</a>
            <a href="#integrations">{{ __('landing.nav.integrations') }}</a>
            <a href="#industries">{{ __('landing.nav.businesses') }}</a>
            <a href="#pricing">{{ __('landing.nav.pricing') }}</a>
            <a href="{{ $appUrl }}" target="_blank" rel="noopener">{{ __('landing.nav.demo') }}</a>
        </div>
    </header>

    <main id="landing-main" tabindex="-1">
        {{-- ── Product-first hero ───────────────────────────── --}}
        <section class="landing-shell landing-v2-hero">
            <div class="landing-v2-hero-copy">
                <span class="landing-eyebrow">
                    <span class="landing-eyebrow-dot"></span>
                    {{ __('landing.v2.hero.eyebrow') }}
                </span>
                <h1 class="landing-v2-hero-title">{{ __('landing.v2.hero.title') }}</h1>
                <p class="landing-v2-hero-sub">{{ __('landing.v2.hero.subtitle') }}</p>

                <div class="landing-v3-hero-integrations" aria-label="{{ __('landing.v3.hero.integrations_label') }}">
                    @foreach ([
                        ['provider' => 'shopify', 'logo' => asset('assets/providers/shopify.png'), 'title' => 'shopify_title', 'body' => 'shopify_body'],
                        ['provider' => 'woocommerce', 'logo' => asset('assets/providers/woocommerce.png'), 'title' => 'woo_title', 'body' => 'woo_body'],
                        ['provider' => 'whatsapp', 'logo' => $whatsappLogo, 'title' => 'whatsapp_title', 'body' => 'whatsapp_body'],
                    ] as $integration)
                        <article class="landing-v3-hero-integration is-{{ $integration['provider'] }}">
                            <span class="landing-v3-provider-logo">
                                @if ($integration['logo'])
                                    <img src="{{ $integration['logo'] }}" alt="">
                                @else
                                    <b class="landing-v3-provider-placeholder" aria-hidden="true">WA</b>
                                @endif
                            </span>
                            <div>
                                <strong>{{ __('landing.v3.hero.' . $integration['title']) }}</strong>
                                <small>{{ __('landing.v3.hero.' . $integration['body']) }}</small>
                            </div>
                            <i aria-hidden="true"></i>
                        </article>
                    @endforeach
                </div>

                <div class="landing-hero-cta">
                    <a href="{{ $appUrl }}" target="_blank" rel="noopener" class="landing-btn landing-btn-primary landing-btn-lg">
                        {{ __('landing.v2.hero.cta_demo') }}
                        <x-icon name="arrow-right" class="w-[18px] h-[18px]" />
                    </a>
                    <a href="#features" class="landing-btn landing-btn-ghost landing-btn-lg">
                        {{ __('landing.v3.hero.cta_features') }}
                        <x-icon name="arrow-right" class="w-[18px] h-[18px]" />
                    </a>
                </div>

                <div class="landing-v2-proof-list">
                    @foreach (['own', 'offline', 'source'] as $proof)
                        <span><x-icon name="check" class="w-[15px] h-[15px]" />{{ __('landing.v2.hero.proof_' . $proof) }}</span>
                    @endforeach
                </div>

                <div class="landing-hero-pays landing-v4-hero-pays" aria-label="{{ __('landing.hero.pays_label') }}">
                    <span class="landing-hero-pays-label">{{ __('landing.hero.pays_label') }}</span>
                    <div class="landing-hero-pays-logos">
                        @foreach ($paymentMethods as $payment)
                            @if ($payment['logo'])
                                <img class="landing-hero-pay" src="{{ $payment['logo'] }}" alt="{{ $payment['label'] }}" loading="eager" decoding="async">
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="landing-v2-product-stage landing-v3-product-stage" role="img" aria-label="{{ __('landing.v2.hero.visual_label') }}">
                <div class="landing-v2-orbit landing-v2-orbit-one" aria-hidden="true"></div>
                <div class="landing-v2-orbit landing-v2-orbit-two" aria-hidden="true"></div>
                <div class="landing-v3-hero-artwork">
                    @if ($heroArtwork)
                        <img src="{{ $heroArtwork }}" alt="{{ __('landing.v2.hero.cashier_alt') }}" width="1536" height="1024" fetchpriority="high" decoding="async" class="landing-v3-hero-artwork-img">
                    @else
                        <div class="landing-v2-screen-fallback"><x-icon name="dashboard" class="w-[44px] h-[44px]" /></div>
                    @endif
                </div>
                <span class="landing-v2-signal is-shopify"><img src="{{ asset('assets/providers/shopify.png') }}" alt="">{{ __('landing.v3.hero.shopify_connected') }}</span>
                <span class="landing-v2-signal is-woo"><img src="{{ asset('assets/providers/woocommerce.png') }}" alt="">{{ __('landing.v3.hero.woo_connected') }}</span>
                <span class="landing-v2-signal is-whatsapp">
                    @if ($whatsappLogo)
                        <img src="{{ $whatsappLogo }}" alt="">
                    @else
                        <b class="landing-v3-signal-placeholder" aria-hidden="true">WA</b>
                    @endif
                    {{ __('landing.v3.hero.whatsapp_connected') }}
                </span>
                <span class="landing-v2-signal is-offline"><x-icon name="refresh" class="w-[15px] h-[15px]" />{{ __('landing.v3.hero.offline_ready') }}</span>
                <span class="landing-v2-signal is-store"><x-icon name="box" class="w-[15px] h-[15px]" />{{ __('landing.v3.hero.inventory_synced') }}</span>
                <span class="landing-v2-live"><i></i>{{ __('landing.v3.hero.sales_updated') }}</span>
            </div>
        </section>

        {{-- ── Connected commerce — deliberately inside the first scroll ── --}}
        <section id="integrations" class="landing-shell landing-section landing-v3-connect-section">
            <div class="landing-v2-section-head" data-reveal>
                <span class="landing-eyebrow-text">{{ __('landing.v3.connect.eyebrow') }}</span>
                <h2 class="landing-section-title">{{ __('landing.v3.connect.title') }}</h2>
                <p class="landing-section-sub">{{ __('landing.v3.connect.subtitle') }}</p>
            </div>
            <div class="landing-v3-connect" data-reveal aria-label="{{ __('landing.v3.connect.visual_label') }}">
                <article class="landing-v3-connect-provider is-shopify">
                    <img src="{{ asset('assets/providers/shopify.png') }}" alt="Shopify">
                    <strong>Shopify</strong>
                    <small>{{ __('landing.v3.connect.online_store') }}</small>
                </article>
                <div class="landing-v3-connect-route is-left" aria-hidden="true"><i></i><i></i><i></i></div>
                <div class="landing-v3-connect-core">
                    <span><x-icon name="store" class="w-[27px] h-[27px]" /></span>
                    <strong>{{ $appName }}</strong>
                    <small>{{ __('landing.v3.connect.one_system') }}</small>
                </div>
                <div class="landing-v3-connect-route is-right" aria-hidden="true"><i></i><i></i><i></i></div>
                <article class="landing-v3-connect-provider is-woo">
                    <img src="{{ asset('assets/providers/woocommerce.png') }}" alt="WooCommerce">
                    <strong>WooCommerce</strong>
                    <small>{{ __('landing.v3.connect.online_store') }}</small>
                </article>
                <div class="landing-v3-sync-items">
                    @foreach (['products' => 'box', 'inventory' => 'archive', 'orders' => 'receipt', 'customers' => 'customers', 'pricing' => 'tag'] as $key => $icon)
                        <span><x-icon :name="$icon" class="w-[16px] h-[16px]" />{{ __('landing.v3.connect.' . $key) }}</span>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ── Ownership proof rail ─────────────────────────── --}}
        <section class="landing-shell">
            <div class="landing-v2-proof-rail" data-reveal>
                @foreach ([
                    ['icon' => 'database', 'key' => 'self_hosted'],
                    ['icon' => 'refresh', 'key' => 'offline'],
                    ['icon' => 'store', 'key' => 'multistore'],
                    ['icon' => 'cash', 'key' => 'no_fees'],
                ] as $proof)
                    <div class="landing-v2-proof">
                        <span><x-icon :name="$proof['icon']" class="w-[20px] h-[20px]" /></span>
                        <div><strong>{{ __('landing.v2.proof.' . $proof['key'] . '.title') }}</strong><small>{{ __('landing.v2.proof.' . $proof['key'] . '.body') }}</small></div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ── Why Hyper POS — large visual product stories ── --}}
        <section id="features" class="landing-shell landing-section landing-v2-section">
            <div class="landing-v2-section-head" data-reveal>
                <span class="landing-eyebrow-text">{{ __('landing.v3.why.eyebrow') }}</span>
                <h2 class="landing-section-title">{{ __('landing.v3.why.title') }}</h2>
                <p class="landing-section-sub">{{ __('landing.v3.why.subtitle') }}</p>
            </div>
            <div class="landing-v3-pillars">
                <article class="landing-v3-pillar is-sell" data-reveal>
                    <div class="landing-v4-visual landing-v4-cashier" aria-hidden="true">
                        <div class="landing-v4-window-bar"><i></i><i></i><i></i><span>{{ __('landing.v4.visual.cashier') }}</span></div>
                        <div class="landing-v4-cashier-body">
                            <div class="landing-v4-products">
                                <div class="landing-v4-search"><x-icon name="search" class="w-[13px] h-[13px]" />{{ __('landing.v4.visual.search') }}</div>
                                <div class="landing-v4-product-grid">@for ($i = 0; $i < 6; $i++)<span><i></i><b></b><small></small></span>@endfor</div>
                            </div>
                            <div class="landing-v4-cart">
                                <strong><x-icon name="cart" class="w-[14px] h-[14px]" />{{ __('landing.v4.visual.cart') }}</strong>
                                <i></i><i></i><i></i>
                                <button tabindex="-1">{{ __('landing.v4.visual.pay') }}</button>
                            </div>
                        </div>
                    </div>
                    <div class="landing-v3-pillar-copy">
                        <small>{{ __('landing.v3.why.sell.kicker') }}</small><h3>{{ __('landing.v3.why.sell.title') }}</h3><p>{{ __('landing.v3.why.sell.body') }}</p>
                    </div>
                </article>

                <article class="landing-v3-pillar is-control" data-reveal>
                    <div class="landing-v4-visual landing-v4-inventory" aria-hidden="true">
                        <div class="landing-v4-window-bar"><i></i><i></i><i></i><span>{{ __('landing.v4.visual.inventory') }}</span></div>
                        <div class="landing-v4-inventory-kpis">
                            <span><x-icon name="archive" class="w-[17px] h-[17px]" /><small>{{ __('landing.v3.inventory.tracking') }}</small><b>142</b></span>
                            <span><x-icon name="truck" class="w-[17px] h-[17px]" /><small>{{ __('landing.v3.inventory.purchasing') }}</small><b>+40</b></span>
                            <span class="is-alert"><x-icon name="bell" class="w-[17px] h-[17px]" /><small>{{ __('landing.v3.inventory.low_stock') }}</small><b>8</b></span>
                        </div>
                        <div class="landing-v4-stock-list">@foreach ([82, 64, 45, 26] as $level)<span><i style="--stock-level: {{ $level }}%"></i><b></b><small></small></span>@endforeach</div>
                    </div>
                    <div class="landing-v3-pillar-copy">
                        <small>{{ __('landing.v3.why.control.kicker') }}</small><h3>{{ __('landing.v3.why.control.title') }}</h3><p>{{ __('landing.v3.why.control.body') }}</p>
                    </div>
                </article>

                <article class="landing-v3-pillar is-anywhere" data-reveal>
                    <div class="landing-v4-visual landing-v4-anywhere" aria-hidden="true">
                        <div class="landing-v5-offline-status">
                            <span><i></i>{{ __('landing.v4.visual.internet_off') }}</span>
                            <strong>{{ __('landing.v4.visual.cashier_active') }}</strong>
                        </div>
                        <div class="landing-v5-offline-register">
                            <div class="landing-v5-offline-screen">
                                <div class="landing-v5-offline-screen-head"><x-icon name="pos" class="w-[17px] h-[17px]" /><strong>{{ __('landing.v4.visual.new_order') }}</strong><small>#1048</small></div>
                                <div class="landing-v5-offline-lines"><i></i><i></i><i></i></div>
                                <div class="landing-v5-offline-total"><span>{{ __('landing.v4.visual.total') }}</span><strong>$48.20</strong></div>
                                <div class="landing-v5-offline-button"><x-icon name="check" class="w-[14px] h-[14px]" />{{ __('landing.v4.visual.order_created') }}</div>
                            </div>
                            <div class="landing-v5-offline-receipt"><x-icon name="receipt" class="w-[22px] h-[22px]" /><i></i><i></i><i></i><strong>{{ __('landing.v4.visual.saved') }}</strong></div>
                        </div>
                        <div class="landing-v5-offline-flow">
                            <span><b><x-icon name="pos" class="w-[15px] h-[15px]" /></b>{{ __('landing.v4.visual.create_sale') }}</span>
                            <i><x-icon name="arrow-right" class="w-[14px] h-[14px]" /></i>
                            <span><b><x-icon name="database" class="w-[15px] h-[15px]" /></b>{{ __('landing.v4.visual.saved_local') }}</span>
                            <i><x-icon name="arrow-right" class="w-[14px] h-[14px]" /></i>
                            <span><b><x-icon name="refresh" class="w-[15px] h-[15px]" /></b>{{ __('landing.v4.visual.auto_sync') }}</span>
                        </div>
                    </div>
                    <div class="landing-v3-pillar-copy">
                        <small>{{ __('landing.v3.why.anywhere.kicker') }}</small><h3>{{ __('landing.v3.why.anywhere.title') }}</h3><p>{{ __('landing.v3.why.anywhere.body') }}</p>
                    </div>
                </article>

                <article class="landing-v3-pillar is-know" data-reveal>
                    <div class="landing-v4-visual landing-v4-analytics" aria-hidden="true">
                        <div class="landing-v4-window-bar"><i></i><i></i><i></i><span>{{ __('landing.v4.visual.reports') }}</span></div>
                        <div class="landing-v4-report-kpis">
                            @foreach ([['key' => 'sales', 'icon' => 'trending'], ['key' => 'profit', 'icon' => 'cash'], ['key' => 'stock', 'icon' => 'archive']] as $kpi)
                                <span><x-icon :name="$kpi['icon']" class="w-[15px] h-[15px]" /><small>{{ __('landing.v4.visual.' . $kpi['key']) }}</small><b></b></span>
                            @endforeach
                        </div>
                        <div class="landing-v4-chart"><i style="--bar:42%"></i><i style="--bar:68%"></i><i style="--bar:54%"></i><i style="--bar:82%"></i><i style="--bar:72%"></i><i style="--bar:94%"></i><i style="--bar:78%"></i></div>
                    </div>
                    <div class="landing-v3-pillar-copy">
                        <small>{{ __('landing.v3.why.know.kicker') }}</small><h3>{{ __('landing.v3.why.know.title') }}</h3><p>{{ __('landing.v3.why.know.body') }}</p>
                    </div>
                </article>
            </div>
        </section>

        {{-- ── Signature feature bento ─────────────────────── --}}
        <section class="landing-shell landing-section landing-v2-section">
            <div class="landing-v2-section-head" data-reveal>
                <span class="landing-eyebrow-text">{{ __('landing.v2.signature.eyebrow') }}</span>
                <h2 class="landing-section-title">{{ __('landing.v2.signature.title') }}</h2>
                <p class="landing-section-sub">{{ __('landing.v2.signature.subtitle') }}</p>
            </div>

            <div class="landing-v2-bento">
                <article class="landing-v2-feature landing-v2-feature-wide is-offline" data-reveal>
                    <div class="landing-v2-feature-copy">
                        <span class="landing-v2-feature-icon"><x-icon name="refresh" class="w-[22px] h-[22px]" /></span>
                        <span class="landing-v2-kicker">{{ __('landing.v2.signature.offline.kicker') }}</span>
                        <h3>{{ __('landing.v2.signature.offline.title') }}</h3>
                        <p>{{ __('landing.v2.signature.offline.body') }}</p>
                    </div>
                    <div class="landing-v3-offline-flow" aria-hidden="true">
                        @foreach ([
                            ['icon' => 'alert', 'key' => 'internet_off'],
                            ['icon' => 'cart', 'key' => 'keep_selling'],
                            ['icon' => 'database', 'key' => 'stored_local'],
                            ['icon' => 'globe', 'key' => 'internet_on'],
                            ['icon' => 'check-all', 'key' => 'auto_sync'],
                        ] as $offlineStep)
                            <span><i><x-icon :name="$offlineStep['icon']" class="w-[17px] h-[17px]" /></i><b>{{ __('landing.v3.offline.' . $offlineStep['key']) }}</b></span>
                        @endforeach
                    </div>
                </article>

                <article class="landing-v2-feature is-pricing" data-reveal>
                    <span class="landing-v2-feature-icon"><x-icon name="tag" class="w-[22px] h-[22px]" /></span>
                    <span class="landing-v2-kicker">{{ __('landing.v3.pricing_visual.kicker') }}</span>
                    <h3>{{ __('landing.v3.pricing_visual.title') }}</h3>
                    <p>{{ __('landing.v3.pricing_visual.body') }}</p>
                    <div class="landing-v3-price-math" aria-label="{{ __('landing.v3.pricing_visual.example_label') }}">
                        <span><small>{{ __('landing.v3.pricing_visual.cost') }}</small><strong>$100</strong></span>
                        <i>+</i>
                        <span><small>{{ __('landing.v3.pricing_visual.margin') }}</small><strong>20%</strong></span>
                        <i>=</i>
                        <span class="is-result"><small>{{ __('landing.v3.pricing_visual.selling') }}</small><strong>$120</strong></span>
                    </div>
                </article>

                <article class="landing-v2-feature is-location" data-reveal>
                    <span class="landing-v2-feature-icon"><x-icon name="store" class="w-[22px] h-[22px]" /></span>
                    <span class="landing-v2-kicker">{{ __('landing.v2.signature.locations.kicker') }}</span>
                    <h3>{{ __('landing.v2.signature.locations.title') }}</h3>
                    <p>{{ __('landing.v2.signature.locations.body') }}</p>
                    <div class="landing-v2-location-stack" aria-hidden="true">
                        <span><b>A3</b>{{ __('landing.v2.signature.locations.aisle') }}</span>
                        <span><b>F1</b>{{ __('landing.v2.signature.locations.fridge') }}</span>
                        <span><b>G2</b>{{ __('landing.v2.signature.locations.godown') }}</span>
                    </div>
                </article>

                <article class="landing-v2-feature is-layouts" data-reveal>
                    <span class="landing-v2-feature-icon"><x-icon name="grid" class="w-[22px] h-[22px]" /></span>
                    <span class="landing-v2-kicker">{{ __('landing.v2.signature.layouts.kicker') }}</span>
                    <h3>{{ __('landing.v2.signature.layouts.title') }}</h3>
                    <p>{{ __('landing.v2.signature.layouts.body') }}</p>
                    <div class="landing-v2-layout-switch" aria-hidden="true">
                        <span class="is-active">{{ __('landing.v2.signature.layouts.beam') }}</span>
                        <span>{{ __('landing.v2.signature.layouts.lane') }}</span>
                        <span>{{ __('landing.v2.signature.layouts.counter') }}</span>
                    </div>
                </article>

                <article class="landing-v2-feature is-fefo" data-reveal>
                    <span class="landing-v2-feature-icon"><x-icon name="archive" class="w-[22px] h-[22px]" /></span>
                    <span class="landing-v2-kicker">{{ __('landing.v2.signature.fefo.kicker') }}</span>
                    <h3>{{ __('landing.v2.signature.fefo.title') }}</h3>
                    <p>{{ __('landing.v2.signature.fefo.body') }}</p>
                    <div class="landing-v2-batch" aria-hidden="true">
                        <span><b>{{ __('landing.v2.signature.fefo.batch') }}</b><small>{{ __('landing.v2.signature.fefo.expiry') }}</small></span>
                        <strong>{{ __('landing.v2.signature.fefo.pick') }}</strong>
                    </div>
                </article>

                <article class="landing-v2-feature landing-v2-feature-wide is-display" data-reveal>
                    <div class="landing-v2-feature-copy">
                        <span class="landing-v2-feature-icon"><x-icon name="qr-code" class="w-[22px] h-[22px]" /></span>
                        <span class="landing-v2-kicker">{{ __('landing.v2.signature.display.kicker') }}</span>
                        <h3>{{ __('landing.v2.signature.display.title') }}</h3>
                        <p>{{ __('landing.v2.signature.display.body') }}</p>
                    </div>
                    <div class="landing-v2-display-demo" aria-hidden="true">
                        <div class="landing-v2-mini-device"><x-icon name="cart" class="w-[25px] h-[25px]" /><b>{{ __('landing.v2.signature.display.cart') }}</b></div>
                        <span><x-icon name="arrow-right" class="w-[18px] h-[18px]" /></span>
                        <div class="landing-v2-mini-device is-customer"><x-icon name="qr-code" class="w-[34px] h-[34px]" /><b>{{ __('landing.v2.signature.display.pay') }}</b></div>
                    </div>
                </article>

                <article class="landing-v2-feature landing-v2-feature-wide landing-v3-inventory-card" data-reveal>
                    <div class="landing-v2-feature-copy">
                        <span class="landing-v2-feature-icon"><x-icon name="archive" class="w-[22px] h-[22px]" /></span>
                        <span class="landing-v2-kicker">{{ __('landing.v3.inventory.kicker') }}</span>
                        <h3>{{ __('landing.v3.inventory.title') }}</h3>
                        <p>{{ __('landing.v3.inventory.body') }}</p>
                        <div class="landing-v3-inventory-chips">
                            @foreach (['tracking', 'purchasing', 'adjustments', 'low_stock', 'history'] as $item)
                                <span>{{ __('landing.v3.inventory.' . $item) }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="landing-v3-stock-flow" aria-hidden="true">
                        @foreach ([
                            ['icon' => 'box', 'key' => 'stock', 'value' => '120'],
                            ['icon' => 'truck', 'key' => 'purchase', 'value' => '+40'],
                            ['icon' => 'cart', 'key' => 'sale', 'value' => '-18'],
                            ['icon' => 'archive', 'key' => 'remaining', 'value' => '142'],
                        ] as $stockStep)
                            <span><i><x-icon :name="$stockStep['icon']" class="w-[18px] h-[18px]" /></i><small>{{ __('landing.v3.inventory.' . $stockStep['key']) }}</small><strong>{{ $stockStep['value'] }}</strong></span>
                        @endforeach
                    </div>
                </article>
            </div>
        </section>

        {{-- ── Sale flow ───────────────────────────────────── --}}
        <section class="landing-shell landing-section landing-v2-section">
            <div class="landing-v2-flow" data-reveal>
                <div class="landing-v2-flow-intro">
                    <span class="landing-eyebrow-text">{{ __('landing.v3.workflow.eyebrow') }}</span>
                    <h2>{{ __('landing.v3.workflow.title') }}</h2>
                    <p>{{ __('landing.v3.workflow.subtitle') }}</p>
                </div>
                <div class="landing-v2-flow-track">
                    @foreach ([
                        ['icon' => 'box', 'key' => 'product'],
                        ['icon' => 'truck', 'key' => 'purchase'],
                        ['icon' => 'archive', 'key' => 'inventory'],
                        ['icon' => 'pos', 'key' => 'pos_sale'],
                        ['icon' => 'card', 'key' => 'payment'],
                        ['icon' => 'receipt', 'key' => 'receipt'],
                        ['icon' => 'bar', 'key' => 'report'],
                    ] as $n => $step)
                        <div class="landing-v2-flow-step">
                            <span><x-icon :name="$step['icon']" class="w-[22px] h-[22px]" /></span>
                            <small>0{{ $n + 1 }}</small>
                            <strong>{{ __('landing.v3.workflow.' . $step['key']) }}</strong>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ── Industry switcher ───────────────────────────── --}}
        <section id="industries" class="landing-shell landing-section landing-v2-section">
            <div class="landing-v2-section-head" data-reveal>
                <span class="landing-eyebrow-text">{{ __('landing.v2.industries.eyebrow') }}</span>
                <h2 class="landing-section-title">{{ __('landing.v2.industries.title') }}</h2>
                <p class="landing-section-sub">{{ __('landing.v2.industries.subtitle') }}</p>
            </div>

            <div class="landing-v2-industry" data-industry-tabs data-reveal>
                <div class="landing-v2-industry-tabs" role="tablist" aria-label="{{ __('landing.v2.industries.tabs_label') }}">
                    @foreach ([['icon' => 'tag', 'key' => 'retail'], ['icon' => 'cart', 'key' => 'grocery'], ['icon' => 'calculator', 'key' => 'supermarket'], ['icon' => 'shield', 'key' => 'pharmacy']] as $index => $industry)
                        <button type="button" role="tab" id="industry-tab-{{ $industry['key'] }}" aria-controls="industry-panel-{{ $industry['key'] }}" data-industry-tab="{{ $industry['key'] }}" aria-selected="{{ $index === 0 ? 'true' : 'false' }}" tabindex="{{ $index === 0 ? '0' : '-1' }}" class="landing-v2-industry-tab {{ $index === 0 ? 'is-active' : '' }}">
                            <x-icon :name="$industry['icon']" class="w-[19px] h-[19px]" />
                            {{ __('landing.v2.industries.' . $industry['key'] . '.title') }}
                        </button>
                    @endforeach
                </div>

                @foreach ([
                    'retail' => $heroShot,
                    'grocery' => asset('assets/documentation/images/weight-prompt.png'),
                    'supermarket' => asset('assets/documentation/images/inventory-levels.png'),
                    'pharmacy' => asset('assets/documentation/images/drug-schedules.png'),
                ] as $indexKey => $image)
                    <div id="industry-panel-{{ $indexKey }}" class="landing-v2-industry-panel {{ $loop->first ? 'is-active' : '' }}" role="tabpanel" aria-labelledby="industry-tab-{{ $indexKey }}" data-industry-panel="{{ $indexKey }}" @unless($loop->first) hidden @endunless>
                        <div class="landing-v2-industry-copy">
                            <span>{{ __('landing.v2.industries.' . $indexKey . '.eyebrow') }}</span>
                            <h3>{{ __('landing.v2.industries.' . $indexKey . '.headline') }}</h3>
                            <p>{{ __('landing.v2.industries.' . $indexKey . '.body') }}</p>
                            <div class="landing-v2-industry-points">
                                @foreach (['point_1', 'point_2', 'point_3'] as $point)
                                    <small><x-icon name="check" class="w-[14px] h-[14px]" />{{ __('landing.v2.industries.' . $indexKey . '.' . $point) }}</small>
                                @endforeach
                            </div>
                        </div>
                        <figure class="landing-v2-industry-shot"><img src="{{ $image }}" alt="{{ __('landing.v2.industries.' . $indexKey . '.alt') }}" loading="lazy"></figure>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ── Online + offline — one business system ─────── --}}
        <section class="landing-shell landing-section landing-v2-section">
            <div class="landing-v2-section-head" data-reveal>
                <span class="landing-eyebrow-text">{{ __('landing.v3.unified.eyebrow') }}</span>
                <h2 class="landing-section-title">{{ __('landing.v3.unified.title') }}</h2>
                <p class="landing-section-sub">{{ __('landing.v3.unified.subtitle') }}</p>
            </div>
            <div class="landing-v3-unified" data-reveal>
                <article class="landing-v3-unified-side is-online">
                    <header><span><x-icon name="globe" class="w-[21px] h-[21px]" /></span><div><small>{{ __('landing.v3.unified.online_kicker') }}</small><h3>{{ __('landing.v3.unified.online') }}</h3></div></header>
                    <div class="landing-v3-unified-brands">
                        <img src="{{ asset('assets/providers/shopify.png') }}" alt="Shopify">
                        <img src="{{ asset('assets/providers/woocommerce.png') }}" alt="WooCommerce">
                    </div>
                    @foreach (['online_orders', 'inventory_sync'] as $item)
                        <span class="landing-v3-unified-item"><x-icon name="check" class="w-[14px] h-[14px]" />{{ __('landing.v3.unified.' . $item) }}</span>
                    @endforeach
                </article>
                <div class="landing-v3-unified-core">
                    <i aria-hidden="true"></i>
                    <span><x-icon name="refresh" class="w-[25px] h-[25px]" /></span>
                    <strong>{{ __('landing.v3.unified.one_system') }}</strong>
                    <small>{{ $appName }}</small>
                </div>
                <article class="landing-v3-unified-side is-offline">
                    <header><span><x-icon name="pos" class="w-[21px] h-[21px]" /></span><div><small>{{ __('landing.v3.unified.offline_kicker') }}</small><h3>{{ __('landing.v3.unified.offline') }}</h3></div></header>
                    <div class="landing-v3-local-data"><x-icon name="database" class="w-[28px] h-[28px]" /><span><strong>{{ __('landing.v3.unified.local_data') }}</strong><small>{{ __('landing.v3.unified.saved_here') }}</small></span></div>
                    @foreach (['offline_sales', 'automatic_sync'] as $item)
                        <span class="landing-v3-unified-item"><x-icon name="check" class="w-[14px] h-[14px]" />{{ __('landing.v3.unified.' . $item) }}</span>
                    @endforeach
                </article>
            </div>
            <div class="landing-v3-mid-cta" data-reveal>
                <span>{{ __('landing.v3.mid_cta.text') }}</span>
                <a href="{{ $appUrl }}" target="_blank" rel="noopener" class="landing-btn landing-btn-primary">{{ __('landing.v3.mid_cta.button') }}<x-icon name="arrow-right" class="w-[17px] h-[17px]" /></a>
            </div>
        </section>

        {{-- ── Dashboard spotlight ─────────────────────────── --}}
        @if ($dashboardShot)
            <section class="landing-shell landing-section landing-v2-section">
                <div class="landing-v2-dashboard" data-reveal>
                    <div class="landing-v2-dashboard-copy">
                        <span class="landing-eyebrow-text">{{ __('landing.v2.dashboard.eyebrow') }}</span>
                        <h2>{{ __('landing.v2.dashboard.title') }}</h2>
                        <p>{{ __('landing.v2.dashboard.subtitle') }}</p>
                        <div class="landing-v2-dashboard-points">
                            @foreach (['sales', 'stock', 'activity'] as $point)
                                <span>{{ __('landing.v2.dashboard.' . $point) }}</span>
                            @endforeach
                        </div>
                    </div>
                    <figure class="landing-v2-dashboard-shot"><img src="{{ $dashboardShot }}" alt="{{ __('landing.showcase.alt') }}" loading="lazy"></figure>
                </div>
            </section>
        @endif

        {{-- ── Screenshot stories ──────────────────────────── --}}
        @if (! empty($galleryShots))
            <section id="screens" class="landing-shell landing-section landing-v2-section">
                <div class="landing-v2-section-head" data-reveal>
                    <span class="landing-eyebrow-text">{{ __('landing.v2.gallery.eyebrow') }}</span>
                    <h2 class="landing-section-title">{{ __('landing.v2.gallery.title') }}</h2>
                    <p class="landing-section-sub">{{ __('landing.v2.gallery.subtitle') }}</p>
                </div>
                <div class="landing-slider landing-v2-slider" data-slider data-reveal>
                    <button type="button" class="landing-slider-btn landing-slider-prev" data-slider-prev aria-label="{{ __('landing.gallery.prev') }}"><x-icon name="back" class="w-[18px] h-[18px]" /></button>
                    <div class="landing-slider-track" data-slider-track>
                        @foreach ($galleryShots as $shot)
                            <figure class="landing-slide">
                                <span class="landing-slide-frame"><img src="{{ $shot['url'] }}" alt="{{ $shot['label'] }}" class="landing-slide-img" loading="lazy"></span>
                                <figcaption class="landing-slide-cap"><small>{{ __('landing.v2.gallery.real_screen') }}</small>{{ $shot['label'] }}</figcaption>
                            </figure>
                        @endforeach
                    </div>
                    <button type="button" class="landing-slider-btn landing-slider-next" data-slider-next aria-label="{{ __('landing.gallery.next') }}"><x-icon name="arrow-right" class="w-[18px] h-[18px]" /></button>
                </div>
            </section>

            <div class="landing-lightbox" data-lightbox hidden>
                <button type="button" class="landing-lightbox-close" data-lb-close aria-label="{{ __('landing.gallery.close') }}"><x-icon name="x" class="w-[22px] h-[22px]" /></button>
                <button type="button" class="landing-lightbox-nav landing-lightbox-prev" data-lb-prev aria-label="{{ __('landing.gallery.prev') }}"><x-icon name="back" class="w-[22px] h-[22px]" /></button>
                <figure class="landing-lightbox-stage"><img data-lb-img src="" alt=""><figcaption class="landing-lightbox-cap" data-lb-cap></figcaption></figure>
                <button type="button" class="landing-lightbox-nav landing-lightbox-next" data-lb-next aria-label="{{ __('landing.gallery.next') }}"><x-icon name="arrow-right" class="w-[22px] h-[22px]" /></button>
            </div>
        @endif

        {{-- ── Compact capability grid ─────────────────────── --}}
        <section id="everything" class="landing-shell landing-section landing-v2-section">
            <div class="landing-v2-section-head" data-reveal>
                <span class="landing-eyebrow-text">{{ __('landing.v2.everything.eyebrow') }}</span>
                <h2 class="landing-section-title">{{ __('landing.v2.everything.title') }}</h2>
                <p class="landing-section-sub">{{ __('landing.v2.everything.subtitle') }}</p>
            </div>
            <div class="landing-v2-capabilities" data-reveal>
                @foreach ([
                    ['icon' => 'customers', 'key' => 'loyalty'],
                    ['icon' => 'receipt', 'key' => 'quotations'],
                    ['icon' => 'cash', 'key' => 'accounting'],
                    ['icon' => 'truck', 'key' => 'suppliers'],
                    ['icon' => 'check-all', 'key' => 'blind_counts'],
                    ['icon' => 'qr-code', 'key' => 'kiosk'],
                    ['icon' => 'refund', 'key' => 'refunds'],
                    ['icon' => 'pie', 'key' => 'tax'],
                    ['icon' => 'lock', 'key' => 'roles'],
                    ['icon' => 'bell', 'key' => 'reports'],
                    ['icon' => 'upload', 'key' => 'imports'],
                    ['icon' => 'database', 'key' => 'backups'],
                ] as $capability)
                    <div class="landing-v2-capability"><span><x-icon :name="$capability['icon']" class="w-[19px] h-[19px]" /></span><strong>{{ __('landing.v2.everything.' . $capability['key']) }}</strong></div>
                @endforeach
            </div>
        </section>

        {{-- ── Trust without invented statistics ─────────── --}}
        <section class="landing-shell landing-v3-trust" data-reveal>
            <div class="landing-v3-trust-copy">
                <span class="landing-eyebrow-text">{{ __('landing.v3.trust.eyebrow') }}</span>
                <h2>{{ __('landing.v3.trust.title') }}</h2>
                <div>
                    <span><x-icon name="shield" class="w-[16px] h-[16px]" />{{ __('landing.v3.trust.private') }}</span>
                    <span><x-icon name="database" class="w-[16px] h-[16px]" />{{ __('landing.v3.trust.owned') }}</span>
                    <span><x-icon name="refresh" class="w-[16px] h-[16px]" />{{ __('landing.v3.trust.modern') }}</span>
                </div>
            </div>
            <div class="landing-v3-trust-logos">
                <div class="landing-v3-trust-integrations">
                    <img src="{{ asset('assets/providers/shopify.png') }}" alt="Shopify">
                    <img src="{{ asset('assets/providers/woocommerce.png') }}" alt="WooCommerce">
                </div>
                @foreach ($paymentMethods as $payment)
                    @if ($payment['logo'])
                        <span><img src="{{ $payment['logo'] }}" alt="{{ $payment['label'] }}" loading="lazy"></span>
                    @endif
                @endforeach
            </div>
        </section>

        {{-- ── Pricing / license comparison ────────────────── --}}
        <section id="pricing" class="landing-shell landing-section">
            <div class="landing-section-head" data-reveal>
                <span class="landing-eyebrow-text">{{ __('landing.pricing.eyebrow') }}</span>
                <h2 class="landing-section-title">{{ __('landing.pricing.title') }}</h2>
                <p class="landing-section-sub">{{ __('landing.pricing.subtitle') }}</p>
            </div>

            <div class="landing-pricing" data-reveal>
                <div class="landing-pricing-scroll">
                    <table class="landing-pricing-table">
                        <thead>
                            <tr>
                                <th>{{ __('landing.pricing.col_feature') }}</th>
                                <th class="col-mark">{{ __('landing.pricing.col_regular') }}</th>
                                <th class="col-mark is-rec">
                                    {{ __('landing.pricing.col_extended') }}
                                    <span class="landing-pricing-badge">{{ __('landing.pricing.recommended') }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ([
                                ['key' => 'lifetime',   'r' => true,  'e' => true],
                                ['key' => 'domain',     'r' => true,  'e' => true],
                                ['key' => 'support',    'r' => true,  'e' => true],
                                ['key' => 'premium',    'r' => true,  'e' => true],
                                ['key' => 'updates',    'r' => true,  'e' => true],
                                ['key' => 'source',     'r' => true,  'e' => true],
                                ['key' => 'personal',   'r' => true,  'e' => true],
                                ['key' => 'install',    'r' => false, 'e' => true],
                                ['key' => 'remote',     'r' => false, 'e' => true],
                                ['key' => 'priority',   'r' => false, 'e' => true],
                                ['key' => 'branding',   'r' => false, 'e' => true],
                                ['key' => 'commercial', 'r' => false, 'e' => true],
                            ] as $row)
                                <tr>
                                    <td>{{ __('landing.pricing.rows.' . $row['key']) }}</td>
                                    <td class="col-mark">
                                        <span class="landing-mark {{ $row['r'] ? 'landing-mark-yes' : 'landing-mark-no' }}">
                                            <x-icon :name="$row['r'] ? 'check' : 'x'" class="w-[14px] h-[14px]" />
                                        </span>
                                    </td>
                                    <td class="col-mark is-rec">
                                        <span class="landing-mark {{ $row['e'] ? 'landing-mark-yes' : 'landing-mark-no' }}">
                                            <x-icon :name="$row['e'] ? 'check' : 'x'" class="w-[14px] h-[14px]" />
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($extendedUrl)
                    <div class="landing-pricing-band">
                        <span class="landing-pricing-band-text">{{ __('landing.pricing.band_text') }}</span>
                        <a href="{{ $extendedUrl }}" target="_blank" rel="noopener" class="landing-btn landing-btn-primary">
                            {{ __('landing.pricing.band_cta') }}
                            <x-icon name="arrow-right" class="w-[18px] h-[18px]" />
                        </a>
                    </div>
                @endif
            </div>
        </section>

        {{-- ── Lead-gated ebook download ───────────────────── --}}
        <section id="free-ebook" class="landing-shell landing-section">
            <div class="landing-ebook" data-reveal>
                <div class="landing-ebook-header">
                    <span class="landing-eyebrow-text">{{ __('landing.ebook.eyebrow') }}</span>
                    <h2 class="landing-ebook-title">{{ __('landing.ebook.title') }}</h2>
                    <p class="landing-ebook-sub">{{ __('landing.ebook.subtitle') }}</p>
                </div>

                <form method="POST" action="{{ route('landing.ebook.download') }}"
                      class="landing-ebook-form" data-ebook-form novalidate>
                    @csrf

                    <div class="landing-ebook-field">
                        <label for="ebook-name" class="sr-only">{{ __('landing.ebook.fields.name') }}</label>
                        <input id="ebook-name" name="name" type="text" required minlength="2" maxlength="120"
                               autocomplete="name" value="{{ old('name') }}"
                               placeholder="{{ __('landing.ebook.fields.name') }}"
                               class="landing-ebook-input @error('name') is-invalid @enderror"
                               @error('name') aria-invalid="true" aria-describedby="ebook-name-error" @enderror>
                        @error('name')
                            <p id="ebook-name-error" class="landing-ebook-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="landing-ebook-field">
                        <label for="ebook-email" class="sr-only">{{ __('landing.ebook.fields.email') }}</label>
                        <input id="ebook-email" name="email" type="email" required maxlength="254"
                               autocomplete="email" value="{{ old('email') }}"
                               placeholder="{{ __('landing.ebook.fields.email') }}"
                               class="landing-ebook-input @error('email') is-invalid @enderror"
                               @error('email') aria-invalid="true" aria-describedby="ebook-email-error" @enderror>
                        @error('email')
                            <p id="ebook-email-error" class="landing-ebook-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="landing-ebook-field">
                        <label for="ebook-country" class="sr-only">{{ __('landing.ebook.fields.country') }}</label>
                        <select id="ebook-country" name="country" required autocomplete="country"
                                class="landing-ebook-input landing-ebook-select @error('country') is-invalid @enderror"
                                @error('country') aria-invalid="true" aria-describedby="ebook-country-error" @enderror>
                            <option value="">{{ __('landing.ebook.fields.country_hint') }}</option>
                            @foreach ($countries as $code => $country)
                                <option value="{{ $code }}" @selected(old('country') === $code)>{{ $country }}</option>
                            @endforeach
                        </select>
                        @error('country')
                            <p id="ebook-country-error" class="landing-ebook-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Honeypot field: hidden from people, tempting to basic form bots. --}}
                    <div class="landing-ebook-trap" aria-hidden="true">
                        <label for="ebook-website">{{ __('landing.ebook.honeypot') }}</label>
                        <input id="ebook-website" name="website" type="text" tabindex="-1" autocomplete="off">
                    </div>

                    @error('ebook')
                        <p class="landing-ebook-alert" role="alert">{{ $message }}</p>
                    @enderror

                    <p class="landing-ebook-alert" role="alert" hidden data-ebook-client-error
                       data-default-message="{{ __('landing.ebook.errors.download') }}"></p>

                    <button type="submit" class="landing-ebook-submit" data-ebook-submit
                            @disabled(! $ebookAvailable)
                            data-loading-text="{{ __('landing.ebook.submitting') }}"
                            data-download-name="{{ $ebookDownloadName }}">
                        <span data-ebook-submit-text>{{ __('landing.ebook.button') }}</span>
                        <span class="landing-ebook-spinner" aria-hidden="true"></span>
                    </button>

                    @unless ($ebookAvailable)
                        <p class="landing-ebook-notice">{{ __('landing.ebook.not_ready') }}</p>
                    @endunless

                    <p class="landing-ebook-privacy">
                        <span aria-hidden="true">&#128274;</span>
                        {{ __('landing.ebook.privacy') }}
                    </p>
                </form>
            </div>
        </section>

        {{-- ── CTA band — drives the purchase first, demo second ─ --}}
        <section class="landing-shell landing-section">
            <div class="landing-cta" data-reveal>
                <h2 class="landing-cta-title">{{ __('landing.cta.title') }}</h2>
                <p class="landing-cta-sub">{{ __('landing.cta.subtitle') }}</p>
                <div class="landing-cta-actions">
                    @if ($purchaseUrl)
                        <a href="{{ $purchaseUrl }}" target="_blank" rel="noopener"
                           class="landing-btn landing-btn-primary landing-btn-lg">
                            {{ __($isInfinitietech ? 'landing.nav.buy' : 'landing.cta.buy') }}
                            <x-icon name="arrow-right" class="w-[18px] h-[18px]" />
                        </a>
                    @endif
                    <a href="{{ $appUrl }}" target="_blank" rel="noopener"
                       class="landing-btn {{ $purchaseUrl ? 'landing-btn-ghost' : 'landing-btn-primary' }} landing-btn-lg">
                        {{ $isAuthed ? __('landing.go_to_dashboard') : __('landing.cta.button') }}
                        @unless ($purchaseUrl)<x-icon name="arrow-right" class="w-[18px] h-[18px]" />@endunless
                    </a>
                </div>
                @if ($purchaseUrl)
                    <p class="landing-cta-note">{{ __('landing.cta.note') }}</p>
                @endif
            </div>
        </section>

        {{-- ── Customization / setup help (WhatsApp) ───────── --}}
        @if ($whatsappUrl)
            <section class="landing-shell landing-section">
                <div class="landing-help" data-reveal>
                    <h2 class="landing-help-title">{{ __('landing.help.title') }}</h2>
                    <p class="landing-help-body">{{ __('landing.help.body') }}</p>
                    <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener"
                       class="landing-btn landing-btn-primary landing-btn-lg">
                        {{ __('landing.help.cta') }}
                        <x-icon name="arrow-right" class="w-[18px] h-[18px]" />
                    </a>
                </div>
            </section>
        @endif
    </main>

    {{-- ── Footer ─────────────────────────────────────────── --}}
    <footer class="landing-footer">
        <div class="landing-shell landing-footer-inner">
            <div class="landing-footer-brand">
                @if ($logo && $logoDark)
                    <img src="{{ $logo }}"     alt="{{ $appName }}" class="landing-brand-logo landing-brand-logo-light">
                    <img src="{{ $logoDark }}" alt="{{ $appName }}" class="landing-brand-logo landing-brand-logo-dark">
                @elseif ($logo || $logoDark)
                    <img src="{{ $logo ?: $logoDark }}" alt="{{ $appName }}" class="landing-brand-logo">
                @else
                    <span class="landing-brand-mark">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($appName, 0, 1)) }}</span>
                    <span>{{ $appName }}</span>
                @endif
            </div>
            <p class="landing-footer-tagline">{{ __('landing.footer.tagline') }}</p>

            <div class="landing-footer-links">
                <a href="{{ config('pos.documentation_url') }}" class="landing-footer-link" target="_blank" rel="noopener">
                    {{ __('landing.footer.docs') }}
                </a>
                @if ($purchaseUrl)
                    <a href="{{ $purchaseUrl }}" class="landing-footer-link" target="_blank" rel="noopener">
                        {{ __($isInfinitietech ? 'landing.nav.buy' : 'landing.footer.buy') }}
                    </a>
                @endif
                @if ($websiteUrl)
                    <a href="{{ $websiteUrl }}" class="landing-footer-link" target="_blank" rel="noopener">
                        {{ __('landing.footer.product') }}
                    </a>
                @endif
                @if ($company && trim((string) $company->privacy_policy) !== '')
                    <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener" class="landing-footer-link">{{ __('landing.footer.privacy') }}</a>
                @endif
                @if ($company && trim((string) $company->terms_of_service) !== '')
                    <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener" class="landing-footer-link">{{ __('landing.footer.terms') }}</a>
                @endif
            </div>

            <p class="landing-footer-copy">
                &copy; {{ now()->year }} {{ $appName }}. {{ __('landing.footer.rights') }}
            </p>
            <p class="landing-footer-made">
                {{-- Escaped before our own markup is spliced in (SEC #19). --}}
                {!! trans_with_html('landing.footer.made_by', [
                    'heart'   => '<span class="landing-footer-heart">&hearts;</span>',
                    'company' => '<a href="https://infinitietech.com/" target="_blank" rel="noopener" class="landing-footer-link">Infinitie Technologies</a>',
                ]) !!}
            </p>
        </div>
    </footer>

    {{-- Floating "Buy now" CTA — mirrors the admin demo panel's floating
         button so the demo always has a one-tap path to purchase. Only shown
         when a purchase URL is configured. --}}
    @if ($purchaseUrl)
        <a href="{{ $purchaseUrl }}" target="_blank" rel="noopener"
           class="landing-buy-now" aria-label="{{ __('landing.buy_now') }}">
            <x-icon name="cart" class="w-[18px] h-[18px]" />
            <span class="landing-buy-now-label">{{ __('landing.buy_now') }}</span>
        </a>
    @endif

    {{-- Back-to-top — JS toggles .is-visible past a scroll threshold. --}}
    <button type="button" class="landing-totop" data-totop aria-label="{{ __('landing.totop') }}">
        <x-icon name="chevron" class="w-[20px] h-[20px] landing-icon-up" />
    </button>
    @include('partials.custom-scripts', ['position' => 'footer', 'surface' => 'public'])
</body>
</html>
