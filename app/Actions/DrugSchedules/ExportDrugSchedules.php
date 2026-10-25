<?php

namespace App\Actions\DrugSchedules;

use App\Actions\Concerns\StreamsListExport;
use App\Events\DrugSchedulesExported;
use App\Models\DrugSchedule;
use App\Services\Excel\SpreadsheetWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Build a downloadable spreadsheet of all drug schedules.
 *
 * Extension points:
 *   - filter `drug_schedules.export.header`  → adjust the header row
 *   - filter `drug_schedules.export.row`     → adjust each data row
 *   - action `drug_schedules.before_export`  → side effects before stream
 *   - action `drug_schedules.after_export`   → fires after rows are buffered
 *   - event  DrugSchedulesExported           → decoupled listeners
 */
class ExportDrugSchedules
{
    use StreamsListExport;

    public function __construct(private SpreadsheetWriter $writer) {}

    public function __invoke(string $format = 'csv'): StreamedResponse
    {
        $format = strtolower($format);

        $schedules = DrugSchedule::query()->orderBy('sort_order')->orderBy('name')->get();

        $header = apply_filters('drug_schedules.export.header', [
            'ID',
            'Code',
            'Name',
            'Description',
            'Country',
            'Active',
            'Created at',
        ]);

        $rows = $schedules->map(function (DrugSchedule $d) {
            $row = [
                (string) $d->id,
                (string) ($d->code ?? ''),
                (string) $d->name,
                (string) ($d->description ?? ''),
                (string) ($d->country_code ?? ''),
                $d->is_active ? 'Yes' : 'No',
                optional($d->created_at)->toDateTimeString() ?? '',
            ];
            return apply_filters('drug_schedules.export.row', $row, $d);
        })->all();

        $count = count($rows);

        return $this->streamListExport(
            $format, 'drug-schedules', $header, $rows, $count,
            new DrugSchedulesExported($format, $count), 'drug_schedules',
        );
    }
}
