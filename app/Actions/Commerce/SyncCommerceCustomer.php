<?php

namespace App\Actions\Commerce;

use App\Models\CommerceConnection;
use App\Models\CommerceEntityMapping;
use App\Models\Customer;
use App\Services\Commerce\CommerceProviderRegistry;
use App\Services\Commerce\Exceptions\CommerceSyncSkipped;

final readonly class SyncCommerceCustomer
{
    public function __construct(
        private CommerceProviderRegistry $providers,
        private PersistCommerceSyncResult $persist,
    ) {}

    public function execute(CommerceConnection $connection, int $customerId): void
    {
        $customer = Customer::query()->withTrashed()->with('addresses')->find($customerId);
        if (! $customer || $customer->trashed() || ! $customer->is_active) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.customer_inactive'));
        }

        $mapping = CommerceEntityMapping::query()
            ->where('commerce_connection_id', $connection->getKey())
            ->where('entity_type', CommerceEntityMapping::TYPE_CUSTOMER)
            ->where('local_type', Customer::class)
            ->where('local_id', $customer->getKey())
            ->first();
        $name = preg_split('/\s+/', trim((string) $customer->name), 2) ?: [];
        $address = $customer->addresses->firstWhere('is_default', true) ?? $customer->addresses->first();
        $payload = [
            'local_type' => Customer::class,
            'local_id' => $customer->getKey(),
            'email' => $customer->email,
            'phone' => $customer->phone,
            'first_name' => $name[0] ?? $customer->name,
            'last_name' => $name[1] ?? '',
            'notes' => $customer->notes,
            'address' => $address ? [
                'line1' => $address->line1,
                'line2' => $address->line2,
                'city' => $address->city,
                'state' => $address->state,
                'postal_code' => $address->postal_code,
                'country_code' => $address->country_code,
                'phone' => $customer->phone,
            ] : [],
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if ($mapping?->local_hash === $hash) {
            return;
        }

        $result = $this->providers->get($connection->provider)->upsertCustomer(
            $connection,
            $payload,
            $mapping?->remote_id,
        );
        $this->persist->execute($connection, $result, $hash);
    }
}
