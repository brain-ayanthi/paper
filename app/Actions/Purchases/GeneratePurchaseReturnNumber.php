<?php

namespace App\Actions\Purchases;

use App\Models\PurchaseReturn;
use App\Models\Store;

/**
 * Builds the next return number formatted `RTN-{STORE_CODE}-{YYYYMM}-{NNNN}`.
 * Mirrors {@see GeneratePurchaseNumber} — sequence resets each month per store.
 */
class GeneratePurchaseReturnNumber
{
    public function __invoke(int $storeId, ?\DateTimeInterface $when = null): string
    {
        $store = Store::query()->findOrFail($storeId);
        $code  = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($store->code ?: 'STORE')));
        if ($code === '') {
            $code = 'STORE';
        }
        $when ??= now();
        $ym     = $when->format('Ym');
        $prefix = "RTN-{$code}-{$ym}-";

        $latest = PurchaseReturn::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        // Shared trailing-digits → +1 (code-review #32).
        $seq = \App\Support\NumberFormat::nextSeq($latest);

        return sprintf('%s%04d', $prefix, $seq);
    }
}
