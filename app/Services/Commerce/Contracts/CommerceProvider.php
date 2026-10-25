<?php

namespace App\Services\Commerce\Contracts;

use App\Models\CommerceConnection;
use App\Services\Commerce\Data\ConnectionTestResult;
use App\Services\Commerce\Data\ProviderSyncResult;
use App\Services\Commerce\Data\RemoteOrder;

interface CommerceProvider
{
    public function code(): string;

    public function label(): string;

    public function normalizeShopUrl(string $shopUrl): string;

    /** @return list<string> */
    public function secretFields(): array;

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $settings
     */
    public function testConnection(
        string $shopUrl,
        array $credentials,
        ?string $apiVersion = null,
        array $settings = [],
    ): ConnectionTestResult;

    /**
     * @param  array<string, mixed>  $product
     */
    public function upsertProduct(CommerceConnection $connection, array $product, ?string $remoteId): ProviderSyncResult;

    /**
     * Archive a mapped product without permanently deleting channel history.
     */
    public function archiveProduct(CommerceConnection $connection, string $remoteId): void;

    /**
     * @param  array<string, mixed>  $customer
     */
    public function upsertCustomer(CommerceConnection $connection, array $customer, ?string $remoteId): ProviderSyncResult;

    /**
     * @param  list<array{remote_id:string,remote_location_id:?string,quantity:int,metadata?:array<string,mixed>}>  $levels
     */
    public function setInventory(CommerceConnection $connection, array $levels): void;

    /**
     * Mark one POS-store allocation as fulfilled on the remote channel.
     *
     * @param  array{
     *     remote_order_id:string,
     *     remote_location_id:?string,
     *     all_fulfilled:bool,
     *     lines:list<array{remote_line_id:string,quantity:int}>
     * }  $fulfillment
     * @return string|null The provider fulfillment identifier, when one exists.
     */
    public function fulfillOrder(CommerceConnection $connection, array $fulfillment): ?string;

    /**
     * Enrich a webhook before normalization when the provider omits details
     * needed for safe local processing (for example WooCommerce refund lines).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function prepareOrderWebhookPayload(CommerceConnection $connection, string $topic, array $payload): array;

    /** @param array<string, mixed> $payload */
    public function normalizeOrderWebhook(string $topic, array $payload): ?RemoteOrder;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function normalizeCustomerWebhook(string $topic, array $payload): ?array;

    public function registerWebhooks(CommerceConnection $connection, string $callbackUrl): void;
}
