<?php

namespace App\Services\Commerce;

use InvalidArgumentException;

/** Splits a 4dp amount across quantities while assigning rounding residue to the last slice. */
final class AllocateAmountAcrossQuantities
{
    /**
     * @param  list<string>  $quantities
     * @return list<string>
     */
    public function execute(string $amount, string $totalQuantity, array $quantities): array
    {
        if ($quantities === [] || bccomp($totalQuantity, '0', 4) <= 0) {
            throw new InvalidArgumentException('A positive total quantity and at least one slice are required.');
        }

        $remaining = bcadd($amount, '0', 4);
        $lastIndex = count($quantities) - 1;
        $allocated = [];

        foreach ($quantities as $index => $quantity) {
            if (bccomp($quantity, '0', 4) <= 0) {
                throw new InvalidArgumentException('Every slice quantity must be positive.');
            }

            $slice = $index === $lastIndex
                ? $remaining
                : bcmul($amount, bcdiv($quantity, $totalQuantity, 8), 4);
            $allocated[] = $slice;
            $remaining = bcsub($remaining, $slice, 4);
        }

        return $allocated;
    }
}
