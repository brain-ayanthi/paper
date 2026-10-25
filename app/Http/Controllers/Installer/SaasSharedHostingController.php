<?php

namespace App\Http\Controllers\Installer;

use App\Http\Controllers\Controller;
use App\Support\Installer\SharedHostingFailureDiagnostic;
use App\Support\InstallerSaasEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SaasSharedHostingController extends Controller
{
    public function show(Request $request, InstallerSaasEntry $saas)
    {
        $this->ensureReady($request, $saas);

        return view('installer.saas-shared-hosting', [
            'currentStep' => 3,
            'setup' => $saas->sharedHostingState(),
            'runtimeReady' => $saas->available(),
            'directInstall' => true,
            'platformRootExample' => dirname(base_path()).DIRECTORY_SEPARATOR.'saas-platform',
            'schedulerCommand' => 'cd '.escapeshellarg(base_path()).' && '
                .escapeshellarg(PHP_BINDIR.'/php').' artisan schedule:run >> /dev/null 2>&1',
        ]);
    }

    public function store(Request $request, InstallerSaasEntry $saas)
    {
        $this->ensureReady($request, $saas);
        $direct = true;
        $validator = Validator::make($request->all(), [
            'platform_root' => ['required', 'string', 'max:1024'],
            'platform_domain' => ['required', 'string', 'max:253'],
            'workspace_domain' => ['required', 'string', 'max:253'],
            'platform_database' => ['required', 'string', 'max:64'],
            'database_host' => ['required', 'string', 'max:253'],
            'database_port' => ['required', 'integer', 'between:1,65535'],
            'database_username' => ['required', 'string', 'max:128'],
            'database_password' => ['nullable', 'string', 'max:4096'],
            'mail_host' => [$direct ? 'sometimes' : 'required', 'string', 'max:253'],
            'mail_port' => [$direct ? 'sometimes' : 'required', 'integer', 'between:1,65535'],
            'mail_scheme' => [$direct ? 'sometimes' : 'required', 'string', 'in:smtp,smtps'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:4096'],
            'mail_from_address' => [$direct ? 'sometimes' : 'required', 'email:rfc', 'max:254'],
            'mail_from_name' => [$direct ? 'sometimes' : 'required', 'string', 'max:120'],
            'manual_provisioning_acknowledged' => $direct ? ['sometimes', 'accepted'] : ['accepted'],
            'cron_acknowledged' => $direct ? ['sometimes', 'accepted'] : ['accepted'],
        ]);
        if ($validator->fails()) {
            return $request->expectsJson()
                ? response()->json([
                    'message' => __('installer.shared_hosting.validation_failed'),
                    'errors' => $validator->errors()->toArray(),
                ], 422)
                : back()->withErrors($validator)->withInput($request->except([
                    'database_password', 'mail_password',
                ]));
        }

        try {
            $configured = $saas->configureSharedHosting($validator->validated());
        } catch (\Throwable) {
            $configured = false;
        }
        if (! $configured) {
            $code = (method_exists($saas, 'sharedHostingFailureCode')
                ? $saas->sharedHostingFailureCode() : null)
                ?? (new SharedHostingFailureDiagnostic)->code($validator->validated());
            $message = $code === null
                ? __('installer.shared_hosting.configuration_failed')
                : __('installer.shared_hosting.failure_'.$code);

            return $request->expectsJson()
                ? response()->json([
                    'message' => $message,
                    'errors' => ['configuration' => [$message]],
                ], 422)
                : back()->withErrors(['configuration' => $message])
                    ->withInput($request->except(['database_password', 'mail_password']));
        }

        $redirect = route('install.saas.shared-hosting');

        return $request->expectsJson()
            ? response()->json([
                'ok' => true,
                'redirect' => $redirect,
                'message' => __('installer.shared_hosting.saved'),
            ])
            : redirect($redirect)->with('success', __('installer.shared_hosting.saved'));
    }

    private function ensureReady(Request $request, InstallerSaasEntry $saas): void
    {
        abort_unless($saas->choiceAvailable() && $saas->selected($request)
            && $saas->freshAdminReady()
            && $saas->selectedHostingMode() === 'shared_manual'
            && $saas->sharedHostingAvailable(), 404);
    }
}
