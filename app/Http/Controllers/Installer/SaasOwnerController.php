<?php

namespace App\Http\Controllers\Installer;

use App\Contracts\Installer\FreshSaasHandoff;
use App\Http\Controllers\Controller;
use App\Support\InstallerSaasEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SaasOwnerController extends Controller
{
    public function show(Request $request, InstallerSaasEntry $saas)
    {
        $adapter = $this->adapter($request, $saas);

        return view('installer.saas-owner', [
            'currentStep' => 5,
            'ownerVerified' => $this->ownerIsVerified($adapter, $request),
        ]);
    }

    public function requestVerification(Request $request, InstallerSaasEntry $saas)
    {
        $adapter = $this->adapter($request, $saas);
        $validator = Validator::make($request->all(), [
            'saas_owner_email' => ['required', 'email', 'max:254'],
        ]);
        if ($validator->fails()) {
            return $this->failure($request, 'saas_owner_email', __('installer.saas_owner.email_invalid'));
        }

        try {
            $sent = $adapter->requestOwnerVerification($request,
                strtolower(trim((string) $validator->validated()['saas_owner_email'])));
        } catch (\Throwable) {
            $sent = false;
        }
        if (! $sent) {
            return $this->failure($request, 'saas_owner_email', __('installer.saas_owner.unavailable'), 503);
        }

        $request->session()->flash('installer_saas_owner_email_sent', true);

        return $this->continue($request, route('install.saas.owner'));
    }

    public function confirm(Request $request, InstallerSaasEntry $saas)
    {
        $adapter = $this->adapter($request, $saas);
        $validator = Validator::make($request->all(), [
            'saas_owner_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
        ]);
        if ($validator->fails()) {
            return $this->failure($request, 'saas_owner_token', __('installer.saas_owner.token_invalid'));
        }
        try {
            $verified = $adapter->confirmOwnerVerification($request,
                (string) $validator->validated()['saas_owner_token']);
        } catch (\Throwable) {
            $verified = false;
        }
        if (! $verified) {
            return $this->failure($request, 'saas_owner_token', __('installer.saas_owner.token_invalid'));
        }

        return $this->continue($request, route('install.saas.owner'));
    }

    public function handoff(Request $request, InstallerSaasEntry $saas)
    {
        $adapter = $this->adapter($request, $saas);
        abort_unless($this->ownerIsVerified($adapter, $request), 404);

        try {
            $redirect = $adapter->beginHostHandoff($request);
        } catch (\Throwable) {
            $redirect = null;
        }
        if ($redirect === null) {
            return $this->failure($request, 'saas_owner_token', __('installer.saas_owner.handoff_unavailable'), 503);
        }

        return $this->continue($request, $redirect->getTargetUrl());
    }

    private function adapter(Request $request, InstallerSaasEntry $saas): FreshSaasHandoff
    {
        abort_unless($saas->available() && $saas->selected($request)
            && $saas->freshAdminReady() && ! $saas->signedDeliveryReady(), 404);
        $adapter = app(FreshSaasHandoff::class);
        try {
            $verified = $adapter->addonPurchaseVerified($request);
        } catch (\Throwable) {
            $verified = false;
        }
        abort_unless($verified, 404);

        return $adapter;
    }

    private function ownerIsVerified(FreshSaasHandoff $adapter, Request $request): bool
    {
        try {
            return $adapter->ownerVerified($request);
        } catch (\Throwable) {
            return false;
        }
    }

    private function continue(Request $request, string $redirect)
    {
        return $request->expectsJson()
            ? response()->json(['ok' => true, 'redirect' => $redirect])
            : redirect($redirect);
    }

    private function failure(Request $request, string $field, string $message, int $status = 422)
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message, 'errors' => [$field => [$message]]], $status)
            : back()->withErrors([$field => $message]);
    }
}
