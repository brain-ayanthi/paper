<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;
use App\Policies\Concerns\ScopedToStore;

/**
 * Stock transfers straddle two stores, so the scope check names the side that
 * matters rather than demanding access to both — a manager who can only reach
 * the sending branch must still be able to send, and the receiving branch must
 * still be able to receive.
 *
 *  - view              — either end. The receiving branch has a legitimate
 *                        interest in a transfer it did not raise.
 *  - create/update/delete/cancel — the SOURCE store. The draft belongs to the
 *                        branch giving up the stock.
 *  - dispatch          — the SOURCE store. This is the movement that decrements
 *                        stock, and is exactly SEC #15 / defect #32: QA set
 *                        from = another store and drained it.
 *  - receive           — the DESTINATION store, which is where stock lands.
 */
class StockTransferPolicy
{
    use ScopedToStore;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('products.view');
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $this->inAnyStoreScope($user, $transfer, 'from_store_id', 'to_store_id')
            && $user->hasPermission('products.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('products.transfer_stock');
    }

    public function update(User $user, StockTransfer $transfer): bool
    {
        return $this->inStoreScope($user, $transfer, 'from_store_id')
            && $user->hasPermission('products.transfer_stock') && $transfer->isDraft();
    }

    public function dispatch(User $user, StockTransfer $transfer): bool
    {
        return $this->inStoreScope($user, $transfer, 'from_store_id')
            && $user->hasPermission('products.transfer_stock') && $transfer->isDraft();
    }

    public function receive(User $user, StockTransfer $transfer): bool
    {
        return $this->inStoreScope($user, $transfer, 'to_store_id')
            && $user->hasPermission('products.transfer_stock') && $transfer->isInTransit();
    }

    public function cancel(User $user, StockTransfer $transfer): bool
    {
        return $this->inStoreScope($user, $transfer, 'from_store_id')
            && $user->hasPermission('products.transfer_stock')
            && ($transfer->isDraft() || $transfer->isInTransit());
    }

    public function delete(User $user, StockTransfer $transfer): bool
    {
        return $this->inStoreScope($user, $transfer, 'from_store_id')
            && $user->hasPermission('products.transfer_stock') && $transfer->isDraft();
    }
}
