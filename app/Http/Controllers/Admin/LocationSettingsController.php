<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LocationSettingsRequest;
use App\Models\Company;
use App\Models\ProductLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Shelf-location field settings — which of aisle / rack / shelf / bin this
 * company uses, and what it calls them.
 *
 * A pharmacy thinks in racks, a warehouse in bins, a supermarket in aisles.
 * Rather than imposing one vocabulary, the four fields are renameable and
 * individually switchable; the product editor renders only what's enabled.
 *
 * Stored as a single JSON column on `company`, read via
 * Company::locationFields(). Gated by `settings.view` / `settings.update`.
 */
class LocationSettingsController extends Controller
{
    use RespondsJsonOrRedirect;

    public function edit(): View
    {
        $this->authorize('settings.view');

        return view('admin.settings.locations', [
            'company'     => Company::current() ?? new Company(),
            'coordinates' => ProductLocation::COORDINATES,
            'kinds'       => ProductLocation::KINDS,
        ]);
    }

    public function update(LocationSettingsRequest $request): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.update');

        $company = Company::current();
        abort_unless($company !== null, 404);

        // Disabling a field never deletes data: existing rows keep their
        // aisle / bin values, they just stop being shown. Switching the
        // field back on brings the old values straight back.
        $company->forceFill(['location_settings' => $request->locationData()])->save();

        return $this->jsonOrRedirect(
            $request,
            __('settings.locations.flash.updated'),
            route('admin.settings.locations.edit'),
        );
    }
}
