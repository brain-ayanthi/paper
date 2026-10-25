<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Marketing\DownloadEbookLeads;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EbookLeadExportController extends Controller
{
    public function __invoke(DownloadEbookLeads $download): BinaryFileResponse|StreamedResponse
    {
        $this->authorize('export', Customer::class);

        return $download->execute();
    }
}
