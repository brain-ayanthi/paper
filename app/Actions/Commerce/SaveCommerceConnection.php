<?php

namespace App\Actions\Commerce;

use App\Models\CommerceConnection;
use App\Models\User;
use App\Services\Commerce\CommerceCredentialManager;
use App\Services\Commerce\CommerceProviderRegistry;
use Illuminate\Validation\ValidationException;

final readonly class SaveCommerceConnection
{
    public function __construct(
        private CommerceProviderRegistry $providers,
        private CommerceCredentialManager $credentials,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(?CommerceConnection $connection, array $data, User $actor): CommerceConnection
    {
        $provider = $this->providers->get((string) $data['provider']);
        $shopUrl = $provider->normalizeShopUrl((string) $data['shop_url']);

        $duplicate = CommerceConnection::query()
            ->where('provider', $provider->code())
            ->where('shop_url', $shopUrl)
            ->when($connection, fn ($query) => $query->where('id', '!=', $connection->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'shop_url' => __('commerce.validation.duplicate_connection'),
            ]);
        }

        $existingCredentials = $connection?->credentials ?? [];
        $credentials = $this->credentials->merge(
            $provider,
            $existingCredentials,
            (array) ($data['credentials'] ?? []),
        );
        if ($provider->code() === CommerceConnection::PROVIDER_WOOCOMMERCE && empty($credentials['webhook_secret'])) {
            $credentials['webhook_secret'] = bin2hex(random_bytes(32));
        }

        $connection ??= new CommerceConnection([
            'created_by' => $actor->getKey(),
            'status' => CommerceConnection::STATUS_DISCONNECTED,
        ]);

        $connection->fill([
            'provider' => $provider->code(),
            'name' => trim((string) $data['name']),
            'shop_url' => $shopUrl,
            'api_version' => $provider->code() === CommerceConnection::PROVIDER_SHOPIFY
                ? ($data['api_version'] ?? config('commerce.shopify.api_version'))
                : 'v3',
            'credentials' => $credentials,
            'settings' => array_replace($connection->settings ?? [], (array) ($data['settings'] ?? [])),
            'updated_by' => $actor->getKey(),
        ]);
        $connection->save();

        return $connection;
    }
}
