<?php

namespace App\Actions\Quotations;

use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChangeQuotationStatus
{
    public function send(Quotation $quotation, User $actor): Quotation
    {
        return $this->change($quotation, $actor, Quotation::STATUS_SENT, 'sent', [
            'sent_at' => now(),
        ], [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT]);
    }

    public function accept(Quotation $quotation, ?User $actor = null): Quotation
    {
        return $this->change($quotation, $actor, Quotation::STATUS_ACCEPTED, 'accepted', [
            'accepted_at' => now(),
            'declined_at' => null,
        ], [Quotation::STATUS_SENT]);
    }

    public function decline(Quotation $quotation, ?User $actor = null): Quotation
    {
        return $this->change($quotation, $actor, Quotation::STATUS_DECLINED, 'declined', [
            'declined_at' => now(),
            'accepted_at' => null,
        ], [Quotation::STATUS_SENT, Quotation::STATUS_ACCEPTED]);
    }

    public function cancel(Quotation $quotation, User $actor): Quotation
    {
        return $this->change($quotation, $actor, Quotation::STATUS_CANCELLED, 'cancelled', [
            'cancelled_at' => now(),
        ], [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT, Quotation::STATUS_ACCEPTED]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  list<string>  $allowedFrom
     */
    private function change(
        Quotation $quotation,
        ?User $actor,
        string $status,
        string $activity,
        array $extra,
        array $allowedFrom,
    ): Quotation {
        return DB::transaction(function () use ($quotation, $actor, $status, $activity, $extra, $allowedFrom) {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            if ($locked->isExpired() || ! in_array($locked->status, $allowedFrom, true)) {
                throw new RuntimeException(__('quotations.errors.invalid_status_change'));
            }

            $locked->forceFill(array_merge($extra, [
                'status' => $status,
                'updated_by' => $actor?->id ?? $locked->updated_by,
            ]))->save();
            $locked->activities()->create([
                'actor_id' => $actor?->id,
                'type' => $activity,
            ]);

            return $locked;
        });
    }
}
