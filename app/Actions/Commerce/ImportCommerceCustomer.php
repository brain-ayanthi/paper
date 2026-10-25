<?php

namespace App\Actions\Commerce;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\UpdateCustomer;
use App\Models\CommerceConnection;
use App\Models\CommerceEntityMapping;
use App\Models\Customer;
use App\Services\Commerce\Exceptions\CommerceSyncSkipped;
use Carbon\CarbonImmutable;

final readonly class ImportCommerceCustomer
{
    public function __construct(
        private CreateCustomer $create,
        private UpdateCustomer $update,
    ) {}

    /** @param array<string, mixed> $remote */
    public function execute(CommerceConnection $connection, array $remote): Customer
    {
        if (empty($remote['remote_id'])) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.customer_payload_invalid'));
        }

        $mapping = CommerceEntityMapping::query()
            ->where('commerce_connection_id', $connection->getKey())
            ->where('entity_type', CommerceEntityMapping::TYPE_CUSTOMER)
            ->where('remote_id', (string) $remote['remote_id'])
            ->first();
        $customer = $mapping?->local_type === Customer::class
            ? Customer::query()->find($mapping->local_id)
            : null;
        if (! $customer && (filled($remote['email'] ?? null) || filled($remote['phone'] ?? null))) {
            $customer = Customer::query()
                ->when(filled($remote['email'] ?? null), fn ($query) => $query->where('email', $remote['email']))
                ->when(! filled($remote['email'] ?? null), fn ($query) => $query->where('phone', $remote['phone']))
                ->first();
        }

        $name = trim((string) ($remote['first_name'] ?? '').' '.(string) ($remote['last_name'] ?? ''));
        if ($name === '') {
            $name = filled($remote['email'] ?? null) ? (string) $remote['email'] : __('commerce.orders.online_customer');
        }
        $data = [
            'name' => $name,
            'email' => $remote['email'] ?? null,
            'phone' => $remote['phone'] ?? null,
            'is_active' => true,
        ];
        $addresses = array_filter([(array) ($remote['address'] ?? [])], fn (array $address) => collect($address)->except('is_default')->filter(fn ($value) => filled($value))->isNotEmpty());
        $customer = $customer
            ? ($this->update)($customer, $data, $addresses)
            : ($this->create)($data, $addresses);

        $remoteHash = hash('sha256', json_encode($remote, JSON_THROW_ON_ERROR));
        CommerceEntityMapping::query()->updateOrCreate(
            [
                'commerce_connection_id' => $connection->getKey(),
                'entity_type' => CommerceEntityMapping::TYPE_CUSTOMER,
                'local_type' => Customer::class,
                'local_id' => $customer->getKey(),
            ],
            [
                'remote_id' => (string) $remote['remote_id'],
                'remote_hash' => $remoteHash,
                'remote_updated_at' => filled($remote['remote_updated_at'] ?? null)
                    ? CarbonImmutable::parse((string) $remote['remote_updated_at'])
                    : null,
                'last_synced_at' => now('UTC'),
            ],
        );

        return $customer;
    }
}
