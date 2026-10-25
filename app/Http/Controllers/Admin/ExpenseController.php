<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Expenses\CreateExpense;
use App\Actions\Shifts\AvailableDrawerCash;
use App\Actions\Expenses\DeleteExpense;
use App\Actions\Expenses\ExportExpenses;
use App\Actions\Expenses\ReverseExpenseTillPayOut;
use App\Actions\Expenses\SyncExpenseTillPayOut;
use App\Actions\Expenses\UpdateExpense;
use App\Exceptions\InsufficientDrawerCash;
use App\Http\Controllers\Concerns\RendersDataTableRows;
use App\Http\Controllers\Concerns\ResolvesExportFormat;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExpenseRequest;
use App\Models\CashDrawerEntry;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expenses admin — CRUD for the drawer's cash-out side
 * (docs/features/cash-drawer-shifts.md §4.3). Scoped to the session
 * store (topbar switcher), like Sales / Purchases. Approval workflow,
 * recurring schedules, receipt-image upload, and journal posting are
 * deferred to later slices.
 */
class ExpenseController extends Controller
{
    use RendersDataTableRows;
    use ResolvesExportFormat;
    use RespondsJsonOrRedirect;

    /**
     * Expenses — server-paginated. Filters (category / date range / search) +
     * paging round-trip to {@see rows()} via the generic `expensesPage`
     * (aliased salesIndexPage) factory. Store-scoped to the active store.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Expense::class);

        $perPage   = $this->dtPerPage($request);
        $paginator = $this->listQuery($request)->paginate($perPage);
        $summary   = $this->summaryFor($request);

        return view('admin.expenses.index', [
            'expenses'     => $paginator->getCollection(),
            'total'        => $paginator->total(),
            'perPage'      => $perPage,
            'totalPages'   => max(1, $paginator->lastPage()),
            'summaryCards' => $this->summaryCards($summary),
            'categories'   => ExpenseCategory::query()->active()->ordered()->get(['id', 'name']),
            'filters'      => [
                'q'          => trim((string) $request->query('q', '')),
                'categoryId' => $request->integer('category_id') ?: null,
                'from'       => $request->query('from'),
                'to'         => $request->query('to'),
            ],
        ]);
    }

    /** One page of expense rows as an HTML fragment plus summary meta. */
    public function rows(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $paginator = $this->listQuery($request)
            ->paginate($this->dtPerPage($request), ['*'], 'page', $this->dtPage($request));

        return $this->dtRows($paginator, 'admin.expenses._rows', 'expenses', [
            'summary' => $this->summaryFor($request),
        ]);
    }

    /** @return Builder<Expense> */
    private function filteredBase(Request $request): Builder
    {
        $storeId    = active_store_id();
        $q          = trim((string) $request->query('q', ''));
        $categoryId = $request->integer('category_id') ?: null;
        $from       = $request->query('from');
        $to         = $request->query('to');

        return Expense::query()
            ->when($storeId, fn ($qb) => $qb->where('store_id', $storeId))
            ->when($categoryId, fn ($qb) => $qb->where('category_id', $categoryId))
            ->when($from, fn ($qb) => $qb->whereDate('expense_date', '>=', $from))
            ->when($to, fn ($qb) => $qb->whereDate('expense_date', '<=', $to))
            ->when($q !== '', function ($qb) use ($q) {
                $qb->where(function ($w) use ($q) {
                    $w->where('number', 'like', "%{$q}%")
                      ->orWhere('reference', 'like', "%{$q}%")
                      ->orWhere('description', 'like', "%{$q}%");
                });
            });
    }

    /** @return Builder<Expense> */
    private function listQuery(Request $request): Builder
    {
        return $this->filteredBase($request)
            ->with(['category:id,name', 'paymentMethod:id,name', 'supplier:id,name'])
            ->ordered();
    }

    /**
     * @return array{count:string, total:string}
     */
    private function summaryFor(Request $request): array
    {
        $agg = $this->filteredBase($request)
            ->selectRaw('COUNT(*) as cnt')
            // `total` is the amount + tax_amount accessor — mirror it in SQL.
            ->selectRaw('COALESCE(SUM(amount + tax_amount), 0) as total')
            ->first();

        return [
            'count' => number_format((int) ($agg->cnt ?? 0)),
            'total' => format_money($agg->total ?? 0),
        ];
    }

    /**
     * @param  array{count:string, total:string}  $summary
     * @return array<int, array<string, mixed>>
     */
    private function summaryCards(array $summary): array
    {
        return [
            ['label' => __('expenses.summary.count'), 'value' => $summary['count'], 'key' => 'count'],
            ['label' => __('expenses.summary.total'), 'value' => $summary['total'], 'key' => 'total', 'tone' => 'warning'],
        ];
    }

    public function create(): View
    {
        $this->authorize('create', Expense::class);

        $expense = new Expense(['expense_date' => now()->toDateString()]);

        return view('admin.expenses.edit', $this->editorPayload($expense));
    }

    /**
     * The till sync is called from the controller rather than from inside
     * {@see CreateExpense} on purpose: the opposite direction — a pay-out at
     * the till that CREATES an expense ({@see App\Actions\Shifts\RecordPayOutExpense})
     * — writes its own drawer entry, and burying the sync in the create Action
     * would give that flow two pay-outs for one payment.
     */
    public function store(
        ExpenseRequest $request,
        CreateExpense $create,
        SyncExpenseTillPayOut $syncTill,
    ): JsonResponse|RedirectResponse {
        $this->authorize('create', Expense::class);

        $storeId = active_store_id();

        // Both writes in one transaction so the drawer guard can veto the pair.
        // A cash expense larger than the open till would otherwise leave the
        // expense on the books with no matching pay-out — the exact drift the
        // till sync exists to prevent.
        try {
            [$expense, $payOut] = DB::transaction(function () use ($request, $create, $syncTill, $storeId) {
                $expense = $create($request->expenseData(), $request->user(), (int) $storeId);

                return [$expense, $syncTill($expense, $request->user())];
            });
        } catch (InsufficientDrawerCash $e) {
            // No redirect target — `back()->withInput()` keeps the user on the
            // form with what they typed, so they can lower the amount or
            // switch to a non-cash method.
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect(
            $request,
            $payOut
                ? __('expenses.flash.created_with_pay_out', [
                    'number' => $expense->number,
                    'amount' => format_money($payOut->amount),
                ])
                : __('expenses.flash.created', ['number' => $expense->number]),
            route('admin.expenses.index'),
        );
    }

    public function edit(Expense $expense): View
    {
        $this->authorize('update', $expense);

        return view('admin.expenses.edit', $this->editorPayload($expense));
    }

    public function update(
        ExpenseRequest $request,
        Expense $expense,
        UpdateExpense $update,
        SyncExpenseTillPayOut $syncTill,
    ): JsonResponse|RedirectResponse {
        $this->authorize('update', $expense);

        // Re-point the drawer at the new amount. No-ops once the shift that
        // paid it has closed — that Z-report is already reconciled. Editing an
        // amount UPWARDS spends more drawer cash, so it's capped like any other
        // pay-out; one transaction keeps the expense and the till in step.
        try {
            DB::transaction(function () use ($request, $expense, $update, $syncTill) {
                $fresh = $update($expense, $request->expenseData(), $request->user());
                $syncTill($fresh, $request->user());
            });
        } catch (InsufficientDrawerCash $e) {
            // No redirect target — `back()->withInput()` keeps the user on the
            // form with what they typed, so they can lower the amount or
            // switch to a non-cash method.
            return $this->jsonOrError($request, $e->getMessage());
        }

        return $this->jsonOrRedirect(
            $request,
            __('expenses.flash.updated', ['number' => $expense->number]),
            route('admin.expenses.index'),
        );
    }

    public function destroy(
        Request $request,
        Expense $expense,
        DeleteExpense $delete,
        ReverseExpenseTillPayOut $reverseTill,
    ): JsonResponse|RedirectResponse {
        $this->authorize('delete', $expense);

        $number = $expense->number;

        // Cash back into the till BEFORE the row goes, while the pay-out link
        // is still resolvable — otherwise the shift keeps expecting less cash
        // than it holds and the cashier counts long at close.
        $reverseTill($expense, $request->user());

        $delete($expense);

        return $this->jsonOrRedirect(
            $request,
            __('expenses.flash.deleted', ['number' => $number]),
            route('admin.expenses.index'),
        );
    }

    public function export(Request $request, ExportExpenses $export): StreamedResponse
    {
        $this->authorize('export', Expense::class);

        $format = $this->exportFormat($request);

        return ($export)($format, active_store_id());
    }

    /** @return array<string, mixed> */
    private function editorPayload(Expense $expense): array
    {
        $storeId = (int) ($expense->store_id ?: (active_store_id()));

        $openShift = auth()->id()
            ? Shift::openForCashier($storeId, (int) auth()->id())
            : null;

        $drawerPayOut = $expense->exists ? $expense->drawerPayOut()->with('shift')->first() : null;

        return [
            'expense'        => $expense,
            'categories'     => ExpenseCategory::query()->active()->ordered()->get(['id', 'name']),
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'suppliers'      => Supplier::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name']),
            // Drives the "this comes out of your open till" notice. Same
            // condition SyncExpenseTillPayOut uses, so the form can't promise
            // a drawer movement that won't happen.
            'hasOpenShift'   => $openShift !== null,
            // Ids of cash-type methods so the notice appears only while a cash
            // method is picked — the JS reads this off the form.
            'cashMethodIds'  => PaymentMethod::query()
                ->where('is_active', true)
                ->where('type', 'cash')
                ->pluck('id')
                ->all(),
            'drawerPayOut'   => $drawerPayOut,
        ] + $this->tillCeiling($openShift, $drawerPayOut);
    }

    /**
     * The most this expense can be, if it's going to be paid from the open
     * till. That's the drawer's cash PLUS whatever this expense already took
     * out of it — `SyncExpenseTillPayOut` reconciles to a target rather than
     * stacking pay-outs, so editing 100 → 150 only spends another 50, and a
     * ceiling that ignored the original 100 would wrongly block the edit.
     *
     * Null when no shift is open: nothing to bound, and the save won't touch
     * a drawer at all.
     *
     * @return array{tillCeiling: ?string, tillCeilingFormatted: ?string}
     */
    private function tillCeiling(?Shift $openShift, ?CashDrawerEntry $drawerPayOut): array
    {
        if (! $openShift) {
            return ['tillCeiling' => null, 'tillCeilingFormatted' => null];
        }

        $ceiling = app(AvailableDrawerCash::class)($openShift);

        // Only credit back a pay-out that belongs to the shift still open —
        // one booked against a closed shift is frozen and can't be reconciled.
        if ($drawerPayOut && (int) $drawerPayOut->shift_id === (int) $openShift->id) {
            $ceiling = bcadd($ceiling, (string) $drawerPayOut->amount, 4);
        }

        return [
            'tillCeiling'          => $ceiling,
            'tillCeilingFormatted' => format_money($ceiling, null, $openShift->store?->currency_code),
        ];
    }
}
