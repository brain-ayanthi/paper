<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\ReverseJournalEntry;
use App\Exceptions\EntryNotReversible;
use App\Exceptions\PeriodLockedException;
use App\Http\Controllers\Admin\Concerns\ResolvesReportFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreManualJournalEntryRequest;
use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The journal / day book (docs/features/accounting.md §5, §6.2, §7.6). Lists
 * posted entries, shows one entry's lines, posts manual entries, and reverses a
 * posted entry. Viewing needs `accounting.view`; posting needs
 * `accounting.manual_entry`; reversing needs `accounting.reverse_entry`.
 *
 * Store scope (SEC #95): the list already filtered by store, but `show` and
 * `reverse` bound an entry straight off the URL and checked only the
 * permission — so a store-restricted accountant could read any branch's
 * journal, and REVERSE it, by guessing an id. Both now call
 * `abort_unless_store_access()`.
 *
 * The register logged this as "company scope, not a store leak" on the
 * assumption that `journal_entries.store_id` is often NULL. It is not: the
 * column is NOT NULL (see `2026_05_21_000013_create_journal`), so every entry
 * belongs to exactly one store and the store boundary is the right control for
 * all of them. The null-guard below is kept only because
 * `abort_unless_store_access()` takes a nullable and a future company-level
 * posting shouldn't 500 here.
 */
class JournalEntryController extends Controller
{
    use ResolvesReportFilters;

    public function __construct(
        private PostJournalEntry $post,
        private ReverseJournalEntry $reverse,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('accounting.view'), 403);

        [$from, $to, $storeId, $period] = $this->reportFilters($request);
        $source = (string) $request->query('source', '');

        $entries = JournalEntry::query()
            ->posted()
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->whereDate('entry_date', '>=', $from->toDateString())
            ->whereDate('entry_date', '<=', $to->toDateString())
            ->when($source !== '', fn ($q) => $q->where('source', $source))
            ->withSum('lines', 'debit')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get();

        return view('admin.accounting.journal.index', [
            'entries' => $entries,
            'from'    => $from,
            'to'      => $to,
            'storeId' => $storeId,
            'period'  => $period,
            'source'  => $source,
            'sources' => JournalEntry::SOURCES ?? [],
            'stores'  => accessible_stores(),
        ]);
    }

    public function show(Request $request, JournalEntry $entry): View
    {
        abort_unless($request->user()?->hasPermission('accounting.view'), 403);
        abort_unless_store_access($entry->store_id !== null ? (int) $entry->store_id : null);

        $entry->load(['lines.account', 'store', 'createdBy', 'reversalOf', 'reversedBy']);

        return view('admin.accounting.journal.show', ['entry' => $entry]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('accounting.manual_entry'), 403);

        return view('admin.accounting.journal.create', [
            'accounts' => Account::active()->orderBy('code')->get(['id', 'code', 'name']),
            'stores'   => accessible_stores(),
        ]);
    }

    public function store(StoreManualJournalEntryRequest $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('accounting.manual_entry'), 403);

        $lines = collect($request->input('lines'))
            ->map(fn ($l) => [
                'account_id'  => (int) $l['account_id'],
                'debit'       => $l['debit'] ?: 0,
                'credit'      => $l['credit'] ?: 0,
                'description' => $l['description'] ?? null,
            ])->all();

        try {
            $entry = ($this->post)([
                'store_id'    => (int) ($request->input('store_id') ?: current_store_id()),
                'entry_date'  => $request->input('entry_date'),
                'source'      => JournalEntry::SOURCE_MANUAL,
                'description' => $request->input('description'),
                'created_by'  => $request->user()->id,
                'lines'       => $lines,
            ]);
        } catch (PeriodLockedException) {
            return back()->withInput()->withErrors(['entry_date' => __('accounting.journal.period_locked')]);
        }

        return redirect()
            ->route('admin.accounting.journal.show', $entry)
            ->with('status', __('accounting.journal.posted'));
    }

    public function reverse(Request $request, JournalEntry $entry): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('accounting.reverse_entry'), 403);
        abort_unless_store_access($entry->store_id !== null ? (int) $entry->store_id : null);
        abort_unless($entry->is_posted, 404);

        $fail = function (string $message) use ($request): JsonResponse|RedirectResponse {
            if ($request->wantsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['reverse' => $message]);
        };

        if ($entry->reversed_by_id) {
            return $fail(__('accounting.journal.already_reversed'));
        }

        // A sale-void entry IS itself the reversal of a sale (SEC #43 /
        // defect #120). Reversing it re-applies the original sale's postings
        // with no sale behind them, so revenue and COGS reappear for a sale
        // that no longer exists. The Reverse button is hidden for this source,
        // but hiding a button is not a control — a direct POST went straight
        // through. Enforced here, where the decision actually happens.
        if ($entry->source === JournalEntry::SOURCE_SALE_VOID) {
            return $fail(__('accounting.journal.not_reversible'));
        }

        try {
            $reversal = ($this->reverse)($entry, JournalEntry::SOURCE_MANUAL);
        } catch (PeriodLockedException) {
            return $fail(__('accounting.journal.period_locked'));
        } catch (EntryNotReversible $e) {
            // The checks above are the friendly path; this is the authoritative
            // one, taken under a row lock inside the action, and it is what
            // catches a genuine double-click (defect #106).
            return $fail($e->getMessage());
        }

        $redirect = route('admin.accounting.journal.show', $reversal);
        $message  = __('accounting.journal.reversed');

        if ($request->wantsJson()) {
            return response()->json(['message' => $message, 'redirect' => $redirect]);
        }

        return redirect($redirect)->with('status', $message);
    }
}
