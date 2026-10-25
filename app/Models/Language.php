<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A locale the install offers (docs/features/multi-language.md §2).
 * English is seeded as the default and can't be deleted. `direction`
 * drives the layout's `dir` attribute for RTL languages.
 */
class Language extends Model
{
    /**
     * Cache key for the active-languages set read by SetLocale on every
     * request (code-review #12). Any save/delete busts it below.
     */
    public const ACTIVE_CACHE_KEY = 'active_languages';

    protected $fillable = [
        'code', 'name', 'native_name', 'direction', 'is_active', 'is_default', 'sort_order',
    ];

    protected static function booted(): void
    {
        // Languages change almost never; the SetLocale hot path caches them
        // forever, so every write here has to bust that cache.
        $forget = static fn () => Cache::forget(self::ACTIVE_CACHE_KEY);
        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function isRtl(): bool
    {
        return $this->direction === 'rtl';
    }

    /** @param Builder<Language> $q */
    public function scopeActive(Builder $q): void
    {
        $q->where('is_active', true);
    }

    /** @param Builder<Language> $q */
    public function scopeOrdered(Builder $q): void
    {
        $q->orderByDesc('is_default')->orderBy('sort_order')->orderBy('name');
    }
}
