<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Reports\ExportLoyaltyPoints;
use App\Http\Controllers\Admin\Concerns\ResolvesReportFilters;
use App\Http\Controllers\Controller;
use App\Services\Reports\LoyaltyPointsQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Loyalty Points report — who earned, who spent, and how big the
 * outstanding points liability is. Shares the customer-reports
 * permission: it answers a question about customers, not about money
 * the business holds.
 */
class LoyaltyPointsReportController extends Controller
{
    use ResolvesReportFilters;

    public function __construct(private LoyaltyPointsQuery $query) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('reports.view_customers'), 403);

        [$from, $to, $storeId, $period] = $this->reportFilters($request);
        $rows = ($this->query)($from, $to, $storeId);

        return view('admin.reports.loyalty-points.index', [
            'rows'    => $rows,
            'summary' => $this->query->summarise($rows),
            'from'    => $from,
            'to'      => $to,
            'storeId' => $storeId,
            'period'  => $period,
            'stores'  => $this->reportStores(),
        ]);
    }

    public function export(Request $request, ExportLoyaltyPoints $export): StreamedResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view_customers'), 403);
        $this->authorizeReportExport($request);

        [$from, $to, $storeId] = $this->reportFilters($request);
        $rows   = ($this->query)($from, $to, $storeId);
        $format = $this->reportFormat($request);

        return $export($rows, $from->toDateString(), $to->toDateString(), $format);
    }
}
