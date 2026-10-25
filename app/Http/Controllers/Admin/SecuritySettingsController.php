<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Auth\SessionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin → Settings → Security.
 *
 * Home of the concurrent-login policy from
 * `docs/features/auth-users.md` §"Settings → Security" — specified since v1.0
 * and never built, so the setting the docs promise simply did not exist
 * (SEC #40 / defect #12).
 *
 * Deliberately small. The rest of that section (MFA enforcement, password
 * expiry) waits on the MFA challenge actually shipping; a settings page full
 * of switches that control nothing is what this row is about in the first
 * place.
 */
class SecuritySettingsController extends Controller
{
    use RespondsJsonOrRedirect;

    public function edit(SessionRegistry $sessions): View
    {
        $this->authorize('settings.view');

        return view('admin.settings.security', [
            'company' => Company::current(),
            // The policy is enforced by deleting rows from the `sessions`
            // table, so it can only work on the database driver. Say so
            // instead of offering a switch that quietly does nothing.
            'sessionsSupported' => $sessions->supported(),
        ]);
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.update');

        if (pos_is_demo()) {
            return $this->demoBlocked($request, route('admin.settings.security.edit'));
        }

        $company = Company::current();
        abort_unless($company !== null, 404);

        $company->update([
            'enforce_single_session' => $request->boolean('enforce_single_session'),
        ]);

        return $this->jsonOrRedirect(
            $request,
            __('settings.security.flash.updated'),
            route('admin.settings.security.edit'),
        );
    }
}
