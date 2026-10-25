<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Customers\AdjustLoyaltyPoints;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustLoyaltyRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Manual loyalty-point adjustments from the customer's Loyalty tab
 * (docs/features/customers.md §9.5). Read-only viewing of the ledger
 * rides along with the customer Show page; this controller only owns the
 * write.
 *
 * Gated by `customers.loyalty_manage` — a separate permission from
 * `customers.update`, because handing someone the customer form should
 * not also hand them the ability to mint points.
 */
class CustomerLoyaltyController extends Controller
{
    use RespondsJsonOrRedirect;

    public function store(
        AdjustLoyaltyRequest $request,
        Customer $customer,
        AdjustLoyaltyPoints $adjust,
    ): JsonResponse|RedirectResponse {
        $this->authorize('customers.loyalty_manage');

        $txn = $adjust(
            customer:  $customer,
            points:    (int) $request->input('points'),
            direction: (string) $request->input('direction'),
            reason:    (string) $request->input('reason'),
            storeId:   null,
            user:      $request->user(),
        );

        $message = $txn->points >= 0
            ? __('customers.loyalty.flash.added', [
                'points' => number_format((int) $txn->points),
                'name'   => $customer->name,
            ])
            : __('customers.loyalty.flash.removed', [
                'points' => number_format(abs((int) $txn->points)),
                'name'   => $customer->name,
            ]);

        return $this->jsonOrRedirect(
            $request,
            $message,
            route('admin.customers.show', $customer).'#loyalty',
        );
    }
}
