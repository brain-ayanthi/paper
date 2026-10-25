<?php

namespace App\Http\Controllers\Installer;

use App\Http\Controllers\Controller;
use App\Support\InstallerSaasEntry;
use App\Support\InstallState;
use Illuminate\Http\Request;

class ModeController extends Controller
{
    public function show(Request $request, InstallerSaasEntry $saas)
    {
        if (InstallState::get('db_configured', false)
            || InstallState::get('migrations_run', false)
            || InstallState::get('admin_created', false)) {
            return redirect()->route('install.requirements');
        }
        if (! config('pos.fresh_saas_installer.enabled', false) && ! $saas->choiceAvailable()) {
            return redirect()->route('install.requirements');
        }
        abort_if($saas->recoveryRequired(), 503, __('installer.saas.recovery_required'));

        return view('installer.mode', ['currentStep' => 2]);
    }

    public function choose(Request $request, InstallerSaasEntry $saas)
    {
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:standard,saas'],
        ]);

        if ($data['mode'] === InstallerSaasEntry::MODE_SAAS && ! $saas->choiceAvailable()) {
            if (! config('pos.fresh_saas_installer.enabled', false)
                || InstallState::get('db_configured', false)
                || InstallState::get('migrations_run', false)
                || InstallState::get('admin_created', false)
                || $saas->recoveryRequired()) {
                return $this->invalid($request, __('installer.saas.unavailable'));
            }
            $redirect = route('install.saas.package');
            return $request->expectsJson()
                ? response()->json(['ok' => true, 'redirect' => $redirect])
                : redirect($redirect);
        }

        if (! $saas->selectMode($request, $data['mode'])) {
            return $this->invalid($request, __('installer.saas.recovery_required'));
        }
        $redirect = $data['mode'] === InstallerSaasEntry::MODE_SAAS
            && $saas->hostingChoiceAvailable()
                ? route($saas->freshAdminReady()
                    ? 'install.saas.hosting' : 'install.saas.admin')
                : route('install.requirements');

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'redirect' => $redirect])
            : redirect($redirect);
    }

    private function invalid(Request $request, string $message)
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message, 'errors' => ['mode' => [$message]]], 422)
            : back()->withErrors(['mode' => $message]);
    }
}
