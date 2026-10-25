<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Settings\UpdateCurrencySettings;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CurrencySettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Base-currency settings — pick the company's currency from the seeded
 * ISO list and edit its display format (symbol, placement, decimals,
 * separators). Writes through to the `currencies` row + `company`
 * (see {@see UpdateCurrencySettings}); the whole app reads it via
 * `app_currency()`.
 *
 * Per-store currency switching + exchange-rate conversion are planned
 * (docs: multi-store, payments) and arrive with the Sales module.
 */
class CurrencySettingsController extends Controller
{
    use RespondsJsonOrRedirect;

    public function edit(): View
    {
        $this->authorize('settings.view');

        $currencies = DB::table('currencies')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name', 'symbol', 'symbol_first', 'decimals', 'thousands_separator', 'decimal_separator']);

        return view('admin.settings.currency', [
            'currencies' => $currencies,
            'current'    => app_currency(),
        ]);
    }

    public function update(CurrencySettingsRequest $request, UpdateCurrencySettings $update): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.update');

        // Read-only on the public demo. Base currency is the most far-reaching
        // setting in the app — `app_currency()` feeds every price, receipt and
        // report — so one visitor switching it re-labels the whole demo for
        // everyone else until the nightly reset (see ResetDemoData).
        if (pos_is_demo()) {
            return $this->jsonOrError(
                $request,
                __('settings.demo.locked'),
                route('admin.settings.currency.edit'),
            );
        }

        ($update)($request->input('base_currency_code'), $request->formatAttributes());

        return $this->jsonOrRedirect(
            $request,
            __('settings.currency.flash.updated'),
            route('admin.settings.currency.edit'),
        );
    }
}
