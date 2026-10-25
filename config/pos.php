<?php

return [
    // A separately approved pre-business host adapter must also be bound.
    // Core-only installs keep their existing wizard when this is disabled.
    'fresh_saas_installer' => [
        'enabled' => (bool) env('POS_FRESH_SAAS_INSTALLER', true),
        // Host preparation supplies a private, sealed source directory.
        // Empty values keep the optional pre-business adapter unavailable.
        'source_directory' => env('POS_FRESH_SAAS_SOURCE_DIRECTORY', ''),
        // Publisher trust is shipped with Hyper-POS core. Customers never enter or configure it.
        'publisher_trust_file' => base_path('resources/security/hyper-pos-saas-publisher.sha256'),
        // Private APP_KEY-authenticated FI02 host identity and bridge configuration.
        'runtime_configuration' => env('POS_FRESH_SAAS_RUNTIME_CONFIGURATION', ''),
        'vps' => [
            'ready' => (bool) env('POS_FRESH_SAAS_VPS_READY', false),
        ],
        'shared' => [
            'ready' => (bool) env('POS_FRESH_SAAS_SHARED_READY', false),
            'management_defaults' => [
                'country_code' => env('POS_FRESH_SAAS_COUNTRY', 'US'),
                'currency_code' => env('POS_FRESH_SAAS_CURRENCY', 'USD'),
                'timezone' => env('POS_FRESH_SAAS_TIMEZONE', 'UTC'),
                'locale' => env('POS_FRESH_SAAS_LOCALE', 'en'),
            ],
            'database' => [
                'host' => env('POS_FRESH_SAAS_SHARED_DB_HOST', ''),
                'port' => (int) env('POS_FRESH_SAAS_SHARED_DB_PORT', 3306),
                'database' => env('POS_FRESH_SAAS_SHARED_DB_DATABASE', ''),
                'username' => env('POS_FRESH_SAAS_SHARED_DB_USERNAME', ''),
                'password' => env('POS_FRESH_SAAS_SHARED_DB_PASSWORD', ''),
            ],
        ],
        // Staging-only fresh SaaS rehearsal option. Production customers must
        // validate their Hyper-POS purchase code through the ordinary step.
        'core_purchase_launch_bypass' => (bool) env('POS_FRESH_SAAS_CORE_PURCHASE_LAUNCH_BYPASS', false),
        // Explicit fresh-installer rehearsal switches. Both default off.
        'test_purchase_bypass' => (bool) env('POS_FRESH_SAAS_TEST_PURCHASE_BYPASS', false),
        'test_https_bypass' => (bool) env('POS_FRESH_SAAS_TEST_HTTPS_BYPASS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application version
    |--------------------------------------------------------------------------
    |
    | The canonical version of this install. Everything that needs to know
    | "what version am I?" reads `config('pos.version')` — the updater's
    | feed comparison, the backup manifest, the footer, support diagnostics.
    |
    | This ships inside config/pos.php (which the updater REPLACES on every
    | update), so a new release naturally carries its own new version number.
    | Bump it here when cutting a release. Format: semver (MAJOR.MINOR.PATCH).
    |
    */

    'version' => '1.1.4',

    /*
    |--------------------------------------------------------------------------
    | Product name
    |--------------------------------------------------------------------------
    |
    | The product's own display name — used where we must brand as the product
    | itself rather than the customer's company, most notably the installer
    | (which runs before any company exists, so `config('app.name')` is still
    | the framework default "Laravel" there). One place to change when the
    | final name is locked. The running app shows the company name instead, via
    | ApplyCompanySettings.
    |
    */

    'name' => 'POS',

    /*
    |--------------------------------------------------------------------------
    | Documentation URL
    |--------------------------------------------------------------------------
    |
    | Where every "Documentation" link points (admin footer, landing page nav
    | and footer). This is the hosted manual rather than the copy bundled at
    | /documentation, so customers always read the latest version even if their
    | install is behind. Override via .env for forks or to point back at the
    | bundled docs.
    |
    */

    'documentation_url' => env('POS_DOCUMENTATION_URL', 'https://hyper-pos.eshopweb.store/documentation/'),

    /*
    |--------------------------------------------------------------------------
    | Developer / maintenance routes
    |--------------------------------------------------------------------------
    |
    | The web equivalents of the artisan commands — /migrate, /seed,
    | /clear-cache, /phpinfo and the demo baseline routes. They
    | exist because this product targets shared hosting, where SSH is often
    | unavailable and the only way to run a migration is over HTTP.
    |
    | They are DANGEROUS by nature (/migrate?fresh=1 drops every table), so
    | `routes/dev.php` is only loaded into the router when `enabled` is true.
    | On a customer install the key is absent, the file is never read, and the
    | routes do not exist at all — not even as a 404-guarded closure. Never
    | ship a .env with POS_DEV_ROUTES=true.
    |
    | The destructive subset (fresh migrate, seed, demo reset/snapshot) needs a
    | second factor on top of the flag: either `?token=` matching POS_DEV_TOKEN,
    | or a signed-in super admin. The token path exists because the very first
    | migrate runs against an empty database where no user can exist yet.
    |
    */

    'dev' => [

        // Master switch. Loads routes/dev.php when true. Leave unset (false)
        // on anything a customer can reach.
        'routes_enabled' => env('POS_DEV_ROUTES', false),

        // Shared secret for the destructive routes, passed as ?token=. Leave
        // empty to require a signed-in super admin instead (which is not an
        // option on a freshly-migrated, userless database).
        'token' => env('POS_DEV_TOKEN', ''),

    ],

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the login page shows a small notice with a link out to the
    | configured marketing site — so visitors who land on the live demo know
    | they're looking at a demo and can find their way back to the product
    | website. The toggle is environment-driven (.env) so the same code can
    | run as either the live demo or a real install without code changes.
    |
    */

    'demo' => [

        // Master switch. Set POS_DEMO_MODE=true in .env on the public demo
        // install; leave it false (the default) on customer installs.
        'enabled' => env('POS_DEMO_MODE', false),

        // Where the "click here" link in the demo banner points. Defaults to
        // the Infinitie product page; override for forks via .env.
        'website_url' => env('POS_DEMO_WEBSITE_URL', 'https://infinitietech.com'),

        // Google Analytics 4 Measurement ID (e.g. "G-XXXXXXXXXX") for the
        // public demo. Left empty by default and ONLY ever loaded when demo
        // mode is on — a real customer install never phones home to GA, which
        // keeps the "self-hosted, no callbacks" promise intact. Set
        // POS_DEMO_GA_ID in .env on the demo install to enable tracking.
        'analytics_id' => env('POS_DEMO_GA_ID', ''),

        // Where the landing page's "Get it on CodeCanyon" buy buttons point.
        // This is the whole reason the demo exists — to convert a visitor
        // into a purchase — so it's first-class config. Override for forks
        // via .env.
        'purchase_url' => env('POS_DEMO_PURCHASE_URL', 'https://1.envato.market/JkYbVR'),

        // Regular-license cart for landing visits with ?market=infinitietech.
        'infinitietech_purchase_url' => 'https://infinitietech.com/cart?product=hyper-pos-laravel-point-of-sale-inventory-erp-online-offline-pos-invoicing-accounting&license=regular',
        'infinitietech_extended_url' => 'https://infinitietech.com/cart?product=hyper-pos-laravel-point-of-sale-inventory-erp-online-offline-pos-invoicing-accounting&license=extended',

        // Specific "Extended License" buy link used by the pricing band.
        // Falls back to purchase_url when left unset.
        'extended_url' => env('POS_DEMO_EXTENDED_URL', 'https://codecanyon.net/cart/add_items?item_ids=64055507&license=extended'),

        // "Talk to our expert" button on the landing page's customization
        // band. A wa.me link opens WhatsApp; swap for any contact URL.
        'support_whatsapp' => env('POS_DEMO_SUPPORT_WHATSAPP', 'https://wa.me/919328972168'),

        // Private lead-gated ebook. Keep the PDF outside public/ so visitors
        // cannot bypass the landing form by guessing a direct asset URL.
        'ebook_pdf_path' => env(
            'POS_DEMO_EBOOK_PDF_PATH',
            storage_path('app/private/downloads/hyper-pos-ebook.pdf'),
        ),
        'ebook_download_name' => env('POS_DEMO_EBOOK_DOWNLOAD_NAME', 'Hyper-POS-eBook.pdf'),

        // Leads are kept on this installation rather than sent to a third
        // party. The CSV sits under storage/app/private by default.
        'ebook_leads_path' => env(
            'POS_DEMO_EBOOK_LEADS_PATH',
            storage_path('app/private/marketing/ebook-leads.csv'),
        ),
        // Only the SHA-256 verifier ships in source. The share URL carries the
        // secret token; override this hash in .env whenever the link is rotated.
        'ebook_leads_download_token_hash' => env(
            'POS_DEMO_EBOOK_LEADS_DOWNLOAD_TOKEN_HASH',
            '64d005f09fa1bc871bbd028b82ed3e881b8264619a796822bce11a3fb2481dae',
        ),

        // Pre-fillable demo credentials shown on the login page when demo mode
        // is enabled. Each entry renders as a card with a "Copy & Fill" button
        // that pastes the email + password into the login form. Empty entries
        // (no email OR no password) are skipped at render time, so the demo
        // operator can light up just the roles they actually seeded by leaving
        // the others blank in .env.
        'credentials' => [
            [
                'role' => 'admin',
                'email' => env('POS_DEMO_ADMIN_EMAIL', ''),
                'password' => env('POS_DEMO_ADMIN_PASSWORD', ''),
            ],
            [
                // The demo cashier is a fixed test account seeded by
                // DemoCashierUserSeeder, so it ships with working defaults
                // (shown on the login page whenever demo mode is on). The
                // admin above stays env-only because it's the operator's own
                // install admin. Override these via .env if you reseed.
                'role' => 'cashier',
                'email' => env('POS_DEMO_CASHIER_EMAIL', 'cashier@demo.test'),
                'password' => env('POS_DEMO_CASHIER_PASSWORD', 'cashier1234'),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-updater
    |--------------------------------------------------------------------------
    |
    | See docs/features/updater-hooks.md. The feed is a static JSON endpoint
    | we control; the public key verifies the Ed25519 signature on each
    | release zip so a compromised feed or a man-in-the-middle can't push a
    | malicious update. Per-install preferences (channel, auto-check, pinned
    | version, skipped versions) live in the database — NOT here — so they
    | survive an update.
    |
    */

    'updater' => [

        // The update feed. env-overridable for forks / staging; defaults to
        // the canonical endpoint shipped with the product.
        'feed_url' => env('POS_UPDATE_FEED_URL', 'https://updates.infinitietech.com/pos.json'),

        // Base64-encoded Ed25519 public key used to verify release signatures.
        //
        // The key is baked in as the env() DEFAULT rather than left to each
        // install's .env, and that is the whole point: this file is replaced by
        // every update, whereas a customer's .env is never touched. A key
        // documented only in .env.example would reach fresh installs and no
        // existing one, so every install already in the field would fail its
        // next update with `updates.errors.no_public_key`.
        //
        // Generate + write it with:  php artisan pos:updater-keygen --write
        // Then sign each release with: php artisan pos:sign-release <zip>
        //
        // Leaving this empty ships a product whose one-click updater cannot
        // install anything — the pre-flight "package verification" check exists
        // to catch that before a release goes out.
        'public_key' => env('POS_UPDATE_PUBLIC_KEY', 'xvCWtfbAvEK9SXQOfkbfClXiQAmBDHB12Lo08yGMTCM='),

        // Escape hatch for developing the update flow without a signing key.
        //
        // Verification used to be skipped whenever APP_ENV was not exactly
        // "production" (SEC #3 / #100), which is the wrong default for a
        // product installed by copying files onto shared hosting — plenty of
        // real installs never change APP_ENV from `local`, and an install in
        // that state would accept an unsigned or modified package and copy it
        // over the live app. Enforcement is now unconditional unless this is
        // explicitly turned on, and it is never on by default.
        //
        // NEVER set this on an install anyone else can reach.
        'allow_unsigned' => env('POS_UPDATE_ALLOW_UNSIGNED', false),

        // Default channel for a fresh install. The per-install choice is a
        // database setting that overrides this.
        'channel' => env('POS_UPDATE_CHANNEL', 'stable'),

        // Seconds to wait on the feed endpoint before giving up. A slow or
        // unreachable feed must never block the app — see the graceful
        // failure handling in the update client.
        'feed_timeout' => 15,

        // Seconds allowed for streaming a release zip down (much larger than
        // the feed JSON — tens of megabytes).
        'download_timeout' => 300,

    ],

    /*
    |--------------------------------------------------------------------------
    | Remote image fetching
    |--------------------------------------------------------------------------
    |
    | Bulk product images can arrive two ways: the customer drops a folder of
    | files, or they give us URLs (from an import's `Image` column, or pasted on
    | the bulk-images screen) and the SERVER downloads them.
    |
    | That download is the only place in the app where a URL a customer typed
    | becomes an outbound request from their server, so it is a trust boundary:
    | App\Services\Images\RemoteImageFetcher refuses anything that isn't HTTPS to
    | a public address (SafeRemoteUrl), re-checks every redirect hop, caps the
    | response size, and identifies the file by its magic bytes rather than its
    | URL or Content-Type.
    |
    | The limits below are deliberately tight — a shared host gives us one PHP
    | request to work in, and the browser drives the run one small batch at a
    | time, so a single slow URL must never eat the whole request.
    |
    */

    'images' => [

        // Largest image we will pull down, in KB. Mirrors the 4 MB cap on the
        // file-upload path so both routes to an image behave the same.
        'max_kb' => (int) env('POS_IMAGE_FETCH_MAX_KB', 4096),

        // Seconds allowed for one image, and for the connection alone. Kept
        // short on purpose: a batch of 5 must finish well inside a typical
        // 30-second max_execution_time even if every URL is dead.
        'timeout' => (int) env('POS_IMAGE_FETCH_TIMEOUT', 10),
        'connect_timeout' => (int) env('POS_IMAGE_FETCH_CONNECT_TIMEOUT', 5),

        // Redirect hops to follow. Guzzle's own redirect handling is turned OFF
        // and each hop is re-checked against SafeRemoteUrl instead — otherwise a
        // public URL could bounce the fetch to 127.0.0.1 and walk straight past
        // the check we made on the URL we were given.
        'max_redirects' => (int) env('POS_IMAGE_FETCH_MAX_REDIRECTS', 3),

        // Some CDNs and shop platforms refuse an empty or unknown User-Agent.
        'user_agent' => env('POS_IMAGE_FETCH_UA', 'Mozilla/5.0 (compatible; POS-ImageFetcher/1.0)'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Licensing
    |--------------------------------------------------------------------------
    |
    | See docs/features/installer.md §3 + §6. The license is validated once at
    | install (Step 3) against our license server, then re-checked
    | periodically using read-only domain status checks. Confirmed invalid
    | bindings block normal operation; outages preserve the last known status.
    | The license KEY lives in .env (LICENSE_KEY); the
    | validated DETAILS (buyer, type, support window) are cached in the DB.
    |
    | `public_key` verifies the offline-activation blob (Ed25519) for customers
    | behind firewalls who can't reach the license server during install.
    |
    */

    'license' => [

        // Master switch for license enforcement. When false the installer skips
        // the purchase-code step entirely and the background re-check no-ops —
        // useful for free / internal builds or offline development.
        //
        // ON: the CodeCanyon item is live (see item_id below), so the installer
        // asks for a purchase code and validates it against the validator
        // server. Set POS_LICENSE_REQUIRED=false in .env to disable on a
        // fork / internal build.
        'required' => env('POS_LICENSE_REQUIRED', true),

        // Explicit operator bypass for servers unable to reach the validator.
        // Honored in every environment, including production. Off by default.
        // Installer accepts a nonempty code; ongoing checks/enforcement are skipped.
        'dev_bypass' => env('POS_LICENSE_DEV_BYPASS', false),

        // Periodic read-only binding checks and middleware enforcement.
        'recheck' => env('POS_LICENSE_RECHECK', true),
        'recheck_minutes' => 15,

        // The activated license key + its install fingerprint, written to .env
        // by the installer. Surfaced through config so the background re-check
        // reads them even when the config is cached (env() outside config
        // returns null once `config:cache` runs).
        'key' => env('LICENSE_KEY', ''),
        'fingerprint' => env('LICENSE_FINGERPRINT', ''),

        // Validator v1: authenticated JSON POST to validate and bind this install.
        // Revalidating the same domain does not consume another seat.
        'check_url' => env('POS_LICENSE_CHECK_URL', 'https://validator-new.infinitietech.com/api/v1/validate'),
        'api_key' => '554c9a4319fd81f75586008b347f767eb9a48b6995c26b50',
        'registration_token' => env('LICENSE_REGISTRATION_TOKEN', ''),

        // The product / item identifier the validator binds the key to.
        // The legacy CI codebase called this `WEB_CODE`; in this build
        // it's `POS_LICENSE_ITEM_ID` in `.env` and reads from here at
        // runtime. Required by the validator — without it every check
        // comes back invalid.
        'item_id' => env('POS_LICENSE_ITEM_ID', '64055507'),

        // Seconds to wait on the license server before giving up. Offline
        // activation has been removed in this build; an unreachable server
        // surfaces a "couldn't reach license server" error and the
        // customer must retry once connectivity is restored.
        'timeout' => 15,

    ],

];
