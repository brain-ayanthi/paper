<?php

namespace App\Actions\Purchases;

use App\Models\Purchase;
use App\Models\Store;

/**
 * Builds the next purchase number for a given store, formatted
 * `PUR-{STORE_CODE}-{YYYYMM}-{NNNN}` — e.g. `PUR-MAIN-202606-0012`.
 *
 * The trailing sequence resets each month per store. The `purchases.number`
 * column is globally unique, so collisions across stores/months are
 * impossible.
 *
 * Date arg lets tests pin a deterministic month. Production callers can
 * skip it and the current month is used.
 */
class GeneratePurchaseNumber
{
    public function __invoke(int $storeId, ?\DateTimeInterface $when = null): string
    {
        $store = Store::query()->findOrFail($storeId);
        $code  = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($store->code ?: 'STORE')));
        if ($code === '') {
            $code = 'STORE';
        }
        // `Carbon::now()` is the project's allowed time source — pass it in
        // for tests; default to "now" for live callers.
        $when ??= now();
        $ym = $when->format('Ym');

        $prefix = "PUR-{$code}-{$ym}-";

        $latest = Purchase::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        // Shared trailing-digits → +1 (code-review #32).
        $seq = \App\Support\NumberFormat::nextSeq($latest);

        return sprintf('%s%04d', $prefix, $seq);
    }
}
