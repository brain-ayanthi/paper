<?php

namespace App\Actions\Quotations;

use App\Models\Company;
use App\Models\Quotation;
use App\Models\Store;
use App\Support\NumberFormat;

class GenerateQuotationNumber
{
    public function __invoke(Store $store, ?\DateTimeInterface $when = null): string
    {
        $when ??= now();
        // The generator runs inside SaveQuotation's transaction. Locking the
        // store row serializes two staff creating the next quotation for the
        // same branch, avoiding duplicate customer-facing numbers.
        Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
        $format = (Company::current() ?? new Company)->numberFormat('quotation');
        $prefix = NumberFormat::prefix($format, $store, $when);

        $highestSequence = Quotation::withTrashed()
            ->where('store_id', $store->id)
            ->where('number', 'like', $prefix.'%')
            ->pluck('number')
            ->reduce(
                fn (int $highest, string $number) => max($highest, NumberFormat::extractSeq($number)),
                0,
            );

        return NumberFormat::render($format, $store, $when, $highestSequence + 1);
    }
}
