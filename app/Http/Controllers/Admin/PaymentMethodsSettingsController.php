<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Manual payment methods settings — Cash, Card (record-only),
 * UPI, Bank Transfer, Cheque, etc. The admin can toggle each
 * on/off from here so the cashier only sees the methods they
 * actually use.
 *
 * Gateway-backed methods (Stripe, Razorpay, Paystack, Flutterwave,
 * Mercado Pago) are managed via /admin/settings/payment-gateways
 * and deliberately excluded from this list — they live through the
 * "Charge via QR" flow, not the manual tile list.
 */
class PaymentMethodsSettingsController extends Controller
{
    use RespondsJsonOrRedirect;

    public function edit(): View
    {
        $this->authorize('settings.view');

        // Only manual methods — gateway-backed rows (Stripe etc.)
        // get their own settings page. Same filter shape the cashier
        // uses to hide gateway rows from the manual tile list.
        // `store_credit` is excluded because v1 ships without the
        // store-credit feature — keep the row in the DB for any
        // legacy references, just don't surface it in the admin UI.
        $methods = PaymentMethod::query()
            ->where(function ($q) {
                $q->whereNull('provider')
                    ->orWhere('provider', '')
                    ->orWhere('provider', 'none');
            })
            ->where('code', '!=', 'store_credit')
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'icon', 'color', 'is_active', 'sort_order', 'requires_reference', 'provider_credentials']);

        return view('admin.settings.payment-methods', [
            'methods' => $methods,
        ]);
    }

    /**
     * Create a record-only payment method. It deliberately has no provider:
     * checkout records the customer's reference instead of contacting a
     * gateway, and the method immediately joins the normal cashier tile list.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.update');

        if (pos_is_demo()) {
            return $this->jsonOrError(
                $request,
                __('settings.demo.locked'),
                route('admin.settings.payment-methods.edit'),
            );
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:card,digital,other'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $name = trim($data['name']);

        $method = PaymentMethod::query()->create([
            'code' => $this->uniqueCode($name),
            'name' => $name,
            'type' => $data['type'],
            'provider' => 'none',
            'requires_reference' => true,
            'opens_cash_drawer' => false,
            'sort_order' => ((int) PaymentMethod::query()->max('sort_order')) + 1,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->jsonOrStay(
            $request,
            __('settings.payment_methods.flash.created', ['name' => $method->name]),
            route('admin.settings.payment-methods.edit'),
            ['refresh' => '#payment-methods-list-card'],
        );
    }

    /**
     * Toggle the `is_active` flag on a single manual method. Returns
     * JSON for the inline toggle (no full-page reload) and a redirect
     * for the no-JS fallback.
     */
    public function toggle(Request $request, PaymentMethod $method): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.update');

        if (pos_is_demo()) {
            return $this->jsonOrError(
                $request,
                __('settings.demo.locked'),
                route('admin.settings.payment-methods.edit'),
            );
        }

        // Defend against a malicious POST flipping a gateway row
        // through this endpoint — gateway rows are managed elsewhere.
        if (! \in_array((string) $method->provider, ['', 'none'], true) && $method->provider !== null) {
            abort(403, 'Gateway-backed methods are managed under Payment Gateways.');
        }

        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $method->is_active = (bool) $request->boolean('is_active');
        $method->save();

        return $this->jsonOrRedirect(
            $request,
            __('settings.payment_methods.flash.saved', ['name' => $method->name]),
            route('admin.settings.payment-methods.edit'),
        );
    }

    /**
     * UPI-specific: store the merchant's Virtual Payment Address and
     * the display name shown in the customer's UPI app on scan. The
     * cashier renders these as a `upi://pay?pa=…&pn=…` QR so customers
     * can scan and pay in their UPI app — no API, no webhook, the
     * cashier still confirms the payment manually via the reference
     * field (UPI UTR).
     *
     * Both fields are optional individually so the admin can save the
     * VPA without setting the payee name (defaults to the store name),
     * and clear either by submitting an empty value.
     */
    public function updateUpi(Request $request, PaymentMethod $method): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.update');

        if (pos_is_demo()) {
            return $this->jsonOrError(
                $request,
                __('settings.demo.locked'),
                route('admin.settings.payment-methods.edit'),
            );
        }

        if ($method->code !== 'upi') {
            abort(404, 'UPI settings are only configurable on the UPI method.');
        }

        $data = $request->validate([
            // VPA shape: <handle>@<bank-id>. Reject obviously bogus
            // input early so the cashier QR never has a malformed
            // payment address. Empty is OK — clears the config.
            'vpa' => ['nullable', 'string', 'max:191', 'regex:/^[a-zA-Z0-9._\-]{2,}@[a-zA-Z0-9.\-]{2,}$/'],
            'payee_name' => ['nullable', 'string', 'max:99'],    // UPI spec caps `pn` at ~99 chars in practice
        ], [
            'vpa.regex' => __('settings.payment_methods.upi.vpa_invalid'),
        ]);

        $creds = $method->provider_credentials ?? [];

        $vpa = trim((string) ($data['vpa'] ?? ''));
        if ($vpa === '') {
            unset($creds['vpa']);
        } else {
            $creds['vpa'] = $vpa;
        }

        $payeeName = trim((string) ($data['payee_name'] ?? ''));
        if ($payeeName === '') {
            unset($creds['payee_name']);
        } else {
            $creds['payee_name'] = $payeeName;
        }

        $method->provider_credentials = $creds;
        $method->save();

        return $this->jsonOrRedirect(
            $request,
            __('settings.payment_methods.upi.flash_saved'),
            route('admin.settings.payment-methods.edit'),
        );
    }

    /** Generate an immutable, URL-safe code without exposing it as admin input. */
    private function uniqueCode(string $name): string
    {
        $base = Str::of($name)->slug('_')->lower()->substr(0, 32)->toString();
        $base = $base !== '' ? $base : 'custom_payment';
        $code = $base;
        $suffix = 2;

        while (PaymentMethod::query()->where('code', $code)->exists()) {
            $tail = '_'.$suffix++;
            $code = substr($base, 0, 32 - strlen($tail)).$tail;
        }

        return $code;
    }
}
