<?php

namespace App\Actions\Marketing;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadEbookLeads
{
    public function execute(): BinaryFileResponse|StreamedResponse
    {
        $path = trim((string) config('pos.demo.ebook_leads_path'));
        $columns = [
            __('landing.ebook.export.columns.requested_at'),
            __('landing.ebook.export.columns.name'),
            __('landing.ebook.export.columns.email'),
            __('landing.ebook.export.columns.country_code'),
            __('landing.ebook.export.columns.country'),
        ];

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $response = response()->streamDownload(
            static function () use ($columns, $path): void {
                $output = fopen('php://output', 'wb');
                if ($output === false) {
                    return;
                }

                fputcsv($output, $columns);

                if ($path !== '' && is_readable($path)) {
                    $input = fopen($path, 'rb');
                    if ($input !== false) {
                        fgetcsv($input); // Stored header; the export uses the current locale above.
                        while (($row = fgetcsv($input)) !== false) {
                            fputcsv($output, $row);
                        }
                        fclose($input);
                    }
                }

                fclose($output);
            },
            'ebook-leads.csv',
            $headers,
        );

        // BinaryFileResponse defaults downloads to a public cache policy;
        // override it after construction because this export contains PII.
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
