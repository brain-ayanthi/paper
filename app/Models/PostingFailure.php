<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A journal posting that failed and has not yet been made good — defect #104.
 *
 * @see \App\Support\Accounting\RecordsPostingFailures  writes these
 * @see \App\Console\Commands\RepostFailedEntries       clears them
 */
class PostingFailure extends Model
{
    protected $fillable = [
        'hook', 'poster', 'reference_type', 'reference_id', 'store_id',
        'exception', 'message', 'attempts', 'last_attempted_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts'          => 'integer',
            'last_attempted_at' => 'datetime',
            'resolved_at'       => 'datetime',
        ];
    }

    /** @param Builder<PostingFailure> $q */
    public function scopeUnresolved(Builder $q): void
    {
        $q->whereNull('resolved_at');
    }

    /** The record we were trying to post, when it still exists. */
    public function reference(): ?Model
    {
        if (! $this->reference_type || ! class_exists($this->reference_type)) {
            return null;
        }

        /** @var class-string<Model> $class */
        $class = $this->reference_type;

        return $class::query()->find($this->reference_id);
    }
}
