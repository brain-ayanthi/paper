<?php

namespace App\Actions\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The scaffold every list-screen Export action repeats (code-review #38):
 * lowercase the format, build `{base}-{Y-m-d}.{ext}`, fire the
 * `{base}.before_export` / `{base}.after_export` hooks around the
 * SpreadsheetWriter stream, then dispatch the per-module Exported event.
 * Mirrors what StreamsReportExport already does for report exports.
 *
 * The action keeps what is genuinely its own: the query, the header
 * (through its `{base}.export.header` filter), and the row mapper
 * (through `{base}.export.row`, which needs the model instance).
 *
 * Host class must have a `SpreadsheetWriter $writer` property (all the
 * export actions already inject one).
 */
trait StreamsListExport
{
    /**
     * @param string          $baseName   filename base, e.g. 'categories'
     * @param array           $header     already-filtered header row
     * @param callable|array  $rows       row arrays, or a callback
     *                                    `fn (callable $write) => …` for
     *                                    streaming producers (see
     *                                    ExportProducts' lazy pages)
     * @param int             $count      row count for the hooks/event
     * @param object|null     $event      module event to dispatch after
     * @param string|null     $hookPrefix hook namespace when it differs
     *                                    from the filename base (several
     *                                    modules pair a kebab-case file
     *                                    with snake_case hooks, e.g.
     *                                    'stock-levels' / 'stock_levels');
     *                                    defaults to $baseName
     */
    private function streamListExport(
        string $format,
        string $baseName,
        array $header,
        callable|array $rows,
        int $count,
        ?object $event = null,
        ?string $hookPrefix = null,
    ): StreamedResponse {
        $format     = strtolower($format);
        $filename   = $baseName.'-'.now()->format('Y-m-d').".{$format}";
        $hookPrefix ??= $baseName;

        do_action("{$hookPrefix}.before_export", $format, $count);

        $producer = is_array($rows)
            ? static fn (callable $write) => $write($rows)
            : $rows;

        $response = $this->writer->stream($filename, $header, $producer);

        do_action("{$hookPrefix}.after_export", $format, $count);

        if ($event !== null) {
            event($event);
        }

        return $response;
    }
}
