<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plugin extends Model
{
    protected $fillable = [
        'slug', 'name', 'version', 'author', 'is_enabled',
        'installed_at', 'last_activated_at', 'last_error', 'manifest',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'installed_at' => 'datetime',
            'last_activated_at' => 'datetime',
            'manifest' => 'array',
        ];
    }

    /** @return HasMany<PluginOption, Plugin> */
    public function options(): HasMany
    {
        return $this->hasMany(PluginOption::class);
    }
}
