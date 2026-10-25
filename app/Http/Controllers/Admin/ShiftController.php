<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Hardware\PrepareZReportPayload;
use App\Actions\Shifts\AvailableDrawerCash;
use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\ComputeShiftTotals;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\RecordCashDrawerEntry;
use App\Actions\Shifts\RecordPayOutExpense;
use App\Exceptions\ShiftAlreadyOpen;
use App\Exceptions\ShiftNotOpen;
use App\Exceptions\VarianceReasonRequired;
use App\Http\Controllers\Concerns\RendersDataTableRows;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CloseShiftRequest;
use App\Http\Requests\Admin\OpenShiftRequest;
use App\Http\Requests\Admin\RecordCashDrawerEntryRequest;
use App\Models\CashDrawerEntry;
use App\Models\ExpenseCategory;
use App\Models\Shift;
use App\Models\ShiftVarianceReason;
use App\Models\Store;
use InvalidArgumentException;
use RuntimeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin surface for shifts — index / open / close / show.
 *
 * A user may own only one open shift installation-wide. The session-active
 * store (set by the topbar switcher) still scopes the list and each action.
 */
class ShiftController extends Controller
{
    use RendersDataTableRows;
    use RespondsJsonOrRedirect;

    public function __construct(
        private readonly ComputeShiftTotals $compute,
    ) {}

    /* ── Index ──────────────────────────────────────────────────── */

    /**
     * Shifts list — server-paginated. Only the first page renders inline;
     * search, sort and paging round-trip to {@see rows()}. Storewise +
     * permission-scoped (a cashier without `shifts.view_all` sees only theirs).
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Shift::class);

        $user    = $request->user();
        $storeId = active_store_id();
        $perPage = $this->dtPerPage($request);

        $paginator = $this->listQuery(new Request(['per_page' => $perPage]))->paginate($perPage);
        $summary   = $this->summaryFor(new Request());

        $activeShift = $user
            ? Shift::openForCashier($storeId, (int) $user->id)
            : null;

        return view('admin.shifts.index', [
            'shifts'       => $paginator->getCollection(),
            'perPage'      => $perPage,
            'total'        => $paginator->total(),
            'totalPages'   => max(1, $paginator->lastPage()),
            'summaryCards' => $this->summaryCards($summary),
            'activeShift'  => $activeShift,
        ]);
    }

    /**
     * One page of shift rows as an HTML fragment plus summary meta. See
     * {@see RendersDataTableRows}.
     */
    public function rows(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Shift::class);

        $paginator = $this->listQuery($request)
            ->paginate($this->dtPerPage($request), ['*'], 'page', $this->dtPage($request));

        return $this->dtRows($paginator, 'admin.shifts._rows', 'shifts', [
            'summary' => $this->summaryFor($request),
        ]);
    }

    /**
     * Store- and permission-scoped list, plus optional search on shift id,
     * cashier, or terminal. No eager loads or ordering — shared with the summary
     * aggregate so the cards describe the exact set on screen.
     *
     * @return Builder<Shift>
     */
    private function filteredBase(Request $request): Builder
    {
        $user       = $request->user();
        $storeId    = active_store_id();
        $canViewAll = $user?->hasPermission('shifts.view_all') ?? false;

        $query = Shift::query()
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when(! $canViewAll && $user, fn ($q) => $q->where('user_id', $user->id));

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(fn (Builder $w) => $w
                ->where('id', 'like', "%{$q}%")
                ->orWhereHas('cashier', fn ($c) => $c->where('name', 'like', "%{$q}%"))
                ->orWhereHas('terminal', fn ($terminal) => $terminal
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")));
        }

        return $query;
    }

    /** @return Builder<Shift> */
    private function listQuery(Request $request): Builder
    {
        return $this->filteredBase($request)
            ->with(['store:id,name', 'cashier:id,name', 'terminal:id,name,code'])
            ->orderByDesc('opened_at')
            ->orderByDesc('id');
    }

    /**
     * Summary-card totals honoring the scope + search. One aggregate query,
     * values arriving display-ready.
     *
     * @return array{total:string, open:string, variance:string}
     */
    private function summaryFor(Request $request): array
    {
        $row = $this->filteredBase($request)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END), 0) AS open_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'closed_with_variance' THEN 1 ELSE 0 END), 0) AS variance_count")
            ->first();

        return [
            'total'    => number_format((int) ($row->total_count ?? 0)),
            'open'     => number_format((int) ($row->open_count ?? 0)),
            'variance' => number_format((int) ($row->variance_count ?? 0)),
        ];
    }

    /**
     * @param  array{total:string, open:string, variance:string}  $summary
     * @return array<int, array<string, mixed>>
     */
    private function summaryCards(array $summary): array
    {
        return [
            ['label' => __('shifts.summary.total'),    'value' => $summary['total'],    'key' => 'total'],
            ['label' => __('shifts.summary.open'),     'value' => $summary['open'],     'key' => 'open',     'tone' => 'accent'],
            ['label' => __('shifts.summary.variance'), 'value' => $summary['variance'], 'key' => 'variance', 'tone' => 'warning'],
        ];
    }

    /* ── Open ───────────────────────────────────────────────────── */

    public function openForm(Request $request): View|RedirectResponse
    {
        $this->authorize('create', Shift::class);

        $user    = $request->user();
        $storeId = active_store_id();
        if (! $storeId) {
            return redirect()
                ->route('admin.shifts.index')
                ->with('error', __('shifts.errors.no_store_selected'));
        }

        $existing = Shift::openForUser((int) $user->id);
        if ($existing) {
            if ((int) $existing->store_id === (int) $storeId) {
                return redirect()->route('admin.shifts.show', $existing);
            }

            return redirect()->route('admin.shifts.index')->with('error', __('shifts.errors.already_open', [
                'number' => '#'.$existing->id,
            ]));
        }

        $store = Store::query()->findOrFail($storeId);

        // Store-scoped terminal picker. A terminal is required to open a shift
        // when the store has any; the picker defaults to the currently-bound
        // terminal (pos_terminal_id cookie).
        $allTerminals = \App\Models\Terminal::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        $terminals = $allTerminals
            ->filter(fn ($terminal) => app(\App\Services\TerminalReservation::class)
                ->available((int) $terminal->id, $request))
            ->values();

        return view('admin.shifts.open', [
            'store'             => $store,
            'terminals'         => $terminals,
            'hasActiveTerminals' => $allTerminals->isNotEmpty(),
            'selectedTerminal'  => current_terminal_id(),
            'denominations'     => \App\Support\Denominations::forActiveCurrency(),
        ]);
    }

    public function store(OpenShiftRequest $request, OpenShift $open): JsonResponse|RedirectResponse
    {
        $user    = $request->user();
        $storeId = active_store_id();
        $store   = Store::query()->findOrFail($storeId);

        // Bind the shift to the terminal chosen on the open form (validated to
        // belong to this store + be active). Persist it as the browser's active
        // terminal so the cashier context matches this shift.
        $data       = $request->validated();
        $terminalId = $request->integer('terminal_id') ?: null;
        if ($terminalId) {
            app(\App\Services\TerminalReservation::class)->claim($terminalId, $request);
            cookie()->queue(cookie()->forever('pos_terminal_id', (string) $terminalId));
        }

        try {
            $shift = $open($data, $user, $store);
        } catch (ShiftAlreadyOpen $e) {
            return $this->jsonOrError(
                $request,
                $e->getMessage(),
                route('admin.shifts.show', $e->existing),
            );
        } catch (\App\Exceptions\TerminalShiftOpen $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect(
            $request,
            __('shifts.flash.opened', ['number' => '#'.$shift->id]),
            route('admin.shifts.show', $shift),
        );
    }

    /* ── Close ──────────────────────────────────────────────────── */

    public function closeForm(Request $request, Shift $shift): View|RedirectResponse
    {
        $this->authorize('close', $shift);

        if (! $shift->isOpen()) {
            return redirect()
                ->route('admin.shifts.show', $shift)
                ->with('error', __('shifts.errors.not_open'));
        }

        $totals = ($this->compute)($shift);

        return view('admin.shifts.close', [
            'shift'           => $shift,
            'totals'          => $totals,
            'varianceReasons' => ShiftVarianceReason::query()->active()->ordered()->get(['id', 'code', 'name']),
            'denominations'   => \App\Support\Denominations::forActiveCurrency(),
        ]);
    }

    public function close(CloseShiftRequest $request, Shift $shift, CloseShift $close): JsonResponse|RedirectResponse
    {
        try {
            $shift = $close($shift, $request->validated(), $request->user());
        } catch (VarianceReasonRequired $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect(
            $request,
            __('shifts.flash.closed', ['number' => '#'.$shift->id]),
            route('admin.shifts.show', $shift),
        );
    }

    /* ── X-report (live, printable) ─────────────────────────────── */

    public function xReport(Shift $shift): View
    {
        $this->authorize('view', $shift);

        $shift->load(['store:id,name,currency_code', 'cashier:id,name']);

        return view('admin.shifts.x-report', [
            'shift'  => $shift,
            'totals' => ($this->compute)($shift),
        ]);
    }

    /**
     * Dual-format Z-report print payload (HTML + ESC/POS) for the print
     * bridge — same contract as the sale receipt payload. The cashier /
     * admin "Print Z-report" button fetches this and hands it to the
     * bridge (WebUSB → browser-print).
     */
    public function zReportPayload(Shift $shift, PrepareZReportPayload $prepare): JsonResponse
    {
        $this->authorize('view', $shift);

        return response()->json(($prepare)($shift, current_terminal()));
    }

    /* ── Show ───────────────────────────────────────────────────── */

    public function show(Shift $shift): View
    {
        $this->authorize('view', $shift);

        $shift->load([
            'store:id,name,currency_code',
            'cashier:id,name',
            'terminal:id,name,code',
            'forceCloser:id,name',
        ]);

        $totals = ($this->compute)($shift);

        $entries = $shift->cashDrawerEntries()
            ->with(['createdBy:id,name', 'expense:id,number'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return view('admin.shifts.show', [
            'shift'   => $shift,
            'totals'  => $totals,
            'entries' => $entries,
            // Feeds the pay-out form's "expense" category (§4.3) so cash taken
            // from the till can be booked to a real expense account on the spot.
            'expenseCategories' => ExpenseCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /* ── Cash drawer entries ────────────────────────────────────── */

    /**
     * How much cash the caller's own open drawer holds right now, for the
     * store passed in (defaults to the active one).
     *
     * The pay-out cap is enforced server-side in
     * {@see \App\Actions\Shifts\RecordCashDrawerEntry}, but a form that only
     * finds out on submit is a bad form — the cashier types 500, waits, and
     * gets told no. Every surface that can spend drawer cash (the shift
     * panel, the till's cash in/out modal, the supplier-payment form) reads
     * this so it can cap the input before anyone types past it.
     *
     * No permission gate beyond store access: `openForCashier()` scopes to the
     * acting user, so this can only ever return the caller's own drawer.
     */
    public function cashDrawerAvailable(Request $request, AvailableDrawerCash $available): JsonResponse
    {
        $storeId = (int) ($request->integer('store_id') ?: current_store_id());
        abort_unless_store_access($storeId);

        $shift = Shift::openForCashier($storeId, (int) $request->user()->id);

        // No open shift = no drawer to spend from. `null` (rather than 0) so
        // the client can tell "nothing to cap" apart from "drawer is empty".
        if (! $shift) {
            return response()->json([
                'shift_id'            => null,
                'available'           => null,
                'available_formatted' => null,
            ]);
        }

        $amount = $available($shift);

        return response()->json([
            'shift_id'            => (int) $shift->id,
            'available'           => $amount,
            'available_formatted' => format_money($amount, null, $shift->store?->currency_code),
        ]);
    }

    public function recordCashDrawerEntry(
        RecordCashDrawerEntryRequest $request,
        Shift $shift,
        RecordCashDrawerEntry $record,
        RecordPayOutExpense $recordExpense,
    ): JsonResponse|RedirectResponse {
        // The request only checks the cash_drawer.* permission, so without
        // this any holder could pay in/out against another cashier's — or
        // another store's — shift and corrupt its expected cash and Z-report
        // (SEC #52).
        abort_unless_store_access($shift->store_id);

        $data = $request->validated();

        // A pay-out categorised as `expense` books a real expense as well as
        // moving the drawer (§4.3) — otherwise petty cash spent at the till
        // never reaches the Expenses list or the P&L.
        $asExpense = (string) $request->input('type') === CashDrawerEntry::TYPE_PAY_OUT
            && (string) $request->input('category') === CashDrawerEntry::CATEGORY_EXPENSE;

        try {
            $entry = $asExpense
                ? $recordExpense($shift, $data, $request->user())
                : $record($shift, $data, $request->user());
        } catch (ShiftNotOpen $e) {
            return $this->jsonOrError($request, $e->getMessage());
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->jsonOrError($request, $e->getMessage());
        }

        $msg = match ((string) $request->input('type')) {
            CashDrawerEntry::TYPE_PAY_IN              => __('cash_drawer.flash.pay_in_recorded'),
            CashDrawerEntry::TYPE_PAY_OUT             => $asExpense
                ? __('cash_drawer.flash.pay_out_expense_recorded', [
                    'number' => $entry->expense?->number ?? '',
                ])
                : __('cash_drawer.flash.pay_out_recorded'),
            CashDrawerEntry::TYPE_DRAWER_OPEN_NO_SALE => __('cash_drawer.flash.drawer_opened_no_sale'),
            default                                   => __('cash_drawer.flash.recorded'),
        };

        return $this->jsonOrRedirect($request, $msg, route('admin.shifts.show', $shift));
    }
}
