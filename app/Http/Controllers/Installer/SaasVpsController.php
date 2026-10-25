<?php

namespace App\Http\Controllers\Installer;

use App\Contracts\Installer\FreshSaasVpsSetup;
use App\Http\Controllers\Controller;
use App\Support\InstallerSaasEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SaasVpsController extends Controller
{
    public function show(Request $request, InstallerSaasEntry $saas, FreshSaasVpsSetup $setup)
    {
        $this->ensureReady($request, $saas, $setup);
        return view('installer.saas-vps', ['currentStep' => 3, 'setup' => $setup->state(),
            'runtimeReady' => $saas->available()]);
    }

    public function store(Request $request, InstallerSaasEntry $saas, FreshSaasVpsSetup $setup)
    {
        $this->ensureReady($request, $saas, $setup);
        $validator = Validator::make($request->all(), [
            'platform_domain' => ['required', 'string', 'max:253'],
            'workspace_domain' => ['required', 'string', 'max:253'],
            'platform_database' => ['required', 'string', 'max:64'],
            'mail_host' => ['required', 'string', 'max:253'],
            'mail_port' => ['required', 'integer', 'between:1,65535'],
            'mail_scheme' => ['required', 'string', 'in:smtp,smtps'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:4096'],
            'mail_from_address' => ['required', 'email:rfc', 'max:254'],
            'mail_from_name' => ['required', 'string', 'max:120'],
            'automatic_provisioning_acknowledged' => ['accepted'],
        ]);
        if ($validator->fails()) return $request->expectsJson()
            ? response()->json(['message' => __('installer.vps.validation_failed'),
                'errors' => $validator->errors()->toArray()], 422)
            : back()->withErrors($validator)->withInput($request->except('mail_password'));
        try { $configured = $setup->configure($validator->validated()); }
        catch (\Throwable) { $configured = false; }
        if (!$configured) {
            $message = __('installer.vps.configuration_failed');
            return $request->expectsJson()
                ? response()->json(['message' => $message, 'errors' => ['platform_domain' => [$message]]], 422)
                : back()->withErrors(['platform_domain' => $message])->withInput($request->except('mail_password'));
        }
        return $request->expectsJson()
            ? response()->json(['ok' => true, 'redirect' => route('install.saas.vps'),
                'message' => __('installer.vps.saved')])
            : redirect()->route('install.saas.vps')->with('success', __('installer.vps.saved'));
    }

    private function ensureReady(Request $request, InstallerSaasEntry $saas, FreshSaasVpsSetup $setup): void
    {
        abort_unless($saas->choiceAvailable() && $saas->selected($request)
            && $saas->freshAdminReady()
            && $saas->selectedHostingMode() === 'vps_automatic' && $setup->available(), 404);
    }
}
