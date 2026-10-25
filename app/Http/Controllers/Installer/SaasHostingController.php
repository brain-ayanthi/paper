<?php

namespace App\Http\Controllers\Installer;

use App\Contracts\Installer\FreshSaasVpsSetup;
use App\Http\Controllers\Controller;
use App\Support\InstallerSaasEntry;
use Illuminate\Http\Request;

class SaasHostingController extends Controller
{
    public function show(Request $request, InstallerSaasEntry $saas)
    {
        abort_unless($saas->choiceAvailable() && $saas->selected($request), 404);
        if (! $saas->freshAdminReady()) return redirect()->route('install.saas.admin');

        $mode = $saas->selectedHostingMode();
        $vps = app()->bound(FreshSaasVpsSetup::class)
            ? app(FreshSaasVpsSetup::class) : null;
        // The root launcher leaves an authenticated bootstrap receipt. Its
        // presence is a stronger signal than web-server fingerprints.
        $bootstrapReady = false;
        try {
            $bootstrapReady = $vps !== null && ($vps->state()['bootstrap_ready'] ?? false) === true;
        } catch (\Throwable) {
            // Leave the choice screen available when host evidence is unreadable.
        }
        $detected = $bootstrapReady ? 'vps_automatic' : 'shared_manual';
        if ($mode === null || ($bootstrapReady && $mode !== $detected)) {
            try {
                if ($saas->selectHostingMode($detected)) {
                    $mode = $detected;
                }
            } catch (\Throwable) {
                // A sealed or unsafe state must remain visible for review.
            }
        }
        if ($mode !== null) {
            if ($saas->available()) {
                return redirect()->route('install.requirements');
            }
            if ($mode === 'vps_automatic' && $vps?->available()) {
                return redirect()->route('install.saas.vps');
            }
            if ($mode === 'shared_manual' && $saas->sharedHostingAvailable()) {
                return redirect()->route('install.saas.shared-hosting');
            }
        }

        return view('installer.saas-hosting', [
            'currentStep' => 3,
            'capabilities' => $saas->hostingCapabilities(),
            'selectedMode' => $saas->selectedHostingMode(),
            'runtimeReady' => $saas->available(),
        ]);
    }

    public function choose(Request $request, InstallerSaasEntry $saas)
    {
        abort_unless($saas->choiceAvailable() && $saas->selected($request), 404);
        if (! $saas->freshAdminReady()) return redirect()->route('install.saas.admin');


        $data = $request->validate([
            'hosting_mode' => ['required', 'string', 'in:shared_manual,vps_automatic'],
        ]);

        try {
            $selected = $saas->selectHostingMode($data['hosting_mode']);
        } catch (\Throwable) {
            return $this->invalid($request, __('installer.hosting.selection_unavailable'));
        }
        if (! $selected) {
            return $this->invalid($request, __('installer.hosting.selection_unavailable'));
        }

        $vpsAvailable = app()->bound(FreshSaasVpsSetup::class)
            && app(FreshSaasVpsSetup::class)->available();
        $redirect = $saas->available()
            ? route('install.requirements')
            : ($data['hosting_mode'] === 'shared_manual' && $saas->sharedHostingAvailable()
                ? route('install.saas.shared-hosting')
                : ($data['hosting_mode'] === 'vps_automatic' && $vpsAvailable
                    ? route('install.saas.vps')
                    : route('install.saas.hosting')));
        $message = $saas->available()
            ? __('installer.hosting.saved')
            : __('installer.hosting.saved_pending');

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'redirect' => $redirect, 'message' => $message])
            : redirect($redirect)->with('success', $message);
    }

    private function invalid(Request $request, string $message)
    {
        return $request->expectsJson()
            ? response()->json([
                'message' => $message,
                'errors' => ['hosting_mode' => [$message]],
            ], 422)
            : back()->withErrors(['hosting_mode' => $message]);
    }
}
