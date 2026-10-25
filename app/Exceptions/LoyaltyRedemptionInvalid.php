<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \App\Actions\Sales\CompleteSale} when a points
 * redemption can't be honoured — program off, no customer attached,
 * balance too low, below the minimum threshold, or the cart's discount
 * doesn't actually carry the redeemed value.
 *
 * Each reason maps to a message key under `sales.errors.loyalty.<reason>`.
 */
class LoyaltyRedemptionInvalid extends RuntimeException
{
    /** @param array<string, mixed> $replace */
    public function __construct(
        public readonly string $reason,
        array $replace = [],
    ) {
        parent::__construct(__('sales.errors.loyalty.'.$reason, $replace));
    }
}
