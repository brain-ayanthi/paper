<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * The ONE `?format=csv|xlsx` guard for list exports and import templates
 * (code-review #35). Copy-pasted verbatim across ~24 admin controllers;
 * they all delegate here now, so the accepted set and the error response
 * live in one place.
 *
 * Deliberately aborts 422 on anything else — unlike the report screens'
 * `reportFormat()`, which silently falls back to csv (and also accepts
 * pdf). The list-export tests pin the 422, so that contract stays.
 */
trait ResolvesExportFormat
{
    protected function exportFormat(Request $request): string
    {
        $format = (string) $request->query('format', 'csv');

        if (! in_array($format, ['csv', 'xlsx'], true)) {
            abort(422, 'Unsupported export format. Use csv or xlsx.');
        }

        return $format;
    }
}
