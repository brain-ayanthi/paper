<?php

namespace App\Actions\Reports;

use App\Actions\Reports\Concerns\StreamsReportExport;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Stream the Loyalty Points report as CSV / XLSX / PDF. */
class ExportLoyaltyPoints
{
    use StreamsReportExport;

    /** Only "Balance value" is money; the point columns are counts. @return list<int> */
    public function moneyColumns(): array
    {
        return [8];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{0: list<string>, 1: list<array<int, string>>}
     */
    public function build(Collection $rows): array
    {
        $header = apply_filters('reports.loyalty_points.export.header', [
            'Customer', 'Code', 'Group', 'Earned', 'Redeemed', 'Expired', 'Net', 'Balance', 'Balance value',
        ]);

        $out = $rows->map(fn ($r) => apply_filters('reports.loyalty_points.export.row', [
            (string) $r['name'],
            (string) ($r['code'] ?? ''),
            (string) ($r['group_name'] ?? ''),
            (string) $r['earned'],
            (string) $r['redeemed'],
            (string) $r['expired'],
            (string) $r['net'],
            (string) $r['balance'],
            (string) $r['balance_value'],
        ], $r))->all();

        return [$header, $out];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    public function __invoke(Collection $rows, string $from, string $to, string $format = 'csv'): StreamedResponse
    {
        [$header, $out] = $this->build($rows);

        return $this->respondWithReportExport('loyalty-points', __('reports.loyalty_points.title'), $header, $out, $format, $from, $to);
    }
}
