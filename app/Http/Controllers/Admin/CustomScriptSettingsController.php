<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomScriptSettingsRequest;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings → Custom scripts.
 *
 * Arbitrary browser code is an owner-level trust decision, not an ordinary
 * settings permission. Both endpoints therefore require the super-admin flag;
 * saving additionally requires the current password in the Form Request.
 */
class CustomScriptSettingsController extends Controller
{
    use RespondsJsonOrRedirect;

    public function edit(Request $request): View
    {
        $this->authorize('settings.view');
        abort_unless($request->user()?->is_super_admin, 403);

        $company = Company::current() ?? new Company;

        return view('admin.settings.custom-scripts', [
            'scripts' => $company->customScripts(),
        ]);
    }

    public function update(CustomScriptSettingsRequest $request): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.update');
        abort_unless($request->user()?->is_super_admin, 403);

        if (pos_is_demo()) {
            return $this->jsonOrError(
                $request,
                __('settings.demo.locked'),
                route('admin.settings.custom-scripts.edit'),
            );
        }

        $company = Company::current();
        abort_unless($company !== null, 404);

        $company->update([
            'custom_script_settings' => json_encode(
                $request->scriptData(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        ]);

        return $this->jsonOrRedirect(
            $request,
            __('settings.custom_scripts.flash.updated'),
            route('admin.settings.custom-scripts.edit'),
        );
    }
}
