<?php

namespace App\Actions\Quotations;

use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReviseQuotation
{
    public function __invoke(Quotation $quotation, User $actor): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor) {
            $source = Quotation::query()->lockForUpdate()->with('items')->findOrFail($quotation->id);
            if (! in_array($source->status, [Quotation::STATUS_SENT, Quotation::STATUS_ACCEPTED, Quotation::STATUS_DECLINED], true)) {
                throw new RuntimeException(__('quotations.errors.cannot_revise'));
            }

            $latestRevision = Quotation::withTrashed()
                ->where('store_id', $source->store_id)
                ->where('number', $source->number)
                ->orderByDesc('revision')
                ->lockForUpdate()
                ->value('revision');

            $copy = $source->replicate([
                'converted_sale_id', 'status', 'sent_at', 'first_viewed_at', 'last_viewed_at',
                'view_count', 'accepted_at', 'declined_at', 'cancelled_at', 'converted_at',
                'converted_by', 'created_by', 'updated_by',
            ]);
            $copy->forceFill([
                'revision_of_id' => $source->id,
                'revision' => ((int) $latestRevision) + 1,
                'status' => Quotation::STATUS_DRAFT,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'view_count' => 0,
            ])->save();

            foreach ($source->items as $item) {
                $copy->items()->create(Arr::except($item->toArray(), ['id', 'quotation_id']));
            }

            $source->forceFill([
                'status' => Quotation::STATUS_SUPERSEDED,
                'updated_by' => $actor->id,
            ])->save();
            $source->activities()->create([
                'actor_id' => $actor->id,
                'type' => 'superseded',
                'meta' => ['revision_id' => $copy->id, 'revision' => $copy->revision],
            ]);
            $copy->activities()->create([
                'actor_id' => $actor->id,
                'type' => 'revised',
                'meta' => ['source_id' => $source->id, 'source_revision' => $source->revision],
            ]);

            return $copy->load('items');
        });
    }
}
