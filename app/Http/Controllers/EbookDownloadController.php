<?php

namespace App\Http\Controllers;

use App\Actions\Marketing\CaptureEbookLead;
use App\Http\Requests\Marketing\DownloadEbookRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class EbookDownloadController extends Controller
{
    public function __invoke(
        DownloadEbookRequest $request,
        CaptureEbookLead $captureLead,
    ): BinaryFileResponse|RedirectResponse|JsonResponse {
        abort_unless(pos_is_demo(), 404);

        $pdfPath = (string) config('pos.demo.ebook_pdf_path');
        if ($pdfPath === '' || ! is_readable($pdfPath)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('landing.ebook.errors.unavailable'),
                ], 503);
            }

            return back()
                ->withInput($request->safe()->only(['name', 'email', 'country']))
                ->withErrors(['ebook' => __('landing.ebook.errors.unavailable')])
                ->withFragment('free-ebook');
        }

        try {
            $captureLead->execute($request->safe()->only(['name', 'email', 'country']));
        } catch (Throwable $exception) {
            report($exception);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('landing.ebook.errors.submit'),
                ], 500);
            }

            return back()
                ->withInput($request->safe()->only(['name', 'email', 'country']))
                ->withErrors(['ebook' => __('landing.ebook.errors.submit')])
                ->withFragment('free-ebook');
        }

        return response()->download(
            $pdfPath,
            (string) config('pos.demo.ebook_download_name', 'Hyper-POS-eBook.pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }
}
