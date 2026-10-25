<?php

namespace App\Services\Quotations;

use App\Models\Company;
use App\Models\Quotation;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuotationPdfRenderer
{
    public function stream(Quotation $quotation): StreamedResponse
    {
        $quotation->loadMissing(['store', 'items']);
        $company = Company::current() ?? new Company;
        $tmp = storage_path('app/mpdf');
        File::ensureDirectoryExists($tmp);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tmp,
            'default_font' => 'dejavusans',
            'margin_top' => 12,
            'margin_bottom' => 12,
            'margin_left' => 12,
            'margin_right' => 12,
            'directionality' => in_array(app()->getLocale(), ['ar', 'he', 'ur', 'fa'], true) ? 'rtl' : 'ltr',
        ]);
        $mpdf->WriteHTML(view('quotations.pdf', compact('quotation', 'company'))->render());
        $bytes = $mpdf->Output('', Destination::STRING_RETURN);
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $quotation->displayNumber()).'.pdf';

        return response()->streamDownload(
            fn () => print ($bytes),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
