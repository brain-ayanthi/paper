<?php

namespace App\Actions\Commerce;

use App\Models\CommerceConnection;
use App\Services\Commerce\CommerceProviderRegistry;
use App\Services\Commerce\Data\ConnectionTestResult;
use Throwable;

final readonly class TestCommerceConnection
{
    public function __construct(private CommerceProviderRegistry $providers) {}

    public function execute(CommerceConnection $connection): ConnectionTestResult
    {
        $provider = $this->providers->get($connection->provider);
        $result = $provider->testConnection(
            $connection->shop_url,
            $connection->credentials ?? [],
            $connection->api_version,
            $connection->settings ?? [],
        );

        $connection->last_tested_at = now('UTC');

        if (! $result->successful) {
            $connection->status = CommerceConnection::STATUS_ERROR;
            $connection->last_error = $result->message;
            $connection->save();

            return $result;
        }

        $settings = $connection->settings ?? [];
        $settings['remote_store_name'] = $result->remoteStoreName;
        $settings['remote_locations'] = array_map(
            fn ($location) => $location->toArray(),
            $result->locations,
        );
        $settings['provider_metadata'] = $result->metadata;

        $connection->fill([
            'external_store_id' => $result->remoteStoreId,
            'credentials' => array_replace($connection->credentials ?? [], $result->credentialUpdates),
            'settings' => $settings,
            'status' => CommerceConnection::STATUS_CONNECTED,
            'last_connected_at' => now('UTC'),
            'last_error' => null,
        ]);
        $connection->save();

        $callbackUrl = route('webhooks.commerce', ['provider' => $connection->provider, 'connection' => $connection]);
        try {
            if ($this->isPublicHttpsUrl($callbackUrl)) {
                $provider->registerWebhooks($connection, $callbackUrl);
            }
        } catch (Throwable $e) {
            $message = __('commerce.sync.errors.webhook_registration_failed', ['message' => $e->getMessage()]);
            $connection->forceFill([
                'status' => CommerceConnection::STATUS_ERROR,
                'last_error' => mb_substr($message, 0, 65535),
            ])->save();

            return ConnectionTestResult::failure($message);
        }

        // Keep display names current without disturbing the merchant's mapping.
        foreach ($result->locations as $location) {
            $connection->storeMappings()
                ->where('remote_location_id', $location->id)
                ->update(['remote_location_name' => $location->name]);
        }

        return $result;
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && $host !== ''
            && $host !== 'localhost'
            && ! str_ends_with($host, '.test')
            && filter_var($host, FILTER_VALIDATE_IP) === false;
    }
}
