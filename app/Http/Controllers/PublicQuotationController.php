<?php

namespace App\Http\Controllers;

use App\Actions\Quotations\ChangeQuotationStatus;
use App\Models\Company;
use App\Models\Quotation;
use App\Models\ReceiptPublicLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class PublicQuotationController extends Controller
{
    public function show(string $token): View
    {
        [$link, $quotation] = $this->resolve($token);

        DB::transaction(function () use ($link, $quotation) {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            $first = $locked->first_viewed_at === null;
            $locked->forceFill([
                'first_viewed_at' => $locked->first_viewed_at ?? now(),
                'last_viewed_at' => now(),
                'view_count' => $locked->view_count + 1,
            ])->save();
            if ($first) {
                $locked->activities()->create(['type' => 'viewed']);
            }
            ReceiptPublicLink::query()->lockForUpdate()->findOrFail($link->id)->markViewed();
        });

        $quotation->refresh()->load(['store', 'items']);

        return view('quotations.document', [
            'quotation' => $quotation,
            'company' => Company::current() ?? new Company,
            'public' => true,
            'token' => $token,
        ]);
    }

    public function accept(string $token, ChangeQuotationStatus $status): RedirectResponse
    {
        [, $quotation] = $this->resolve($token);
        try {
            $status->accept($quotation);
        } catch (RuntimeException $e) {
            return redirect()->route('quotation.public', $token)->with('error', $e->getMessage());
        }

        return redirect()->route('quotation.public', $token)->with('success', __('quotations.public.accepted'));
    }

    public function decline(string $token, ChangeQuotationStatus $status): RedirectResponse
    {
        [, $quotation] = $this->resolve($token);
        try {
            $status->decline($quotation);
        } catch (RuntimeException $e) {
            return redirect()->route('quotation.public', $token)->with('error', $e->getMessage());
        }

        return redirect()->route('quotation.public', $token)->with('success', __('quotations.public.declined'));
    }

    /** @return array{0: ReceiptPublicLink, 1: Quotation} */
    private function resolve(string $token): array
    {
        $link = ReceiptPublicLink::query()->where('token', $token)->first();
        abort_if($link === null || $link->isExpired(), 404);
        abort_unless($link->reference_type === (new Quotation)->getMorphClass(), 404);

        $quotation = $link->reference()->first();
        abort_unless($quotation instanceof Quotation && $quotation->status !== Quotation::STATUS_CANCELLED, 404);

        return [$link, $quotation];
    }
}
