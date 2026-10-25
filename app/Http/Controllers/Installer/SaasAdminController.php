<?php

namespace App\Http\Controllers\Installer;

use App\Http\Controllers\Controller;
use App\Support\InstallState;
use App\Support\InstallerModeState;
use App\Support\InstallerSaasEntry;
use App\Support\Installer\FreshSaasAdminCredentials;
use App\Support\PasswordRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Collects the platform administrator before any POS business schema exists. */
final class SaasAdminController extends Controller
{
    public function show(Request $request, InstallerSaasEntry $saas)
    {
        $mode = $this->mode($request, $saas);
        try {
            $saved = $this->store()->read($mode);
        } catch (\Throwable $error) {
            report($error);
            abort(409, __('installer.saas_admin.state_unavailable'));
        }

        if ($saved !== null) return redirect()->route('install.saas.hosting');

        return view('installer.saas-admin', ['currentStep' => 3]);
    }

    public function save(Request $request, InstallerSaasEntry $saas)
    {
        $mode = $this->mode($request, $saas);
        $validator = Validator::make($request->all(), [
            'admin_name' => ['required', 'string', 'max:191'],
            'admin_email' => ['required', 'email:rfc', 'max:191'],
            'admin_password' => ['required', 'string', 'min:12', 'max:72', 'confirmed', PasswordRules::strong()],
        ]);
        if ($validator->fails()) {
            return $request->expectsJson()
                ? response()->json(['message' => __('installer.saas_admin.validation_failed'),
                    'errors' => $validator->errors()->toArray()], 422)
                : back()->withErrors($validator)
                    ->withInput($request->except(['admin_password', 'admin_password_confirmation']));
        }
        $data = $validator->validated();

        try {
            $this->store()->save($mode, $data['admin_name'], $data['admin_email'],
                $data['admin_password']);
        } catch (\Throwable) {
            $message = __('installer.saas_admin.unavailable');
            return $request->expectsJson()
                ? response()->json(['message' => $message,
                    'errors' => ['admin_email' => [$message]]], 409)
                : back()->withErrors(['admin_email' => $message])
                    ->withInput($request->except(['admin_password', 'admin_password_confirmation']));
        }

        $redirect = route('install.saas.hosting');
        return $request->expectsJson()
            ? response()->json(['ok' => true, 'redirect' => $redirect])
            : redirect($redirect);
    }

    private function mode(Request $request, InstallerSaasEntry $saas): array
    {
        abort_unless($saas->choiceAvailable() && $saas->selected($request)
            && ! $saas->handoffStarted($request)
            && ! InstallState::get('db_configured', false)
            && ! InstallState::get('migrations_run', false)
            && ! InstallState::get('admin_created', false), 404);
        $source = app()->bound('saas.fresh.prepared_source')
            ? app('saas.fresh.prepared_source') : null;
        abort_unless(is_array($source), 404);
        $identity = [];
        foreach (['delivery_sha256', 'manifest_sha256', 'trusted_key_sha256',
            'addon_zip_sha256'] as $field) {
            abort_unless(is_string($source[$field] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/D', $source[$field]), 404);
            $identity[$field] = $source[$field];
        }
        $digest = hash('sha256', json_encode($identity,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $mode = app(InstallerModeState::class)->read($digest);
        abort_unless(($mode['status'] ?? null) === 'valid'
            && ($mode['mode'] ?? null) === InstallerSaasEntry::MODE_SAAS
            && $mode['prepared_source_sha256'] === $digest, 404);
        return $mode;
    }

    private function store(): FreshSaasAdminCredentials
    {
        return new FreshSaasAdminCredentials(
            storage_path('app/private/saas-fresh-installer/admin'),
            (string) config('app.key', ''),
        );
    }
}
