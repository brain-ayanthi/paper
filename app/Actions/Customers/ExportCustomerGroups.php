<?php

namespace App\Actions\Customers;

use App\Actions\Concerns\StreamsListExport;
use App\Events\CustomerGroupsExported;
use App\Models\CustomerGroup;
use App\Services\Excel\SpreadsheetWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Build a downloadable spreadsheet of all customer groups.
 *
 * Extension points:
 *   - filter `customer_groups.export.header`  → adjust the header row
 *   - filter `customer_groups.export.row`     → adjust each data row
 *   - action `customer_groups.before_export`  → side effects before stream
 *   - action `customer_groups.after_export`   → fires after rows are buffered
 *   - event  CustomerGroupsExported           → decoupled listeners
 */
class ExportCustomerGroups
{
    use StreamsListExport;

    public function __construct(private SpreadsheetWriter $writer) {}

    public function __invoke(string $format = 'csv'): StreamedResponse
    {
        $format = strtolower($format);

        $groups = CustomerGroup::query()->orderBy('name')->get();

        $header = apply_filters('customer_groups.export.header', [
            'ID',
            'Name',
            'Default Discount %',
            'Loyalty Multiplier',
            'Active',
            'Created at',
        ]);

        $rows = $groups->map(function (CustomerGroup $g) {
            $row = [
                (string) $g->id,
                (string) $g->name,
                (string) ($g->default_discount_percent ?? '0'),
                $g->earnMultiplier(),
                $g->is_active ? 'Yes' : 'No',
                optional($g->created_at)->toDateTimeString() ?? '',
            ];
            return apply_filters('customer_groups.export.row', $row, $g);
        })->all();

        $count = count($rows);

        return $this->streamListExport(
            $format, 'customer-groups', $header, $rows, $count,
            new CustomerGroupsExported($format, $count), 'customer_groups',
        );
    }
}
