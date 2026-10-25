<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Reports\ExportTopCustomers;
use App\Http\Controllers\Admin\Concerns\ResolvesReportFilters;
use App\Http\Controllers\Controller;
use App\Services\Reports\TopCustomersQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TopCustomersReportController extends Controller
{
    use ResolvesReportFilters;

    public function __construct(private TopCustomersQuery $query) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('reports.view_customers'), 403);

        [$from, $to, $storeId, $period] = $this->reportFilters($request);
        $rows = ($this->query)($from, $to, $storeId);

        return view('admin.reports.top-customers.index', [
            'rows'    => $rows,
            'summary' => $this->query->summarise($rows),
            'from'    => $from,
            'to'      => $to,
            'storeId' => $storeId,
            'period'  => $period,
            'stores'  => $this->reportStores(),
        ]);
    }

    public function export(Request $request, ExportTopCustomers $export): StreamedResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view_customers'), 403);
        $this->authorizeReportExport($request);

        [$from, $to, $storeId] = $this->reportFilters($request);
        $rows   = ($this->query)($from, $to, $storeId);
        $format = $this->reportFormat($request);

        return $export($rows, $from->toDateString(), $to->toDateString(), $format);
    }
}
