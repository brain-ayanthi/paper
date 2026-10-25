<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoyaltySettingsRequest;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Loyalty program settings (docs/features/customers.md §9.1) — earn rate,
 * redeem rate, thresholds, and expiry. Stored as a single JSON column on
 * `company` and read through Company::loyalty(). System-wide, not
 * per-store. Gated by `settings.view` / `settings.update`.
 */
class LoyaltySettingsController extends Controller
{
    use RespondsJsonOrRedirect;

    public function edit(): View
    {
        $this->authorize('settings.view');

        return view('admin.settings.loyalty', [
            'company'   => Company::current() ?? new Company(),
            'earnBases' => LoyaltySettingsRequest::EARN_BASES,
        ]);
    }

    public function update(LoyaltySettingsRequest $request): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.update');

        $company = Company::current();
        abort_unless($company !== null, 404);

        $company->forceFill(['loyalty_settings' => $request->loyaltyData()])->save();

        return $this->jsonOrRedirect(
            $request,
            __('settings.loyalty.flash.updated'),
            route('admin.settings.loyalty.edit'),
        );
    }
}
