<?php

namespace App\Http\Controllers\Installer;

use App\Actions\Installer\ValidateLicense;
use App\Actions\Installer\WriteEnvFile;
use App\Http\Controllers\Controller;
use App\Support\InstallState;
use App\Support\InstallerSaasEntry;
use Illuminate\Http\Request;

class LicenseController extends Controller
{
    public function show(Request $request, InstallerSaasEntry $saas)
    {
        abort_if($saas->selected($request) && ! $saas->available(), 503, __('installer.saas.unavailable'));
        if ($saas->selected($request) && ! $saas->freshAdminReady()) {
            return redirect()->route('install.saas.admin');
        }
        $freshSaasCoreBypass = $saas->selected($request) && $saas->corePurchaseLaunchBypass();
        // License enforcement turned off (config/pos.php → license.required):
        // mark the step satisfied and jump straight to the database step, so
        // the customer never sees a license screen.
        if (! config('pos.license.required') && ! $saas->selected($request)) {
            InstallState::set('license_validated', true);

            return redirect()->route('install.database');
        }

        return view('installer.license', [
            'currentStep' => 3,
            'alreadyValidated' => InstallState::get('license_validated', false),
            'freshSaasCoreBypass' => $freshSaasCoreBypass,
        ]);
    }

    public function validateLicense(Request $request, ValidateLicense $validate, WriteEnvFile $writeEnv, InstallerSaasEntry $saas)
    {
        abort_if($saas->selected($request) && ! $saas->available(), 503, __('installer.saas.unavailable'));
        abort_if($saas->selected($request) && ! $saas->freshAdminReady(), 404);
        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:64'],
        ]);

        $result = ($validate)($data['license_key'], url('/'));

        if (! $result['ok']) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $result['error'], 'errors' => ['license_key' => [$result['error']]]], 422);
            }

            return back()->withErrors(['license_key' => $result['error']])->withInput();
        }

        // The key + fingerprint go to .env so the background 30-day re-check
        // (and a future major-version re-validation) can re-POST without the
        // customer re-entering anything. The full validated response is stashed
        // in the installer state file — the DB doesn't exist yet (it's set up
        // at Step 4) — and gets written into the company row at completion.
        ($writeEnv)([
            'LICENSE_REGISTRATION_TOKEN' => $result['response']['token'] ?? '',
            'LICENSE_KEY' => $data['license_key'],
            'LICENSE_FINGERPRINT' => $result['fingerprint'] ?? '',
        ]);

        InstallState::setMany([
            'license_validated' => true,
            'license_key_hash' => hash('sha256', $data['license_key']),
            'license' => $result['response'] ?? [],
        ]);

        if ($saas->selected($request)) {
            $saas->recordCorePurchase($request, 'verified');
            $handoff = $saas->begin($request);

            return $request->expectsJson()
                ? response()->json(['ok' => true, 'redirect' => $handoff->getTargetUrl()])
                : $handoff;
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'redirect' => route('install.database')]);
        }

        return redirect()->route('install.database');
    }

    public function bypassCorePurchase(Request $request, InstallerSaasEntry $saas)
    {
        abort_unless($saas->selected($request) && $saas->available()
            && $saas->freshAdminReady() && $saas->corePurchaseLaunchBypass(), 404);

        // This is session-only scope. Never write a fake core purchase code,
        // registration token, or validated state to the ordinary POS install.
        $saas->recordCorePurchase($request, 'launch_bypass');
        $handoff = $saas->begin($request);

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'redirect' => $handoff->getTargetUrl()])
            : $handoff;
    }
}
