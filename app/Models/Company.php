<?php

namespace App\Models;

use App\Models\Concerns\MasksDemoEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The single company record (one install = one company). Holds identity,
 * address, logo, and base configuration. Created by the installer; edited
 * via Settings → Company profile.
 */
class Company extends Model
{
    use MasksDemoEmail;

    protected $table = 'company';

    /** Never expose decrypted owner scripts through accidental model JSON. */
    protected $hidden = ['custom_script_settings'];

    protected $fillable = [
        'name', 'legal_name', 'tax_registration_number',
        'email', 'phone', 'website',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code',
        'app_name',
        'logo_path', 'app_logo_path', 'app_logo_dark_path', 'app_logo_half_path', 'app_logo_half_dark_path',
        'brand_color', 'brand_text_color', 'favicon_path', 'theme_default', 'footer_text',
        'privacy_policy', 'terms_of_service',
        'timezone', 'date_format', 'time_format',
        'receipt_paper_size', 'receipt_show_logo', 'receipt_show_customer', 'receipt_show_cashier',
        'receipt_show_tax_breakdown', 'receipt_show_barcode', 'receipt_show_qr',
        'receipt_show_sku', 'receipt_show_hsn', 'receipt_show_hsn_summary',
        'receipt_header', 'receipt_footer', 'receipt_return_policy',
        'cashier_settings', 'scale_settings', 'loyalty_settings', 'location_settings',
        'custom_script_settings',
        'sale_number_format', 'hold_number_format', 'quotation_number_format', 'refund_number_format', 'sku_format', 'barcode_prefix',
        'mail_driver', 'mail_host', 'mail_port', 'mail_username', 'mail_password',
        'mail_encryption', 'mail_from_address', 'mail_from_name',
        'backup_enabled', 'backup_frequency', 'backup_retention_days', 'backup_target_disk',
        'backup_include_uploads', 'backup_email_enabled', 'backup_last_run_at',
        'scheduler_last_run_at', 'scheduler_cron_token',
        'scheduler_cron_token_created_at', 'scheduler_cron_token_expires_at',
        'enforce_single_session',
        'update_channel', 'update_auto_check', 'update_auto_install', 'update_last_checked_at',
        'update_available_version', 'update_available_release', 'update_pinned_version',
        'update_skipped_versions', 'update_last_feed_error',
        'license_status', 'license_type', 'license_buyer_name', 'license_buyer_email',
        'license_expires_at', 'license_support_until', 'license_last_checked_at', 'license_last_error',
        'industry', 'industries_enabled',
        'base_currency_code', 'fiscal_year_start_month',
        'tax_registered', 'composition_scheme_enabled', 'composition_rate_percent',
        'auto_apply_markup_on_receive',
        'pricing_method', 'batch_pricing_initialized_at',
        'block_expired_batch_sale',
        'dashboard_setup_dismissed',
        'dashboard_setup_celebrated',
    ];

    protected function casts(): array
    {
        return [
            'industries_enabled'          => 'array',
            'tax_registered'              => 'boolean',
            'composition_scheme_enabled'  => 'boolean',
            'composition_rate_percent'    => 'decimal:4',
            'fiscal_year_start_month'     => 'integer',
            'receipt_show_logo'           => 'boolean',
            'receipt_show_customer'       => 'boolean',
            'receipt_show_cashier'        => 'boolean',
            'receipt_show_tax_breakdown'  => 'boolean',
            'receipt_show_barcode'        => 'boolean',
            'receipt_show_qr'             => 'boolean',
            'receipt_show_sku'            => 'boolean',
            'receipt_show_hsn'            => 'boolean',
            'receipt_show_hsn_summary'    => 'boolean',
            'mail_password'               => \App\Casts\SafeEncrypted::class,
            'mail_port'                   => 'integer',
            'dashboard_setup_dismissed'   => 'boolean',
            'dashboard_setup_celebrated'  => 'boolean',
            'backup_enabled'              => 'boolean',
            'backup_include_uploads'      => 'boolean',
            'backup_email_enabled'        => 'boolean',
            'backup_retention_days'       => 'integer',
            'backup_last_run_at'          => 'datetime',
            'scheduler_last_run_at'       => 'datetime',
            'scheduler_cron_token_created_at' => 'datetime',
            'scheduler_cron_token_expires_at' => 'datetime',
            'enforce_single_session'  => 'boolean',
            'update_auto_check'           => 'boolean',
            'update_auto_install'         => 'boolean',
            'update_last_checked_at'      => 'datetime',
            'update_available_release'    => 'array',
            'update_skipped_versions'     => 'array',
            'cashier_settings'            => 'array',
            'scale_settings'              => 'array',
            'loyalty_settings'            => 'array',
            'location_settings'           => 'array',
            'custom_script_settings'      => \App\Casts\SafeEncrypted::class,
            'license_expires_at'          => 'datetime',
            'license_support_until'       => 'datetime',
            'license_last_checked_at'     => 'datetime',
            'auto_apply_markup_on_receive' => 'boolean',
            'batch_pricing_initialized_at' => 'datetime',
            'block_expired_batch_sale'    => 'boolean',
        ];
    }

    public function usesBatchPricing(): bool
    {
        return $this->pricing_method === 'batch_wise';
    }

    /** Read the scheduler heartbeat as the UTC instant stored in the DB. */
    public function schedulerLastRunAtUtc(): ?Carbon
    {
        $raw = $this->getRawOriginal('scheduler_last_run_at');

        return $raw === null || $raw === ''
            ? null
            : Carbon::createFromFormat($this->getDateFormat(), (string) $raw, 'UTC');
    }

    /**
     * Cashier (POS) UI preferences with defaults filled in.
     *
     * Keep the surface small — every key here is a knob exposed on the
     * Cashier Settings page. Adding one means: (a) bump this default
     * map, (b) extend the request rules + form, (c) consume it from the
     * cashier-page Alpine factory or the Blade template.
     *
     * @return array{
     *     layout: string,
     *     tile_size: string,
     *     theme_default: string,
     *     show_tax_line: bool,
     *     show_from_prefix: bool,
     *     show_quick_picks: bool,
     *     sound_on_add: bool,
     *     default_category: string
     * }
     */
    public function cashier(): array
    {
        $stored = is_array($this->cashier_settings) ? $this->cashier_settings : [];
        return array_replace([
            'layout'           => 'beam',          // 'beam' (cart right) | 'lane' (cart left)
            'tile_size'        => 'comfortable',   // 'compact' | 'comfortable' | 'spacious'
            'theme_default'    => 'auto',          // 'auto' | 'light' | 'dark' — overrides company.theme_default just for /cashier
            'show_tax_line'    => true,
            'show_from_prefix' => true,
            'show_quick_picks' => false,
            'sound_on_add'     => true,
            'default_category' => 'all',
            // Checkout stock policy: when true, sales may take stock below
            // zero (backorders / overselling) — but only for users holding
            // `sales.oversell`. Enforced server-side in CompleteSale.
            'allow_negative_stock' => false,
            // Batch-tracked products: pick the earliest-expiring live batch
            // automatically instead of stopping the cashier with the picker on
            // every scan. The picked batch stays visible on the cart line and
            // can be changed there, so nothing is hidden — this only removes a
            // modal from the common case. Off = the picker opens every time,
            // which some pharmacies prefer as a deliberate checkpoint.
            'auto_batch' => true,
        ], $stored);
    }

    /**
     * Weighing-scale barcode template with defaults filled in.
     *
     * Scale-printed barcodes embed a PLU (item code) and a measured value
     * (weight or total price) inside an EAN-13/UPC-A. The layout differs by
     * scale brand, so every field is configurable — the cashier decodes a
     * scanned barcode positionally:
     *
     *   [prefix][plu(plu_length)][skip(value_offset)][value(value_length)]…
     *
     * The trailing digits (EAN check, internal price check) are ignored —
     * the hardware scanner already validates the EAN-13 check digit.
     *
     * @return array{
     *     enabled: bool,
     *     prefix: string,
     *     plu_length: int,
     *     value_offset: int,
     *     value_length: int,
     *     value_decimals: int,
     *     embed_type: string
     * }
     */
    public function scale(): array
    {
        $stored = is_array($this->scale_settings) ? $this->scale_settings : [];
        $merged = array_replace([
            'enabled'        => false,
            'prefix'         => '2',       // EAN-13 in-store flag digit(s)
            'plu_length'     => 5,         // digits of the item/PLU code
            'value_offset'   => 0,         // digits between PLU and value (internal check)
            'value_length'   => 5,         // digits of the embedded value
            'value_decimals' => 3,         // implied decimals (weight: 3 = grams→kg)
            'embed_type'     => 'weight',  // 'weight' | 'price'
        ], $stored);

        // Coerce the numeric knobs so a stray string from old JSON can't
        // poison the cashier's positional slicing.
        $merged['enabled']        = (bool) $merged['enabled'];
        $merged['prefix']         = (string) $merged['prefix'];
        $merged['plu_length']     = (int) $merged['plu_length'];
        $merged['value_offset']   = (int) $merged['value_offset'];
        $merged['value_length']   = (int) $merged['value_length'];
        $merged['value_decimals'] = (int) $merged['value_decimals'];
        $merged['embed_type']     = $merged['embed_type'] === 'price' ? 'price' : 'weight';

        return $merged;
    }

    /**
     * Loyalty program configuration with defaults filled in
     * (docs/features/customers.md §9.1). System-wide, like every other
     * program-level knob — one install, one company, one program.
     *
     * The two rates are expressed as ratios so the UI can phrase them the
     * way an owner thinks: "earn {earn_points} points for every
     * {earn_per_amount} spent", "{redeem_points} points = {redeem_value}".
     * Money values are decimal-strings; points are whole numbers.
     *
     * Off by default — an install that never visits the settings page
     * behaves exactly as it did before loyalty existed.
     *
     * @return array{
     *     enabled: bool,
     *     earn_points: int,
     *     earn_per_amount: string,
     *     earn_on: string,
     *     redeem_points: int,
     *     redeem_value: string,
     *     min_redeem_points: int,
     *     max_redeem_percent: string,
     *     expiry_months: int
     * }
     */
    public function loyalty(): array
    {
        $stored = is_array($this->loyalty_settings) ? $this->loyalty_settings : [];
        $merged = array_replace([
            'enabled'            => false,
            'earn_points'        => 1,             // …points
            'earn_per_amount'    => '10',          // …per this much spent
            'earn_on'            => 'grand_total', // 'grand_total' | 'subtotal' (net of discount, pre-tax)
            'redeem_points'      => 100,           // this many points…
            'redeem_value'       => '10',          // …are worth this much money
            'min_redeem_points'  => 100,           // can't redeem below this balance
            'max_redeem_percent' => '100',         // cap on how much of a sale points may cover
            'expiry_months'      => 0,             // 0 = points never expire
        ], $stored);

        // Coerce — a stray string from older JSON must never poison the
        // bcmath paths in the earn/redeem calculator.
        $merged['enabled']            = (bool) $merged['enabled'];
        $merged['earn_points']        = max(0, (int) $merged['earn_points']);
        $merged['earn_per_amount']    = (string) $merged['earn_per_amount'];
        $merged['earn_on']            = $merged['earn_on'] === 'subtotal' ? 'subtotal' : 'grand_total';
        $merged['redeem_points']      = max(1, (int) $merged['redeem_points']);
        $merged['redeem_value']       = (string) $merged['redeem_value'];
        $merged['min_redeem_points']  = max(0, (int) $merged['min_redeem_points']);
        $merged['max_redeem_percent'] = (string) $merged['max_redeem_percent'];
        $merged['expiry_months']      = max(0, (int) $merged['expiry_months']);

        return $merged;
    }

    /**
     * Shelf-location field configuration — which of the four coordinate
     * fields this company uses, and what it calls them.
     *
     * A pharmacy says "Rack", a warehouse says "Bin", a supermarket says
     * "Aisle". Rather than imposing our vocabulary, each field can be
     * renamed and switched off. Defaults enable Aisle + Rack (enough for
     * a shop) and leave Shelf + Bin off until someone asks for them.
     *
     * A `label` is only stored when the owner overrode it — otherwise the
     * translated default is returned, so an install that switches language
     * gets translated field names rather than frozen English ones.
     *
     * @return array<int, array{key: string, label: string, enabled: bool}>
     */
    public function locationFields(): array
    {
        $stored = is_array($this->location_settings) ? $this->location_settings : [];
        $fields = is_array($stored['fields'] ?? null) ? $stored['fields'] : [];

        $defaults = [
            'aisle' => true,
            'rack'  => true,
            'shelf' => false,
            'bin'   => false,
        ];

        $out = [];
        foreach ($defaults as $key => $enabledByDefault) {
            $row      = is_array($fields[$key] ?? null) ? $fields[$key] : [];
            $override = trim((string) ($row['label'] ?? ''));

            $out[] = [
                'key'     => $key,
                'label'   => $override !== '' ? $override : __('products.locations.fields.'.$key),
                'enabled' => array_key_exists('enabled', $row)
                    ? (bool) $row['enabled']
                    : $enabledByDefault,
            ];
        }

        // Never hand back an all-disabled set — the editor would render a
        // Locations card with nothing in it. Falling back to the defaults
        // is friendlier than an empty form the owner can't recover from.
        if (! collect($out)->contains(fn ($f) => $f['enabled'])) {
            foreach ($out as $i => $f) {
                $out[$i]['enabled'] = $defaults[$f['key']];
            }
        }

        return $out;
    }

    /**
     * Just the enabled location field keys, in display order.
     *
     * @return array<int, string>
     */
    public function enabledLocationFields(): array
    {
        return array_values(array_column(
            array_filter($this->locationFields(), fn ($f) => $f['enabled']),
            'key',
        ));
    }

    /**
     * Trusted owner-supplied browser snippets with conservative defaults.
     * The encrypted column contains JSON so it remains one atomic setting.
     * Invalid/corrupt data disables the feature instead of reaching a layout.
     *
     * @return array{enabled: bool, include_authenticated: bool, header: string, footer: string}
     */
    public function customScripts(): array
    {
        $defaults = [
            'enabled' => false,
            'include_authenticated' => false,
            'header' => '',
            'footer' => '',
        ];

        $stored = json_decode((string) ($this->custom_script_settings ?? ''), true);
        if (! is_array($stored)) {
            return $defaults;
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'include_authenticated' => (bool) ($stored['include_authenticated'] ?? false),
            'header' => is_string($stored['header'] ?? null) ? trim($stored['header']) : '',
            'footer' => is_string($stored['footer'] ?? null) ? trim($stored['footer']) : '',
        ];
    }

    /**
     * Resolved number-format template for the given number type.
     * Falls back to the system default when the admin hasn't configured
     * one — both produce the same output for the default `MAIN` store
     * so existing tests / receipts stay stable until an admin opts in.
     *
     * @param 'sale'|'hold'|'quotation'|'refund'|'sku' $type
     */
    public function numberFormat(string $type): string
    {
        $defaults = [
            'sale'   => 'SALE-{store}-{Ym}-{seq:04}',
            'hold'   => 'HOLD-{store}-{Ym}-{seq:04}',
            'quotation' => 'QUO-{store}-{Ym}-{seq:04}',
            'refund' => 'REFUND-{store}-{Ym}-{seq:04}',
            // Products are company-level, so no {store} — a SKU that
            // implied a store would be a lie the moment the item is
            // stocked in a second branch.
            'sku'    => 'SKU-{seq:05}',
        ];

        $stored = match ($type) {
            'sale'   => $this->sale_number_format,
            'hold'   => $this->hold_number_format,
            'quotation' => $this->quotation_number_format,
            'refund' => $this->refund_number_format,
            'sku'    => $this->sku_format,
            default  => null,
        };

        return $stored !== null && trim((string) $stored) !== ''
            ? (string) $stored
            : ($defaults[$type] ?? $defaults['sale']);
    }

    /**
     * The in-store prefix auto-generated product barcodes start with
     * (docs/features/products-inventory.md §10). Digits only, 1–7 long so
     * the counter + check digit still fit inside an EAN-13's 13 digits.
     *
     * Defaults to `20` — the GS1 20–29 range is reserved for in-store use,
     * so a generated code can never collide with a real manufacturer GTIN.
     * A stray non-digit value from bad input falls back to the default
     * rather than producing an un-encodable barcode.
     */
    public function barcodePrefix(): string
    {
        $stored = trim((string) ($this->barcode_prefix ?? ''));

        return preg_match('/^[0-9]{1,7}$/', $stored) === 1 ? $stored : '20';
    }

    /** The single company row (the installer guarantees one exists). */
    /**
     * Container key holding the memoized current company for the request
     * (code-review #13 / #14). The container is rebuilt per request (and per
     * test), so this is request-scoped without leaking across requests.
     */
    private const CURRENT_BINDING = 'company.current';

    /**
     * The single company row, resolved ONCE per request. `current()` is
     * called ~91x across the app and several times within a single hot
     * request (the cashier render alone hits it 3+ times), so re-running
     * `->first()` each time multiplied identical SELECTs on the busiest
     * screens — including the 30s heartbeat (code-review #13 / #14). Mirrors
     * the request-scoping of current_store()/current_terminal(). Any
     * save/delete busts the memo (see {@see booted}).
     */
    public static function current(): ?self
    {
        $app = app();

        // Boxed in an array because `bound()` is isset()-based: a memoized
        // NULL (no company row yet — fresh install, or a test that skips the
        // seeder) would read as unbound forever, and `make()` would then try
        // to resolve the memo key as a class name and blow up.
        if (! $app->bound(self::CURRENT_BINDING)) {
            $app->instance(self::CURRENT_BINDING, [static::query()->first()]);
        }

        return $app->make(self::CURRENT_BINDING)[0];
    }

    /** Drop the per-request memo so the next {@see current} re-reads. */
    public static function forgetCurrent(): void
    {
        app()->forgetInstance(self::CURRENT_BINDING);
    }

    protected static function booted(): void
    {
        // A settings save must be visible to the rest of THIS request, and
        // never leave a stale row memoized for a reused worker.
        $forget = static fn () => static::forgetCurrent();
        static::saved($forget);
        static::deleted($forget);
    }

    public function getFaviconUrlAttribute(): ?string
    {
        return $this->favicon_path ? Storage::url($this->favicon_path) : null;
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::url($this->logo_path) : null;
    }

    public function getAppLogoUrlAttribute(): ?string
    {
        return $this->app_logo_path ? Storage::url($this->app_logo_path) : null;
    }

    public function getAppLogoDarkUrlAttribute(): ?string
    {
        return $this->app_logo_dark_path ? Storage::url($this->app_logo_dark_path) : null;
    }

    public function getAppLogoHalfUrlAttribute(): ?string
    {
        return $this->app_logo_half_path ? Storage::url($this->app_logo_half_path) : null;
    }

    public function getAppLogoHalfDarkUrlAttribute(): ?string
    {
        return $this->app_logo_half_dark_path ? Storage::url($this->app_logo_half_dark_path) : null;
    }

    /** Custom app name; falls back to config('app.name') if not set. */
    public function getDisplayAppNameAttribute(): string
    {
        return $this->app_name ?: config('app.name');
    }
}
