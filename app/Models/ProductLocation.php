<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a product physically sits inside one store — aisle / rack /
 * shelf / bin. Matches the schema in
 * database/migrations/2026_08_05_000001_create_product_locations.php.
 *
 * A product may have several rows per store (front rack + godown +
 * fridge + controlled cabinet); `kind` says which is which and
 * `is_primary` marks the pick face. Locations hold no quantities —
 * see the migration's note for why that boundary matters.
 */
class ProductLocation extends Model
{
    /**
     * Kinds of place a product can occupy, in display order.
     *
     *   primary     the pick face — dispensing rack / front shelf
     *   backstock   godown, reserve, bulk storage
     *   cold        refrigerator / cold chain (vaccines, insulin)
     *   controlled  locked cabinet — pairs with products.pharmacy_schedule
     *   other       escape hatch
     */
    public const KINDS = ['primary', 'backstock', 'cold', 'controlled', 'other'];

    /** The four coordinate columns, in the order they read on a label. */
    public const COORDINATES = ['aisle', 'rack', 'shelf', 'bin'];

    protected $fillable = [
        'store_id',
        'product_id',
        'variant_id',
        'kind',
        'is_primary',
        'aisle',
        'rack',
        'shelf',
        'bin',
        'note',
        'sort_key',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Walk-order sort key for a set of coordinates.
     *
     * Every numeric run is zero-padded to 4 digits so string ordering
     * matches shelf ordering: plain sorting puts "A10" before "A2",
     * which silently scrambles a picking list into uselessness.
     *
     *   ['aisle' => 'A3',  'rack' => 'R12'] → "A0003/R0012//"
     *   ['aisle' => 'A10', 'rack' => 'R2' ] → "A0010/R0002//"
     *
     * Position is preserved (empty segments stay empty) so a product
     * with only an aisle still sorts against one that has aisle + rack.
     *
     * @param  array<string, mixed>  $parts
     */
    public static function makeSortKey(array $parts): string
    {
        $segments = [];

        foreach (self::COORDINATES as $key) {
            $value = strtoupper(trim((string) ($parts[$key] ?? '')));

            $segments[] = $value === ''
                ? ''
                : preg_replace_callback(
                    '/\d+/',
                    fn (array $m) => str_pad($m[0], 4, '0', STR_PAD_LEFT),
                    $value,
                );
        }

        // Truncate rather than overflow the column — a key long enough to
        // clip is already far past any real shelf coordinate.
        return mb_substr(implode('/', $segments), 0, 80);
    }

    /**
     * Human label for this location: the enabled, non-empty coordinates
     * joined with a separator, e.g. "A3 · R-12 · 2".
     *
     * `$fields` comes from {@see Company::locationFields()} so a company
     * that switched "Shelf" and "Bin" off never sees them printed, even
     * if old rows still carry values.
     *
     * @param  array<int, array{key: string, label: string, enabled: bool}>|null  $fields
     */
    public function label(?array $fields = null, string $separator = ' · '): string
    {
        return self::formatCoordinates($this->only(self::COORDINATES), $fields, $separator);
    }

    /**
     * Same label, built from a plain array of coordinates.
     *
     * Report screens read locations through raw joins rather than hydrating
     * a model per row — a low-stock list would otherwise fire a query per
     * line — so they need the formatting without the model.
     *
     * @param  array<string, mixed>                                              $values
     * @param  array<int, array{key: string, label: string, enabled: bool}>|null $fields
     */
    public static function formatCoordinates(array $values, ?array $fields = null, string $separator = ' · '): string
    {
        $fields ??= Company::current()?->locationFields() ?? [];

        $keys = $fields === []
            ? self::COORDINATES
            : array_column(array_filter($fields, fn ($f) => $f['enabled']), 'key');

        $parts = [];
        foreach ($keys as $key) {
            $value = trim((string) ($values[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode($separator, $parts);
    }

    /** Does this row actually say where anything is? */
    public function isBlank(): bool
    {
        foreach (self::COORDINATES as $key) {
            if (trim((string) ($this->{$key} ?? '')) !== '') {
                return false;
            }
        }

        return trim((string) ($this->note ?? '')) === '';
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @param Builder<ProductLocation> $q */
    public function scopeForStore(Builder $q, ?int $storeId): void
    {
        if ($storeId !== null) {
            $q->where('store_id', $storeId);
        }
    }

    /**
     * Product-level rows only (the editor writes these). Variant-level
     * bins are v1.1; the column exists so that lands as data, not schema.
     *
     * @param Builder<ProductLocation> $q
     */
    public function scopeProductLevel(Builder $q): void
    {
        $q->whereNull('variant_id');
    }

    /**
     * Walk order: pick face first, then by padded coordinates.
     *
     * @param Builder<ProductLocation> $q
     */
    public function scopeInWalkOrder(Builder $q): void
    {
        $q->orderByDesc('is_primary')
            ->orderBy('sort_key')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
