<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Customer group — pricing tier + loyalty multiplier + reporting bucket.
 * Seeded with: Walk-in, Regular, Wholesale, Premium. The customer can
 * add their own. The `default_price_list_id` column is reserved for the
 * v1.1 tiered-pricing feature; null in v1.0.
 */
class CustomerGroup extends Model
{
    protected $fillable = [
        'name', 'default_discount_percent', 'loyalty_multiplier', 'default_price_list_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_discount_percent' => 'decimal:4',
            'loyalty_multiplier'       => 'decimal:4',
            'is_active'                => 'boolean',
        ];
    }

    /**
     * The tier's loyalty earn multiplier as a bcmath-safe decimal-string.
     *
     * NULL means "never configured" → 1× (plain earn). An explicit 0 is
     * meaningful and preserved: it's how the seeded Wholesale tier earns
     * no points at all (docs/features/customers.md §12.2).
     */
    public function earnMultiplier(): string
    {
        $m = $this->loyalty_multiplier;
        return $m === null || (string) $m === '' ? '1' : (string) $m;
    }

    /** @param Builder<CustomerGroup> $q */
    public function scopeActive(Builder $q): void
    {
        $q->where('is_active', true);
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
