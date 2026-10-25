<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;
use App\Policies\Concerns\ScopedToStore;

class QuotationPolicy
{
    use ScopedToStore;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('quotations.view_all')
            || $user->hasPermission('quotations.view_own');
    }

    public function view(User $user, Quotation $quotation): bool
    {
        if (! $this->inStoreScope($user, $quotation)) {
            return false;
        }

        return $user->hasPermission('quotations.view_all', (int) $quotation->store_id)
            || ($user->hasPermission('quotations.view_own', (int) $quotation->store_id)
                && (int) $quotation->created_by === (int) $user->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('quotations.create');
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $this->view($user, $quotation)
            && $user->hasPermission('quotations.update', (int) $quotation->store_id)
            && $quotation->isEditable();
    }

    public function send(User $user, Quotation $quotation): bool
    {
        return $this->view($user, $quotation)
            && $user->hasPermission('quotations.send', (int) $quotation->store_id)
            && $quotation->canSend();
    }

    public function revise(User $user, Quotation $quotation): bool
    {
        return $this->view($user, $quotation)
            && $user->hasPermission('quotations.send', (int) $quotation->store_id)
            && $quotation->canRevise();
    }

    public function convert(User $user, Quotation $quotation): bool
    {
        return $this->view($user, $quotation)
            && $user->hasPermission('quotations.convert', (int) $quotation->store_id)
            && $user->hasPermission('sales.create', (int) $quotation->store_id)
            && $quotation->canConvert();
    }

    public function cancel(User $user, Quotation $quotation): bool
    {
        return $this->view($user, $quotation)
            && $user->hasPermission('quotations.cancel', (int) $quotation->store_id)
            && $quotation->canCancel();
    }
}
