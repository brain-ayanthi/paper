<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Accounting\UpdateAccountMappings;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Business-event → account mappings (docs/features/accounting.md §3.4). Lets
 * the customer re-point named events (sales_revenue, cogs, tax_output, …) at
 * a different ledger account without touching code. Edits the global defaults
 * (store_id = null) that every auto-posting action falls back to. Viewing
 * needs `accounting.view`; saving needs `accounting.mappings.update`.
 */
class AccountMappingController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('accounting.view'), 403);

        $mappings = AccountMapping::query()
            ->whereNull('store_id')
            ->with('account')
            ->get()
            ->sortBy('key')
            ->keyBy('key');

        $accounts = Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        return view('admin.accounting.mappings.index', [
            'mappings' => $mappings,
            'accounts' => $accounts,
        ]);
    }

    public function update(Request $request, UpdateAccountMappings $action): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('accounting.mappings.update'), 403);

        $validated = $request->validate([
            'mappings'   => ['required', 'array'],
            // ACTIVE accounts only (SEC #42 / defect #117). The dropdown has
            // always offered active accounts, but the server accepted any id
            // that merely existed — so a crafted request could re-point a core
            // event at a deactivated account and every later posting would
            // follow it.
            'mappings.*' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_active', true)],
        ]);

        // Only mappings that already exist globally may be re-pointed here.
        $allowed = AccountMapping::query()->whereNull('store_id')->pluck('key')->all();
        $map     = collect($validated['mappings'])->only($allowed)->all();

        // …and the account must be of the right KIND for the event. Pointing
        // `sales_revenue` at an expense account leaves the trial balance
        // balanced, so nothing surfaces the mistake — the books are simply
        // wrong until someone reconciles by hand.
        $this->assertMappingTypes($map);

        $action($map);

        $message = __('accounting.mappings.flash.saved');

        if ($request->wantsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()
            ->route('admin.accounting.mappings.index')
            ->with('success', $message);
    }

    /**
     * Refuse a mapping pointed at an account of the wrong kind (SEC #42).
     *
     * Keys with no declared expectation pass — a plugin may add its own event
     * and we should not guess at its accounting treatment.
     *
     * @param  array<string, int>  $map  key => account id
     */
    private function assertMappingTypes(array $map): void
    {
        $expected = AccountMapping::EXPECTED_TYPES;
        $types    = Account::query()->whereIn('id', array_values($map))->pluck('type', 'id');

        $errors = [];

        foreach ($map as $key => $accountId) {
            $want = $expected[$key] ?? null;
            $got  = $types[$accountId] ?? null;

            if ($want !== null && $got !== null && $want !== $got) {
                $errors["mappings.{$key}"] = __('accounting.mappings.errors.wrong_type', [
                    'expected' => $want,
                ]);
            }
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }
}
