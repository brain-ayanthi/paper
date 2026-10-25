<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Sync\ResolveSyncLog;
use App\Actions\Sync\RetrySyncLog;
use App\Exceptions\SyncLogNotRetryable;
use App\Http\Controllers\Concerns\RendersDataTableRows;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SyncLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sync log — owner-facing audit trail of every cashier-complete
 * round-trip. Written by `SaleController::complete()` on every POST
 * that carries a `local_uuid`. Surfaces:
 *
 *   - successes (most rows)
 *   - failures (server-side rejections — bad payload, etc.)
 *   - conflicts (insufficient-stock, expired-batch, etc.)
 *
 * Read is gated on `sync.log.view`; the two write actions —
 * {@see retry()} (replay the stored payload) and {@see resolve()}
 * (dismiss something that can never succeed) — need `sync.log.retry`.
 *
 * Why a server-side retry exists at all: the queued payload lives in the
 * ORIGINATING terminal's IndexedDB, so without this the only machine
 * that could re-send a stuck sale was the till it was rung on — useless
 * once that tablet is off, wiped, or in someone's bag. `sync_logs`
 * carries the full payload, so the back office can replay it from here.
 */
class SyncLogController extends Controller
{
    use RendersDataTableRows;
    use RespondsJsonOrRedirect;

    /**
     * Sync log — server-paginated. The result tabs are page-nav links; the
     * date range + search self-manage via the generic `syncLogPage`
     * (aliased salesIndexPage) factory and paging round-trips to {@see rows()}.
     */
    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('sync.log.view'), 403);

        $perPage   = $this->dtPerPage($request);
        $paginator = $this->listQuery($request)->paginate($perPage);
        $summary   = $this->summaryFor($request);

        // Tab badges stay GLOBAL (unfiltered) — they show the overall breakdown.
        // Failed / Conflict count UNRESOLVED rows only: once an owner has
        // retried or dismissed an entry it stops nagging, so the badge reads
        // as "still needs attention" rather than "happened at some point".
        $counts = [
            'all'      => SyncLog::query()->count(),
            'success'  => SyncLog::query()->success()->count(),
            'failed'   => SyncLog::query()->failed()->unresolved()->count(),
            'conflict' => SyncLog::query()->conflict()->unresolved()->count(),
        ];

        return view('admin.sync-log.index', [
            'logs'         => $paginator->getCollection(),
            'total'        => $paginator->total(),
            'perPage'      => $perPage,
            'totalPages'   => max(1, $paginator->lastPage()),
            'summaryCards' => $this->summaryCards($summary),
            'counts'       => $counts,
            'filters'      => [
                'result' => (string) $request->query('result', ''),
                'from'   => $request->query('from'),
                'to'     => $request->query('to'),
                'q'      => trim((string) $request->query('q', '')),
            ],
        ]);
    }

    /** One page of sync-log rows as an HTML fragment plus summary meta. */
    public function rows(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('sync.log.view'), 403);

        $paginator = $this->listQuery($request)
            ->paginate($this->dtPerPage($request), ['*'], 'page', $this->dtPage($request));

        return $this->dtRows($paginator, 'admin.sync-log._rows', 'logs', [
            'summary' => $this->summaryFor($request),
        ]);
    }

    /**
     * Active filter set (result tab + date range + search).
     *
     * @return Builder<SyncLog>
     */
    private function filteredBase(Request $request): Builder
    {
        $result = (string) $request->query('result', '');
        $from   = $request->query('from');
        $to     = $request->query('to');
        $q      = trim((string) $request->query('q', ''));

        return SyncLog::query()
            ->when(\in_array($result, ['success', 'failed', 'conflict'], true),
                fn ($qb) => $qb->where('result', $result))
            // The Failed / Conflict tabs are a worklist, so they hide what
            // has already been retried or dismissed — matching their badge
            // counts. Dismissed rows are never deleted; they stay visible
            // (with a chip) on the All tab, where they can be un-dismissed.
            ->when(\in_array($result, ['failed', 'conflict'], true),
                fn ($qb) => $qb->unresolved())
            ->when($from, fn ($qb) => $qb->whereDate('synced_at', '>=', $from))
            ->when($to,   fn ($qb) => $qb->whereDate('synced_at', '<=', $to))
            ->when($q !== '', fn ($qb) => $qb->where(function ($w) use ($q) {
                $w->where('local_uuid', 'like', "%{$q}%")
                  ->orWhere('result_message', 'like', "%{$q}%");
            }));
    }

    /** @return Builder<SyncLog> */
    private function listQuery(Request $request): Builder
    {
        return $this->filteredBase($request)
            ->with(['user:id,name'])
            ->orderByDesc('synced_at')
            ->orderByDesc('id');
    }

    /**
     * Summary-card totals over the filtered set, as display-ready strings.
     *
     * @return array{total:string, success:string, conflict:string, failed:string}
     */
    private function summaryFor(Request $request): array
    {
        $row = $this->filteredBase($request)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN result = 'success' THEN 1 ELSE 0 END), 0) as success")
            ->selectRaw("COALESCE(SUM(CASE WHEN result = 'conflict' THEN 1 ELSE 0 END), 0) as conflict")
            ->selectRaw("COALESCE(SUM(CASE WHEN result = 'failed' THEN 1 ELSE 0 END), 0) as failed")
            ->first();

        return [
            'total'    => number_format((int) ($row->total ?? 0)),
            'success'  => number_format((int) ($row->success ?? 0)),
            'conflict' => number_format((int) ($row->conflict ?? 0)),
            'failed'   => number_format((int) ($row->failed ?? 0)),
        ];
    }

    /**
     * @param  array{total:string, success:string, conflict:string, failed:string}  $summary
     * @return array<int, array<string, mixed>>
     */
    private function summaryCards(array $summary): array
    {
        return [
            ['label' => __('sync_log.summary.total'),    'value' => $summary['total'],    'key' => 'total'],
            ['label' => __('sync_log.summary.success'),  'value' => $summary['success'],  'key' => 'success', 'tone' => 'positive'],
            ['label' => __('sync_log.summary.conflict'), 'value' => $summary['conflict'], 'key' => 'conflict', 'tone' => 'warning'],
            ['label' => __('sync_log.summary.failed'),   'value' => $summary['failed'],   'key' => 'failed', 'tone' => 'danger'],
        ];
    }

    public function show(Request $request, SyncLog $syncLog): View
    {
        abort_unless($request->user()?->hasPermission('sync.log.view'), 403);

        // If this log corresponds to a successful sale we can link to
        // it; pull the row so the show page can render a small KPI strip.
        $sale = $syncLog->synced_entity_id
            ? Sale::query()->find($syncLog->synced_entity_id)
            : null;

        return view('admin.sync-log.show', [
            'log'  => $syncLog->load(['user:id,name', 'resolver:id,name']),
            'sale' => $sale,
        ]);
    }

    /**
     * Replay a failed / conflicted entry against the server.
     *
     * Idempotent — `CompleteSale` dedupes on `sales.local_uuid`, so a
     * double-click (or a retry of something the terminal has since
     * drained on its own) adopts the existing sale rather than posting a
     * second one. The outcome always lands back on the SAME log row.
     *
     * A retry that fails again is NOT an error condition for this
     * endpoint — the payload was replayed, the server said no. We answer
     * 422 with the server's own message so the toast tells the owner
     * exactly what to fix.
     */
    public function retry(Request $request, SyncLog $syncLog, RetrySyncLog $retry): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('sync.log.retry'), 403);

        try {
            $log = $retry($syncLog, $request->user());
        } catch (SyncLogNotRetryable $e) {
            return $this->jsonOrError($request, $e->getMessage(), route('admin.sync-log.show', $syncLog));
        }

        if (! $log->isSuccess()) {
            return $this->jsonOrError(
                $request,
                __('sync_log.flash.retry_failed', ['message' => $log->result_message ?: '—']),
                route('admin.sync-log.show', $log),
            );
        }

        return $this->jsonOrRedirect(
            $request,
            __('sync_log.flash.retry_success'),
            route('admin.sync-log.show', $log),
        );
    }

    /**
     * Dismiss (or un-dismiss with `?undo=1`) an entry that can never
     * succeed — stock genuinely wasn't there, customer walked, the sale
     * was re-rung by hand. Keeps the row + payload for audit; only stops
     * it counting against the Failed / Conflict badges.
     */
    public function resolve(Request $request, SyncLog $syncLog, ResolveSyncLog $resolve): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('sync.log.retry'), 403);

        $undo = $request->boolean('undo');

        try {
            $resolve($syncLog, $request->user(), $undo);
        } catch (SyncLogNotRetryable $e) {
            return $this->jsonOrError($request, $e->getMessage(), route('admin.sync-log.show', $syncLog));
        }

        return $this->jsonOrRedirect(
            $request,
            $undo ? __('sync_log.flash.undismissed') : __('sync_log.flash.dismissed'),
            route('admin.sync-log.show', $syncLog),
        );
    }
}
