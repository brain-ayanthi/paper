<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PluginOption extends Model
{
    public $timestamps = false;

    protected $fillable = ['plugin_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** @return BelongsTo<Plugin, PluginOption> */
    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Plugin::class);
    }
}
