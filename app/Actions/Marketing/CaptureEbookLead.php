<?php

namespace App\Actions\Marketing;

use App\Support\Countries;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class CaptureEbookLead
{
    public function __construct(private readonly Filesystem $files) {}

    /**
     * Append one validated lead to the private CSV using an exclusive lock.
     *
     * @param  array{name:string,email:string,country:string}  $lead
     */
    public function execute(array $lead): void
    {
        $path = trim((string) config('pos.demo.ebook_leads_path'));
        if ($path === '') {
            throw new RuntimeException('The ebook lead file path is not configured.');
        }

        $this->files->ensureDirectoryExists(dirname($path));

        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('The ebook lead file could not be opened.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('The ebook lead file could not be locked.');
            }

            $stats = fstat($handle);
            $isEmpty = $stats === false || ($stats['size'] ?? 0) === 0;
            fseek($handle, 0, SEEK_END);

            if ($isEmpty) {
                $this->writeRow($handle, [
                    __('landing.ebook.export.columns.requested_at'),
                    __('landing.ebook.export.columns.name'),
                    __('landing.ebook.export.columns.email'),
                    __('landing.ebook.export.columns.country_code'),
                    __('landing.ebook.export.columns.country'),
                ]);
            }

            $this->writeRow($handle, [
                now()->toIso8601String(),
                $this->spreadsheetSafe($lead['name']),
                $this->spreadsheetSafe($lead['email']),
                $lead['country'],
                Countries::name($lead['country'], 'en'),
            ]);

            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function writeRow($handle, array $row): void
    {
        if (fputcsv($handle, $row) === false) {
            throw new RuntimeException('The ebook lead could not be written.');
        }
    }

    /** Prevent a downloaded CSV from interpreting visitor input as a formula. */
    private function spreadsheetSafe(string $value): string
    {
        return preg_match('/^[=+@\-]/u', ltrim($value)) === 1 ? "'{$value}" : $value;
    }
}
