<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catalog product. Matches the schema in
 * database/migrations/2026_05_21_000007_create_catalog.php.
 *
 * Variants, batches, kits, per-store stock, and multi-image gallery
 * are deliberately NOT modelled on this class — each gets its own
 * model when the corresponding feature lands. The flagship POS use
 * cases (ringing up a "simple" product at a single price) work off
 * just these columns.
 */
class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sku',
        'barcode',
        'name',
        'slug',
        'category_id',
        'brand_id',
        'unit_id',
        'tax_group_id',
        'is_tax_inclusive',
        'description',
        'short_description',
        'type',
        'cost_price',
        'selling_price',
        'mrp',
        'markup_percent',
        'sold_by_weight',
        'scale_plu',
        'track_stock',
        'track_batches',
        'track_expiry',
        'expiry_date',
        'reorder_level',
        'reorder_quantity',
        'hsn_code',
        'pharmacy_schedule',
        'generic_name',
        'manufacturer',
        'is_active',
        'is_featured',
        'image_path',
        'image_ref',
        'image_src_url',
        'image_fetch_status',
        'image_fetch_error',
        'meta',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'cost_price'       => 'decimal:4',
            'selling_price'    => 'decimal:4',
            'mrp'              => 'decimal:4',
            'markup_percent'   => 'decimal:4',
            'reorder_level'    => 'decimal:4',
            'reorder_quantity' => 'decimal:4',
            'expiry_date'      => 'date',
            'is_tax_inclusive' => 'boolean',
            'sold_by_weight'   => 'boolean',
            'scale_plu'        => 'integer',
            'track_stock'      => 'boolean',
            'track_batches'    => 'boolean',
            'track_expiry'     => 'boolean',
            'is_active'        => 'boolean',
            'is_featured'      => 'boolean',
            'meta'             => 'array',
        ];
    }

    /**
     * Variant attribute definitions for `type = 'variant'` products,
     * stored in `meta->variant_attributes`. Shape:
     *
     *   [ ['name' => 'Color', 'values' => ['Red', 'Blue']], ... ]
     *
     * These drive the editor's matrix generator — the cartesian product
     * of every attribute's values becomes the variant rows. Kept on the
     * product (rather than a shared attributes table) because v1 scopes
     * attributes per-product; promoting to a global library later is a
     * data migration, not a schema break.
     *
     * @return array<int, array{name: string, values: array<int, string>}>
     */
    public function variantAttributes(): array
    {
        $meta = $this->meta;
        return is_array($meta) && isset($meta['variant_attributes']) && is_array($meta['variant_attributes'])
            ? $meta['variant_attributes']
            : [];
    }

    /**
     * Suggested selling price derived from a given cost + this product's
     * `markup_percent`. Returns null when no markup is set (owner is
     * managing the selling price by hand). Decimal string at 4dp,
     * matching every other money path in the system.
     *
     * Formula: `cost × (1 + markup/100)`.
     */
    public function suggestedSellingFromCost(string|float|int $cost): ?string
    {
        $markup = $this->markup_percent !== null ? (string) $this->markup_percent : null;
        if ($markup === null || bccomp($markup, '0', 4) <= 0) {
            return null;
        }
        $costStr = (string) $cost;
        if ($costStr === '' || bccomp($costStr, '0', 4) <= 0) {
            return null;
        }
        $multiplier = bcadd('1', bcdiv($markup, '100', 8), 8);
        $raw        = bcmul($costStr, $multiplier, 8);
        // Round half-up to 4dp.
        $nudged = bcadd($raw, '0.00005', 8);
        return bcadd($nudged, '0', 4);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<TaxGroup, $this> */
    public function taxGroup(): BelongsTo
    {
        return $this->belongsTo(TaxGroup::class);
    }

    /**
     * Per-store price overrides. Any subset of (cost, selling, mrp)
     * may be set; nulls fall through to this product's defaults at
     * resolve time. See {@see App\Actions\Products\ResolveProductPrice}.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ProductStorePrice, $this>
     */
    public function prices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductStorePrice::class);
    }

    /**
     * Where this product physically sits, per store. A store may hold
     * several rows (front rack + godown + fridge + locked cabinet);
     * exactly one of them per store is flagged `is_primary` — the pick
     * face that labels and the cashier badge use.
     *
     * These carry no quantities. Stock lives on the batch / movement
     * ledger; a location only says where to walk.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ProductLocation, $this>
     */
    public function locations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductLocation::class);
    }

    /**
     * The pick face at a given store, or null when none is recorded.
     *
     * Reads from the loaded `locations` relation when it's there, so a
     * list screen that eager-loaded locations doesn't fire a query per
     * row. Falls back to `is_primary` being unset by taking the first
     * row for the store — a product with locations always has somewhere
     * to send staff, even if the flag was never set.
     */
    public function primaryLocation(?int $storeId = null): ?ProductLocation
    {
        $storeId ??= current_store_id();
        if ($storeId === null) {
            return null;
        }

        $rows = $this->relationLoaded('locations')
            ? $this->locations
            : $this->locations()->forStore($storeId)->productLevel()->inWalkOrder()->get();

        $forStore = $rows->where('store_id', $storeId)->whereNull('variant_id');

        return $forStore->firstWhere('is_primary', true) ?? $forStore->first();
    }

    /**
     * Child SKUs for `type = 'variant'` products. The parent product
     * is non-sellable directly in this mode — every receipt line
     * references a specific variant via `sale_items.variant_id`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ProductVariant, $this>
     */
    public function variants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** Is this a variant-parent product (vs. a simple / kit SKU)? */
    public function isVariantType(): bool
    {
        return $this->type === 'variant';
    }

    /** Is this a kit / bundle product? */
    public function isKitType(): bool
    {
        return $this->type === 'kit';
    }

    /**
     * Selling-price bounds for list screens, as `[low, high]`.
     *
     * A variant parent is never sold directly — its price lives on each child
     * SKU, so `products.selling_price` is 0 and printing it reads as "free".
     * When the query loaded the `variants_min_price` / `variants_max_price`
     * aggregates (see ProductController::listQuery) we report that range
     * instead; `low === high` means every variant shares one price.
     *
     * Variants that inherit the parent's price store NULL, and SQL's MIN/MAX
     * skip NULLs — so a parent whose variants ALL inherit falls back to its
     * own price, which is exactly right.
     *
     * @return array{0: int|float|string|null, 1: int|float|string|null}
     */
    public function priceBounds(): array
    {
        return $this->bounds($this->selling_price, 'variants_min_price', 'variants_max_price');
    }

    /** Cost-price bounds for list screens. See {@see priceBounds()}. */
    public function costBounds(): array
    {
        return $this->bounds($this->cost_price, 'variants_min_cost', 'variants_max_cost');
    }

    /**
     * @return array{0: int|float|string|null, 1: int|float|string|null}
     */
    private function bounds(mixed $own, string $minKey, string $maxKey): array
    {
        if (! $this->isVariantType()) {
            return [$own, $own];
        }

        $min = $this->getAttribute($minKey);
        $max = $this->getAttribute($maxKey);

        return ($min === null || $max === null) ? [$own, $own] : [$min, $max];
    }

    /**
     * Component lines for `type = 'kit'` products. Each row points at
     * another product (and optionally a specific variant) plus the
     * quantity that ships in the bundle.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ProductKitItem, $this>
     */
    public function kitItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductKitItem::class, 'parent_product_id')->orderBy('sort_order');
    }

    /**
     * Public URL of the product image, or null. Relative URL — see
     * {@see Brand::logoUrl()} for the reasoning (works regardless of
     * APP_URL or host:port).
     *
     * @return Attribute<?string, never>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path
            ? '/storage/'.ltrim($this->image_path, '/')
            : null);
    }

    /** @param Builder<Product> $q */
    public function scopeActive(Builder $q): void
    {
        $q->where('is_active', true);
    }

    /**
     * Products whose declared image URL still needs fetching — what the
     * "Fetch from URLs" screen counts and works through.
     *
     * A NULL status counts as pending: the column is written by the importer,
     * but a URL set by hand (or by an install that pre-dates the column) is
     * just as much a job waiting to be done. `failed` is deliberately excluded
     * so a dead URL isn't retried on every run — re-queueing it is an explicit
     * action, not the default.
     *
     * @param Builder<Product> $q
     */
    public function scopePendingImageFetch(Builder $q): void
    {
        $q->whereNotNull('image_src_url')
            ->where(fn (Builder $inner) => $inner
                ->whereNull('image_fetch_status')
                ->orWhere('image_fetch_status', 'pending'));
    }

    /**
     * Default admin-list / export ordering: newest first. Matches how
     * Brand and Unit lists are sorted (the established convention for
     * flat catalog data).
     *
     * @param Builder<Product> $q
     */
    public function scopeOrdered(Builder $q): void
    {
        $q->orderByDesc('id');
    }
}
