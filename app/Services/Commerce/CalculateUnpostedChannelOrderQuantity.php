<?php

namespace App\Services\Commerce;

final class CalculateUnpostedChannelOrderQuantity
{
    /**
     * Calculate stock that still needs a local POS allocation.
     *
     * Remote fulfillment is intentionally not part of this calculation. A
     * provider status does not create a POS sale or post a stock movement.
     */
    public function execute(string $orderedQuantity, string $refundedQuantity): string
    {
        $quantity = bcsub($orderedQuantity, $refundedQuantity, 4);

        return bccomp($quantity, '0', 4) > 0 ? $quantity : '0.0000';
    }
}
