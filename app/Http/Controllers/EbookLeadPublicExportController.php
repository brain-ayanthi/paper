<?php

namespace App\Http\Controllers;

use App\Actions\Marketing\DownloadEbookLeads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EbookLeadPublicExportController extends Controller
{
    public function __invoke(string $token, DownloadEbookLeads $download): BinaryFileResponse|StreamedResponse
    {
        $expectedHash = strtolower(trim((string) config('pos.demo.ebook_leads_download_token_hash')));
        $providedHash = hash('sha256', strtolower($token));

        abort_unless(
            strlen($expectedHash) === 64 && hash_equals($expectedHash, $providedHash),
            404,
        );

        return $download->execute();
    }
}
