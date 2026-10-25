<?php

namespace App\Http\Controllers\Installer;

use App\Contracts\Installer\FreshSaasHandoff;
use App\Contracts\Installer\FreshSaasProgressAdvance;
use App\Contracts\Installer\FreshSaasVpsSetup;
use App\Http\Controllers\Controller;
use App\Support\InstallerSaasEntry;
use Illuminate\Http\Request;

class SaasProgressController extends Controller
{
    public function show(Request $request, InstallerSaasEntry $saas)
    {
        $this->adapter($request, $saas);
        abort_unless($saas->handoffStarted($request), 404);

        return view('installer.saas-progress', [
            'currentStep' => 6,
            'platformLoginUrl' => $this->platformLoginUrl($saas),
        ]);
    }

    public function status(Request $request, InstallerSaasEntry $saas)
    {
        $adapter = $this->adapter($request, $saas);
        abort_unless($saas->handoffStarted($request), 404);
        try {
            $progress = $adapter->platformProgress($request);
        } catch (\Throwable) {
            $progress = null;
        }

        return response()->json($progress ?? [
            'status' => 'checking', 'completed_steps' => 0, 'total_steps' => 6,
            'current_step' => null, 'error' => null, 'ready' => false, 'observed_at' => null,
        ]);
    }

    public function advance(Request $request, InstallerSaasEntry $saas)
    {
        $adapter = $this->adapter($request, $saas);
        abort_unless($saas->handoffStarted($request), 404);
        try {
            $progress = $adapter instanceof FreshSaasProgressAdvance
                ? $adapter->advanceShared($request) : $adapter->platformProgress($request);
        } catch (\Throwable) {
            $progress = null;
        }
        return response()->json($progress ?? [
            'status' => 'checking', 'completed_steps' => 0, 'total_steps' => 6,
            'current_step' => null, 'error' => null, 'ready' => false, 'observed_at' => null,
        ]);
    }

    private function adapter(Request $request, InstallerSaasEntry $saas): FreshSaasHandoff
    {
        abort_unless($saas->available() && $saas->selected($request), 404);
        $adapter = app(FreshSaasHandoff::class);
        try {
            $verified = $adapter->addonPurchaseVerified($request) && $adapter->ownerVerified($request);
        } catch (\Throwable) {
            $verified = false;
        }
        abort_unless($verified, 404);

        return $adapter;
    }

    private function platformLoginUrl(InstallerSaasEntry $saas): ?string
    {
        try {
            $mode = $saas->selectedHostingMode();
            $state = match ($mode) {
                'shared_manual' => $saas->sharedHostingState(),
                'vps_automatic' => app()->bound(FreshSaasVpsSetup::class)
                    ? app(FreshSaasVpsSetup::class)->state() : [],
                default => [],
            };
            $domain = $state['platform_domain'] ?? null;
            if (($state['configured'] ?? false) !== true || ! is_string($domain)
                || ! str_contains($domain, '.') || str_ends_with($domain, '.')
                || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                || filter_var($domain, FILTER_VALIDATE_IP) !== false) return null;

            return 'https://'.$domain.'/login';
        } catch (\Throwable) {
            return null;
        }
    }
}
