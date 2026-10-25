<?php

namespace App\Services\Commerce\Data;

use Carbon\CarbonImmutable;

final readonly class RemoteOrder
{
    /**
     * @param  list<RemoteOrderLine>  $lines
     * @param  array<string, mixed>  $billingAddress
     * @param  array<string, mixed>  $shippingAddress
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $remoteId,
        public ?string $number,
        public string $status,
        public ?string $financialStatus,
        public ?string $fulfillmentStatus,
        public string $currencyCode,
        public string $subtotal,
        public string $discountTotal,
        public string $taxTotal,
        public string $shippingTotal,
        public string $grandTotal,
        public string $refundedTotal,
        public ?string $remoteCustomerId,
        public ?string $paymentReference,
        public array $billingAddress,
        public array $shippingAddress,
        public array $customer,
        public array $lines,
        public ?CarbonImmutable $placedAt,
        public ?CarbonImmutable $paidAt,
        public ?CarbonImmutable $cancelledAt,
        public ?CarbonImmutable $remoteUpdatedAt,
        public array $payload,
        public bool $paid,
        public bool $cancelled,
    ) {}
}
