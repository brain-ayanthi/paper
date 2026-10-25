<?php

namespace App\Http\Controllers\Installer;

use App\Contracts\Installer\FreshSaasHandoff;
use App\Http\Controllers\Controller;
use App\Support\InstallerSaasEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SaasPurchaseController extends Controller
{
    public function show(Request $request, InstallerSaasEntry $saas)
    {
        $this->ensureReady($request, $saas);

        return view('installer.saas-purchase', [
            'currentStep' => 4,
            'testPurchaseBypass' => $saas->testPurchaseBypass(),
        ]);
    }

    public function submit(Request $request, InstallerSaasEntry $saas)
    {
        $this->ensureReady($request, $saas);
        $testBypass = $saas->testPurchaseBypass();
        $validator = Validator::make($request->all(), [
            'saas_purchase_code' => [$testBypass ? 'nullable' : 'required', 'string', 'max:128'],
        ]);
        if ($validator->fails()) {
            return $this->failure($request, __('installer.saas_purchase.code_required'));
        }

        try {
            $redirect = app(FreshSaasHandoff::class)->submitAddonPurchase(
                $request,
                trim((string) ($validator->validated()['saas_purchase_code'] ?? '')),
            );
        } catch (\Throwable) {
            // Provider failures may contain secrets; show only an owned message.
            return $this->failure($request, __('installer.saas_purchase.unavailable'), 503);
        }

        if ($redirect === null) {
            return $this->failure($request, __('installer.saas_purchase.invalid'));
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'redirect' => $redirect->getTargetUrl()])
            : $redirect;
    }

    private function ensureReady(Request $request, InstallerSaasEntry $saas): void
    {
        abort_unless($saas->available() && $saas->selected($request)
            && $saas->freshAdminReady()
            && ! $saas->signedDeliveryReady()
            && $saas->corePurchaseScope($request) !== null, 404);
    }

    private function failure(Request $request, string $message, int $status = 422)
    {
        return $request->expectsJson()
            ? response()->json([
                'message' => $message,
                'errors' => ['saas_purchase_code' => [$message]],
            ], $status)
            : back()->withErrors(['saas_purchase_code' => $message]);
    }
}
