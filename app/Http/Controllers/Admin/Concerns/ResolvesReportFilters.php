<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Shared date-range + store resolution for the report screens. Backs the
 * universal toolbar's period selector (docs/features/reports.md §3.1): a
 * `period` preset ("this_month", "last_7_days", …) is resolved server-side
 * (authoritative for the store's timezone), falling back to explicit
 * from/to dates when the preset is "custom".
 *
 * "This"-periods run period-start → today (month-to-date, week-to-date,
 * year-to-date) — the most useful framing for a shop owner — while past
 * periods (yesterday, last month, …) use their full range.
 */
trait ResolvesReportFilters
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: ?int, 3: string} */
    protected function reportFilters(Request $request): array
    {
        $storeId = $this->reportStoreId($request);
        $preset  = (string) $request->query('period', '');

        if ($preset === '') {
            // No preset given: custom when explicit dates are present,
            // otherwise fall back to the default "this month" view.
            $preset = ($request->filled('from') || $request->filled('to')) ? 'custom' : 'this_month';
        }

        $range = $this->resolvePeriod($preset);
        if ($range !== null) {
            return [$range[0], $range[1], $storeId, $preset];
        }

        // Custom / unknown preset → explicit from/to (clamped).
        $from = $this->parseReportDate($request->query('from'), 'start');
        $to   = $this->parseReportDate($request->query('to'), 'end');
        if ($from->isAfter($to)) {
            $from = $to->startOfMonth();
        }

        return [$from, $to, $storeId, 'custom'];
    }

    /**
     * The store a report actually runs against (SEC #26).
     *
     * `reports.cross_store` is seeded and documented but nothing consulted
     * it, so the `?store_id=` filter was honoured for anyone who could open
     * the report at all: a manager assigned to three branches could pull any
     * of the other two's numbers, and the permission meant nothing.
     *
     * Without it, the report is pinned to the store the user is working in
     * and a hand-crafted `store_id` is ignored rather than obeyed. With it,
     * {@see enforce_store_access()} takes over — an accessible pick is
     * honoured, anything else falls back to the active store.
     *
     * Super admins are unchanged: they keep the null → "every store"
     * aggregate they are entitled to.
     */
    protected function reportStoreId(Request $request): ?int
    {
        $user = $request->user();

        if ($user && ! $user->is_super_admin && ! $user->hasPermission('reports.cross_store')) {
            return current_store_id() ?: ($user->accessibleStoreIds()[0] ?? null);
        }

        return enforce_store_access($request->integer('store_id') ?: null);
    }

    /**
     * The stores offered in the report toolbar's picker. Mirrors
     * {@see reportStoreId()} — offering a branch the filter will silently
     * ignore is worse than not offering it (SEC #26 / #27).
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Store>
     */
    protected function reportStores(): \Illuminate\Support\Collection
    {
        $user = auth()->user();

        if ($user && ! $user->is_super_admin && ! $user->hasPermission('reports.cross_store')) {
            $pinned = current_store_id() ?: ($user->accessibleStoreIds()[0] ?? null);

            return accessible_stores()->where('id', $pinned)->values();
        }

        return accessible_stores();
    }

    /**
     * Gate a report export on `reports.export` (SEC #26). The permission is
     * seeded and shown in the role builder but no export route checked it,
     * so "may view this report on screen" and "may download the whole
     * dataset as a file" were the same thing. The caller still has to pass
     * the report's own view permission first.
     */
    protected function authorizeReportExport(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('reports.export'), 403);
    }

    /**
     * Map a preset key to a [from, to] range, or null for custom/unknown.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    protected function resolvePeriod(string $preset): ?array
    {
        return ReportPeriod::resolve($preset);
    }

    protected function parseReportDate(?string $raw, string $fallback): CarbonImmutable
    {
        try {
            if ($raw) {
                return CarbonImmutable::parse($raw)->startOfDay();
            }
        } catch (\Throwable) {}

        return $fallback === 'start'
            ? CarbonImmutable::now()->startOfMonth()->startOfDay()
            : CarbonImmutable::today()->startOfDay();
    }

    /** Normalise the requested export format to one we support. */
    protected function reportFormat(Request $request): string
    {
        $format = strtolower((string) $request->query('format'));

        return in_array($format, ['csv', 'xlsx', 'pdf'], true) ? $format : 'csv';
    }

    /** The preset keys offered in the toolbar, in display order. */
    public static function reportPeriodPresets(): array
    {
        return ReportPeriod::presets();
    }
}
