<?php

namespace App\Services\Commerce\Providers;

use App\Models\CommerceConnection;
use App\Models\CommerceEntityMapping;
use App\Services\Commerce\Contracts\CommerceProvider;
use App\Services\Commerce\Data\ConnectionTestResult;
use App\Services\Commerce\Data\ProviderSyncResult;
use App\Services\Commerce\Data\RemoteEntity;
use App\Services\Commerce\Data\RemoteLocation;
use App\Services\Commerce\Data\RemoteOrder;
use App\Services\Commerce\Data\RemoteOrderLine;
use App\Services\Commerce\Exceptions\CommerceRemoteEntityMissing;
use App\Services\Commerce\Exceptions\CommerceSyncException;
use App\Services\Commerce\Exceptions\CommerceSyncSkipped;
use App\Support\SafeRemoteUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class WooCommerceProvider implements CommerceProvider
{
    /** @var array<string, bool> */
    private array $brandEndpointSupport = [];

    /** @var array<string, string> */
    private array $categoryRemoteIds = [];

    /** @var array<string, string> */
    private array $brandRemoteIds = [];

    public function code(): string
    {
        return 'woocommerce';
    }

    public function label(): string
    {
        return __('commerce.providers.woocommerce');
    }

    public function normalizeShopUrl(string $shopUrl): string
    {
        $shopUrl = trim($shopUrl);
        if (! preg_match('~^https?://~i', $shopUrl)) {
            $shopUrl = 'https://'.$shopUrl;
        }

        $parts = parse_url($shopUrl);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(__('commerce.connection_test.woocommerce.invalid_url'));
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(__('commerce.connection_test.woocommerce.invalid_scheme'));
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = isset($parts['path']) ? '/'.trim($parts['path'], '/') : '';

        return rtrim($scheme.'://'.strtolower($parts['host']).$port.$path, '/');
    }

    public function secretFields(): array
    {
        return ['consumer_secret', 'webhook_secret'];
    }

    public function testConnection(
        string $shopUrl,
        array $credentials,
        ?string $apiVersion = null,
        array $settings = [],
    ): ConnectionTestResult {
        try {
            $url = $this->normalizeShopUrl($shopUrl);
        } catch (InvalidArgumentException $e) {
            return ConnectionTestResult::failure($e->getMessage());
        }

        if (SafeRemoteUrl::reject($url) !== null) {
            return ConnectionTestResult::failure(__('commerce.connection_test.woocommerce.public_url_required'));
        }

        $key = trim((string) ($credentials['consumer_key'] ?? ''));
        $secret = trim((string) ($credentials['consumer_secret'] ?? ''));
        if ($key === '' || $secret === '') {
            return ConnectionTestResult::failure(__('commerce.connection_test.woocommerce.credentials_required'));
        }

        try {
            $response = Http::withBasicAuth($key, $secret)
                ->acceptJson()
                ->connectTimeout((int) config('commerce.connect_timeout', 5))
                ->timeout((int) config('commerce.timeout', 20))
                ->get($url.config('commerce.woocommerce.api_path', '/wp-json/wc/v3').'/system_status');
        } catch (ConnectionException) {
            return ConnectionTestResult::failure(__('commerce.connection_test.woocommerce.unreachable'));
        } catch (\Throwable) {
            return ConnectionTestResult::failure(__('commerce.connection_test.woocommerce.failed'));
        }

        if (! $response->successful()) {
            return ConnectionTestResult::failure($this->httpFailure($response->status()));
        }

        $environment = (array) $response->json('environment', []);
        $name = (string) ($environment['site_title'] ?? parse_url($url, PHP_URL_HOST));
        $siteUrl = (string) ($environment['site_url'] ?? $url);

        return ConnectionTestResult::success(
            __('commerce.connection_test.woocommerce.connected'),
            $siteUrl,
            $name,
            [new RemoteLocation('woocommerce-default', $name ?: __('commerce.connection_test.woocommerce.default_location'))],
            metadata: ['woocommerce_version' => $environment['version'] ?? null],
        );
    }

    public function upsertProduct(CommerceConnection $connection, array $product, ?string $remoteId): ProviderSyncResult
    {
        $type = ($product['type'] ?? 'simple') === 'variant' ? 'variable' : 'simple';
        $taxonomyEntities = [];
        [$categoryIds, $categoryEntities] = $this->upsertCategories($connection, (array) data_get($product, 'category.path', []));
        array_push($taxonomyEntities, ...$categoryEntities);

        $brand = is_array($product['brand'] ?? null) ? $product['brand'] : null;
        [$brandId, $brandEntity, $brandsSupported] = $brand
            ? $this->upsertBrand($connection, $brand)
            : [null, null, (bool) data_get($product, 'remote_metadata.woocommerce_brands_supported', false)];
        if ($brandEntity) {
            $taxonomyEntities[] = $brandEntity;
        }

        $body = [
            'name' => (string) $product['name'],
            'type' => $type,
            'status' => ($product['active'] ?? true) ? 'publish' : 'draft',
            'sku' => $type === 'simple' ? (string) ($product['sku'] ?? '') : '',
            'description' => (string) ($product['description'] ?? ''),
            'short_description' => (string) ($product['short_description'] ?? ''),
            'featured' => (bool) ($product['featured'] ?? false),
            'categories' => collect($categoryIds)->map(fn (string $id) => ['id' => (int) $id])->all(),
        ];

        if ($brandsSupported) {
            $body['brands'] = $brandId ? [['id' => (int) $brandId]] : [];
        }

        $image = is_array($product['image'] ?? null) ? $product['image'] : null;
        $remoteMetadata = (array) ($product['remote_metadata'] ?? []);
        $imageChanged = $image && (
            ($remoteMetadata['image_hash'] ?? null) !== ($image['hash'] ?? null)
            || empty($remoteMetadata['image_id'])
        );
        if ($imageChanged) {
            $body['images'] = [[
                'src' => (string) $image['url'],
                'name' => (string) $image['filename'],
                'alt' => (string) $image['alt'],
            ]];
        } elseif (! $image && (isset($remoteMetadata['image_hash']) || isset($remoteMetadata['image_id']))) {
            $body['images'] = [];
        }

        if ($type === 'simple') {
            $body['regular_price'] = (string) ($product['price'] ?? '0');
            $body['manage_stock'] = (bool) ($product['track_stock'] ?? true);
        } else {
            $body['attributes'] = collect($product['options'] ?? [])->map(fn (array $option, int $index) => [
                'name' => (string) $option['name'],
                'position' => $index,
                'visible' => true,
                'variation' => true,
                'options' => array_values($option['values'] ?? []),
            ])->all();
        }

        $brandFallback = $brand && ! $brandsSupported;
        if ($brandFallback) {
            $body['attributes'] ??= [];
            $body['attributes'][] = [
                'name' => __('commerce.sync.woocommerce.brand_attribute'),
                'position' => count($body['attributes']),
                'visible' => true,
                'variation' => false,
                'options' => [(string) $brand['name']],
            ];
        } elseif ($type === 'simple' && (bool) ($remoteMetadata['woocommerce_brand_fallback'] ?? false)) {
            $body['attributes'] = [];
        }

        $recreatedParent = false;
        if ($remoteId) {
            $response = $this->request($connection, 'PUT', '/products/'.$remoteId, $body, allowNotFound: true);
            if ($this->responseIndicatesMissingEntity($response)) {
                $createBody = $body;
                if ($image) {
                    $createBody['images'] = [[
                        'src' => (string) $image['url'],
                        'name' => (string) $image['filename'],
                        'alt' => (string) $image['alt'],
                    ]];
                }
                $response = $this->request($connection, 'POST', '/products', $createBody);
                $recreatedParent = true;
            }
        } else {
            $response = $this->request($connection, 'POST', '/products', $body);
        }

        $parentId = (string) $response->json('id');
        $parentMetadata = $remoteMetadata;
        unset($parentMetadata['image_id'], $parentMetadata['image_hash']);
        $parentMetadata['woocommerce_brands_supported'] = $brandsSupported;
        $parentMetadata['woocommerce_brand_fallback'] = $brandFallback;
        if ($image) {
            $remoteImageId = data_get($response->json(), 'images.0.id') ?: ($remoteMetadata['image_id'] ?? null);
            if (! $remoteImageId) {
                throw new CommerceSyncException(__('commerce.sync.errors.woocommerce_image_not_applied'), false);
            }
            $parentMetadata['image_id'] = (string) $remoteImageId;
            $parentMetadata['image_hash'] = (string) $image['hash'];
        }

        $entities = $taxonomyEntities;
        $entities[] = new RemoteEntity(
            CommerceEntityMapping::TYPE_PRODUCT,
            (string) $product['local_type'],
            (int) $product['local_id'],
            $parentId,
            metadata: $parentMetadata,
        );

        if ($type === 'simple') {
            $entities[] = new RemoteEntity(
                CommerceEntityMapping::TYPE_VARIANT,
                (string) $product['local_type'],
                (int) $product['local_id'],
                $parentId,
                $parentId,
                ['inventory_endpoint' => 'product'],
            );

            return new ProviderSyncResult($entities);
        }

        foreach ($product['variants'] ?? [] as $variant) {
            $variantBody = [
                'sku' => (string) ($variant['sku'] ?? ''),
                'regular_price' => (string) ($variant['price'] ?? '0'),
                'manage_stock' => (bool) ($product['track_stock'] ?? true),
                'status' => ($variant['active'] ?? true) ? 'publish' : 'private',
                'attributes' => collect($variant['option_values'] ?? [])->map(fn (array $option) => [
                    'name' => (string) $option['name'],
                    'option' => (string) $option['value'],
                ])->all(),
            ];
            if (! empty($variant['barcode'])) {
                $variantBody['global_unique_id'] = (string) $variant['barcode'];
            }

            $variantResponse = null;
            if (! $recreatedParent && ! empty($variant['remote_id'])) {
                $variantResponse = $this->request(
                    $connection,
                    'PUT',
                    "/products/{$parentId}/variations/{$variant['remote_id']}",
                    $variantBody,
                    allowNotFound: true,
                );
            }
            if (! $variantResponse || $this->responseIndicatesMissingEntity($variantResponse)) {
                $variantResponse = $this->request($connection, 'POST', "/products/{$parentId}/variations", $variantBody);
            }

            $entities[] = new RemoteEntity(
                CommerceEntityMapping::TYPE_VARIANT,
                (string) $variant['local_type'],
                (int) $variant['local_id'],
                (string) $variantResponse->json('id'),
                $parentId,
                ['inventory_endpoint' => 'variation'],
            );
        }

        return new ProviderSyncResult($entities);
    }

    /**
     * @param  list<array<string, mixed>>  $path
     * @return array{0:list<string>,1:list<RemoteEntity>}
     */
    private function upsertCategories(CommerceConnection $connection, array $path): array
    {
        $remoteParentId = 0;
        $remoteIds = [];
        $entities = [];

        foreach ($path as $category) {
            $cacheKey = (string) ($connection->getKey() ?: $connection->shop_url).'|'.$category['local_id'].'|'.$remoteParentId;
            if (isset($this->categoryRemoteIds[$cacheKey])) {
                $remoteId = $this->categoryRemoteIds[$cacheKey];
                $entities[] = new RemoteEntity(
                    CommerceEntityMapping::TYPE_CATEGORY,
                    (string) $category['local_type'],
                    (int) $category['local_id'],
                    $remoteId,
                    $remoteParentId > 0 ? (string) $remoteParentId : null,
                );
                $remoteParentId = (int) $remoteId;
                $remoteIds[] = $remoteId;

                continue;
            }

            $remoteParent = $remoteParentId > 0 ? (string) $remoteParentId : null;
            $body = [
                'name' => (string) $category['name'],
                'slug' => (string) $category['slug'],
                'description' => (string) ($category['description'] ?? ''),
                'parent' => $remoteParentId,
                'menu_order' => (int) ($category['sort_order'] ?? 0),
            ];
            $response = $this->upsertTerm(
                $connection,
                '/products/categories',
                $body,
                $category['remote_id'] ?? null,
            );
            $remoteId = (string) $response->json('id');
            if ($remoteId === '') {
                throw new CommerceSyncException(__('commerce.sync.errors.woocommerce_category_incomplete'), false);
            }

            $this->categoryRemoteIds[$cacheKey] = $remoteId;
            $remoteParentId = (int) $remoteId;
            $remoteIds[] = $remoteId;
            $entities[] = new RemoteEntity(
                CommerceEntityMapping::TYPE_CATEGORY,
                (string) $category['local_type'],
                (int) $category['local_id'],
                $remoteId,
                $remoteParent,
            );
        }

        return [$remoteIds, $entities];
    }

    /** @return array{0:?string,1:?RemoteEntity,2:bool} */
    private function upsertBrand(CommerceConnection $connection, array $brand): array
    {
        $supportKey = (string) ($connection->getKey() ?: $connection->shop_url);
        $brandCacheKey = $supportKey.'|'.$brand['local_id'];
        if (isset($this->brandRemoteIds[$brandCacheKey])) {
            $remoteId = $this->brandRemoteIds[$brandCacheKey];

            return [$remoteId, new RemoteEntity(
                CommerceEntityMapping::TYPE_BRAND,
                (string) $brand['local_type'],
                (int) $brand['local_id'],
                $remoteId,
            ), true];
        }
        if (($this->brandEndpointSupport[$supportKey] ?? null) === false) {
            return [null, null, false];
        }

        $body = [
            'name' => (string) $brand['name'],
            'slug' => (string) $brand['slug'],
            'description' => (string) ($brand['description'] ?? ''),
        ];
        $remoteId = filled($brand['remote_id'] ?? null) ? (string) $brand['remote_id'] : null;
        if ($remoteId) {
            $response = $this->request($connection, 'PUT', '/products/brands/'.$remoteId, $body, allowNotFound: true);
            if (! $this->responseIndicatesMissingEntity($response)) {
                $this->brandEndpointSupport[$supportKey] = true;
                $this->brandRemoteIds[$brandCacheKey] = $remoteId;

                return [$remoteId, new RemoteEntity(
                    CommerceEntityMapping::TYPE_BRAND,
                    (string) $brand['local_type'],
                    (int) $brand['local_id'],
                    $remoteId,
                ), true];
            }
        }

        $lookup = $this->request(
            $connection,
            'GET',
            '/products/brands?slug='.rawurlencode((string) $brand['slug']).'&hide_empty=false&per_page=100',
            allowNotFound: true,
        );
        if ($this->responseIndicatesMissingEntity($lookup)) {
            $this->brandEndpointSupport[$supportKey] = false;

            return [null, null, false];
        }

        $this->brandEndpointSupport[$supportKey] = true;
        $existing = collect($lookup->json())->first(fn ($row) => is_array($row) && ($row['slug'] ?? null) === $brand['slug']);
        $response = $existing
            ? $this->request($connection, 'PUT', '/products/brands/'.$existing['id'], $body)
            : $this->request($connection, 'POST', '/products/brands', $body);
        $remoteId = (string) $response->json('id');
        if ($remoteId === '') {
            throw new CommerceSyncException(__('commerce.sync.errors.woocommerce_brand_incomplete'), false);
        }
        $this->brandRemoteIds[$brandCacheKey] = $remoteId;

        return [$remoteId, new RemoteEntity(
            CommerceEntityMapping::TYPE_BRAND,
            (string) $brand['local_type'],
            (int) $brand['local_id'],
            $remoteId,
        ), true];
    }

    /** @param array<string, mixed> $body */
    private function upsertTerm(
        CommerceConnection $connection,
        string $endpoint,
        array $body,
        mixed $remoteId,
    ): Response {
        if (filled($remoteId)) {
            $response = $this->request($connection, 'PUT', $endpoint.'/'.$remoteId, $body, allowNotFound: true);
            if (! $this->responseIndicatesMissingEntity($response)) {
                return $response;
            }
        }

        $lookup = $this->request(
            $connection,
            'GET',
            $endpoint.'?slug='.rawurlencode((string) $body['slug']).'&hide_empty=false&per_page=100',
        );
        $existing = collect($lookup->json())->first(fn ($row) => is_array($row) && ($row['slug'] ?? null) === $body['slug']);

        return $existing
            ? $this->request($connection, 'PUT', $endpoint.'/'.$existing['id'], $body)
            : $this->request($connection, 'POST', $endpoint, $body);
    }

    public function archiveProduct(CommerceConnection $connection, string $remoteId): void
    {
        $this->request(
            $connection,
            'DELETE',
            '/products/'.$remoteId,
            ['force' => false],
            allowNotFound: true,
        );
    }

    public function upsertCustomer(CommerceConnection $connection, array $customer, ?string $remoteId): ProviderSyncResult
    {
        if (empty($customer['email'])) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.customer_email_required'));
        }

        $address = (array) ($customer['address'] ?? []);
        $billing = array_filter([
            'first_name' => $customer['first_name'] ?? '',
            'last_name' => $customer['last_name'] ?? '',
            'address_1' => $address['line1'] ?? null,
            'address_2' => $address['line2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'postcode' => $address['postal_code'] ?? null,
            'country' => filled($address['country_code'] ?? null) ? strtoupper((string) $address['country_code']) : null,
            'email' => $customer['email'] ?? null,
            'phone' => $customer['phone'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
        $body = array_filter([
            'email' => $customer['email'],
            'first_name' => $customer['first_name'] ?? '',
            'last_name' => $customer['last_name'] ?? '',
            'billing' => $billing === [] ? null : $billing,
            'meta_data' => [[
                'key' => '_pos_local_id',
                'value' => (string) $customer['local_id'],
            ]],
        ], fn ($value) => $value !== null);

        $response = $remoteId
            ? $this->request($connection, 'PUT', '/customers/'.$remoteId, $body)
            : $this->request($connection, 'POST', '/customers', $body);

        return new ProviderSyncResult([new RemoteEntity(
            CommerceEntityMapping::TYPE_CUSTOMER,
            (string) $customer['local_type'],
            (int) $customer['local_id'],
            (string) $response->json('id'),
        )]);
    }

    public function setInventory(CommerceConnection $connection, array $levels): void
    {
        foreach ($levels as $level) {
            $metadata = $level['metadata'] ?? [];
            $remoteId = $level['remote_id'];
            $path = ($metadata['inventory_endpoint'] ?? 'product') === 'variation'
                ? "/products/{$metadata['remote_parent_id']}/variations/{$remoteId}"
                : '/products/'.$remoteId;

            $quantity = max(0, (int) $level['quantity']);
            $body = [
                'manage_stock' => true,
                'stock_quantity' => $quantity,
                'stock_status' => $quantity > 0 ? 'instock' : 'outofstock',
            ];
            $response = $this->request($connection, 'PUT', $path, $body, allowNotFound: true);
            if ($this->responseIndicatesMissingEntity($response)) {
                throw new CommerceRemoteEntityMissing(__('commerce.sync.errors.woocommerce_inventory_mapping_missing'), false);
            }

            if (! $this->stockWasApplied($response, $quantity)) {
                // WooCommerce ignores per-product quantities while its global
                // stock switch is off. Inventory sync is explicitly enabled
                // for this connection, so enable the required provider setting
                // once and retry the same absolute update.
                try {
                    $this->request(
                        $connection,
                        'PUT',
                        '/settings/products/woocommerce_manage_stock',
                        ['value' => 'yes'],
                    );
                    $response = $this->request($connection, 'PUT', $path, $body);
                } catch (CommerceSyncException) {
                    throw new CommerceSyncException(__('commerce.sync.errors.woocommerce_stock_not_applied'), false);
                }
            }

            if (! $this->stockWasApplied($response, $quantity)) {
                throw new CommerceSyncException(__('commerce.sync.errors.woocommerce_stock_not_applied'), false);
            }
        }
    }

    private function stockWasApplied(Response $response, int $quantity): bool
    {
        $appliedQuantity = $response->json('stock_quantity');

        return $response->json('manage_stock') === true
            && $appliedQuantity !== null
            && (int) $appliedQuantity === $quantity;
    }

    public function fulfillOrder(CommerceConnection $connection, array $fulfillment): ?string
    {
        // Core WooCommerce has one order-level fulfillment state rather than
        // Shopify-style per-location fulfillments. Keep split allocations local
        // until the final POS store has fulfilled its portion, then complete the
        // remote order idempotently.
        if (! $fulfillment['all_fulfilled']) {
            return null;
        }

        $remoteOrderId = trim((string) $fulfillment['remote_order_id']);
        if ($remoteOrderId === '') {
            throw new CommerceSyncException(__('commerce.sync.errors.fulfillment_order_missing'));
        }

        $response = $this->request($connection, 'PUT', '/orders/'.rawurlencode($remoteOrderId), [
            'status' => 'completed',
        ]);

        return (string) ($response->json('id') ?: $remoteOrderId);
    }

    public function prepareOrderWebhookPayload(CommerceConnection $connection, string $topic, array $payload): array
    {
        if (! str_starts_with(strtolower($topic), 'order.') || empty($payload['id'])) {
            return $payload;
        }

        $refunds = (array) ($payload['refunds'] ?? []);
        $needsDetails = $refunds !== [] && ! collect($refunds)->contains(
            fn ($refund) => is_array($refund) && array_key_exists('line_items', $refund),
        );
        if ($needsDetails) {
            $payload['_pos_refunds'] = (array) $this->request(
                $connection,
                'GET',
                '/orders/'.rawurlencode((string) $payload['id']).'/refunds',
            )->json();
        }

        return $payload;
    }

    public function normalizeOrderWebhook(string $topic, array $payload): ?RemoteOrder
    {
        if (! str_starts_with(strtolower($topic), 'order.')) {
            return null;
        }

        $status = strtolower((string) ($payload['status'] ?? 'pending'));
        $paid = ! empty($payload['date_paid_gmt']) || in_array($status, ['processing', 'completed'], true);
        $cancelled = in_array($status, ['cancelled', 'failed', 'trash'], true);
        $refunds = (array) ($payload['_pos_refunds'] ?? $payload['refunds'] ?? []);
        $refundedByLine = $this->wooRefundedQuantities($refunds);
        $refundedTotal = $this->wooRefundedTotal($refunds, (string) ($payload['refunded_total'] ?? '0'));
        $lines = [];

        foreach ((array) ($payload['line_items'] ?? []) as $line) {
            $quantity = (string) ($line['quantity'] ?? '0');
            $subtotal = (string) ($line['subtotal'] ?? $line['total'] ?? '0');
            $total = (string) ($line['total'] ?? $subtotal);
            $unitPrice = bccomp($quantity, '0', 4) > 0 ? bcdiv($subtotal, $quantity, 4) : '0';
            $lines[] = new RemoteOrderLine(
                (string) ($line['id'] ?? hash('sha256', json_encode($line))),
                isset($line['product_id']) ? (string) $line['product_id'] : null,
                ! empty($line['variation_id']) ? (string) $line['variation_id'] : null,
                (string) ($line['name'] ?? __('commerce.orders.unnamed_product')),
                filled($line['sku'] ?? null) ? (string) $line['sku'] : null,
                $quantity,
                $unitPrice,
                bcsub($subtotal, $total, 4),
                (string) ($line['total_tax'] ?? '0'),
                $total,
                $status === 'completed' ? $quantity : '0',
                $status === 'refunded'
                    ? $quantity
                    : (string) ($refundedByLine[(string) ($line['id'] ?? '')] ?? '0'),
                ['taxes' => $line['taxes'] ?? []],
            );
        }

        return new RemoteOrder(
            (string) $payload['id'],
            isset($payload['number']) ? (string) $payload['number'] : null,
            $status,
            $status === 'refunded' ? 'refunded' : ($paid ? 'paid' : 'pending'),
            $status === 'completed' ? 'fulfilled' : 'unfulfilled',
            strtoupper((string) ($payload['currency'] ?? 'USD')),
            (string) collect($payload['line_items'] ?? [])->sum(fn ($line) => (float) ($line['subtotal'] ?? 0)),
            (string) ($payload['discount_total'] ?? '0'),
            (string) ($payload['total_tax'] ?? '0'),
            (string) ($payload['shipping_total'] ?? '0'),
            (string) ($payload['total'] ?? '0'),
            $status === 'refunded' && bccomp($refundedTotal, '0', 4) <= 0
                ? (string) ($payload['total'] ?? '0')
                : $refundedTotal,
            ! empty($payload['customer_id']) ? (string) $payload['customer_id'] : null,
            filled($payload['transaction_id'] ?? null) ? (string) $payload['transaction_id'] : null,
            (array) ($payload['billing'] ?? []),
            (array) ($payload['shipping'] ?? []),
            (array) ($payload['billing'] ?? []),
            $lines,
            $this->date($payload['date_created_gmt'] ?? $payload['date_created'] ?? null),
            $this->date($payload['date_paid_gmt'] ?? $payload['date_paid'] ?? null),
            $cancelled ? $this->date($payload['date_modified_gmt'] ?? $payload['date_modified'] ?? null) : null,
            $this->date($payload['date_modified_gmt'] ?? $payload['date_modified'] ?? null),
            $payload,
            $paid,
            $cancelled,
        );
    }

    public function registerWebhooks(CommerceConnection $connection, string $callbackUrl): void
    {
        $secret = (string) data_get($connection->credentials, 'webhook_secret', '');
        if ($secret === '') {
            throw new CommerceSyncException(__('commerce.sync.errors.woocommerce_webhook_secret_missing'), false);
        }

        $existing = collect($this->request($connection, 'GET', '/webhooks?per_page=100')->json());
        foreach (['order.created', 'order.updated', 'order.deleted', 'customer.created', 'customer.updated'] as $topic) {
            $registered = $existing->contains(fn ($webhook) => is_array($webhook)
                && ($webhook['topic'] ?? null) === $topic
                && rtrim((string) ($webhook['delivery_url'] ?? ''), '/') === rtrim($callbackUrl, '/'));
            if (! $registered) {
                $this->request($connection, 'POST', '/webhooks', [
                    'name' => 'POS '.$topic,
                    'topic' => $topic,
                    'delivery_url' => $callbackUrl,
                    'secret' => $secret,
                    'status' => 'active',
                ]);
            }
        }
    }

    /** @param list<array<string,mixed>> $refunds @return array<string,string> */
    private function wooRefundedQuantities(array $refunds): array
    {
        $totals = [];
        foreach ($refunds as $refund) {
            foreach ((array) ($refund['line_items'] ?? []) as $line) {
                $remoteLineId = (string) ($line['line_item_id'] ?? $this->wooOriginalLineId($line) ?? $line['id'] ?? '');
                if ($remoteLineId === '') {
                    continue;
                }
                $quantity = ltrim((string) ($line['quantity'] ?? '0'), '-');
                $totals[$remoteLineId] = bcadd($totals[$remoteLineId] ?? '0', $quantity, 4);
            }
        }

        return $totals;
    }

    /** @param list<array<string,mixed>> $refunds */
    private function wooRefundedTotal(array $refunds, string $fallback): string
    {
        $total = '0';
        foreach ($refunds as $refund) {
            $total = bcadd($total, ltrim((string) ($refund['total'] ?? '0'), '-'), 4);
        }

        return bccomp($total, '0', 4) > 0 ? $total : bcadd($fallback, '0', 4);
    }

    /** @param array<string,mixed> $line */
    private function wooOriginalLineId(array $line): ?string
    {
        foreach ((array) ($line['meta_data'] ?? []) as $meta) {
            if (is_array($meta) && ($meta['key'] ?? null) === '_refunded_item_id' && filled($meta['value'] ?? null)) {
                return (string) $meta['value'];
            }
        }

        return null;
    }

    public function normalizeCustomerWebhook(string $topic, array $payload): ?array
    {
        if (! str_starts_with(strtolower($topic), 'customer.') || empty($payload['id'])) {
            return null;
        }

        $billing = (array) ($payload['billing'] ?? []);

        return [
            'remote_id' => (string) $payload['id'],
            'first_name' => (string) ($payload['first_name'] ?? $billing['first_name'] ?? ''),
            'last_name' => (string) ($payload['last_name'] ?? $billing['last_name'] ?? ''),
            'email' => filled($payload['email'] ?? null) ? strtolower((string) $payload['email']) : null,
            'phone' => filled($billing['phone'] ?? null) ? (string) $billing['phone'] : null,
            'address' => [
                'line1' => $billing['address_1'] ?? null,
                'line2' => $billing['address_2'] ?? null,
                'city' => $billing['city'] ?? null,
                'state' => $billing['state'] ?? null,
                'postal_code' => $billing['postcode'] ?? null,
                'country_code' => $billing['country'] ?? null,
                'is_default' => true,
            ],
            'remote_updated_at' => $payload['date_modified_gmt'] ?? $payload['date_modified'] ?? null,
            'payload' => $payload,
        ];
    }

    /** @param array<string, mixed> $body */
    private function request(
        CommerceConnection $connection,
        string $method,
        string $path,
        array $body = [],
        bool $allowNotFound = false,
    ): Response {
        $credentials = $connection->credentials ?? [];
        $options = $body === [] ? [] : ['json' => $body];

        try {
            $response = Http::withBasicAuth(
                (string) ($credentials['consumer_key'] ?? ''),
                (string) ($credentials['consumer_secret'] ?? ''),
            )
                ->acceptJson()
                ->connectTimeout((int) config('commerce.connect_timeout', 5))
                ->timeout((int) config('commerce.timeout', 20))
                ->send($method, rtrim($connection->shop_url, '/').config('commerce.woocommerce.api_path', '/wp-json/wc/v3').$path, $options);
        } catch (ConnectionException $e) {
            throw new CommerceSyncException(__('commerce.sync.errors.woocommerce_unreachable', [
                'reason' => $this->connectionFailureReason($e),
            ]), true);
        }

        if ($allowNotFound && $this->responseIndicatesMissingEntity($response)) {
            return $response;
        }

        if (! $response->successful()) {
            $message = trim((string) $response->json('message')) ?: __('commerce.sync.errors.woocommerce_http', ['status' => $response->status()]);
            throw new CommerceSyncException(mb_substr($message, 0, 500), $response->status() === 429 || $response->serverError());
        }

        return $response;
    }

    /**
     * WooCommerce normally reports a deleted resource with HTTP 404, but
     * some versions return HTTP 400 with an `*_invalid_id` REST error. Both
     * responses mean the permanent local mapping is stale and may be safely
     * recreated; unrelated validation failures must still surface normally.
     */
    private function responseIndicatesMissingEntity(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }
        if ($response->status() !== 400) {
            return false;
        }

        $code = strtolower(trim((string) $response->json('code')));
        $message = strtolower(trim((string) $response->json('message')));

        return str_ends_with($code, '_invalid_id')
            || in_array($code, ['rest_invalid_id', 'rest_post_invalid_id'], true)
            || in_array($message, ['invalid id', 'invalid id.'], true);
    }

    private function connectionFailureReason(ConnectionException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'curl error 6') || str_contains($message, 'resolve host')) {
            return __('commerce.sync.errors.network.dns');
        }
        if (str_contains($message, 'curl error 28') || str_contains($message, 'timed out')) {
            return __('commerce.sync.errors.network.timeout');
        }
        if (str_contains($message, 'curl error 35')
            || str_contains($message, 'curl error 51')
            || str_contains($message, 'curl error 58')
            || str_contains($message, 'curl error 60')
            || str_contains($message, 'curl error 77')
            || str_contains($message, 'ssl')
            || str_contains($message, 'certificate')) {
            return __('commerce.sync.errors.network.tls');
        }
        if (str_contains($message, 'curl error 7')
            || str_contains($message, 'curl error 52')
            || str_contains($message, 'curl error 56')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'empty reply')) {
            return __('commerce.sync.errors.network.connection');
        }

        return __('commerce.sync.errors.network.unknown');
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse((string) $value) : null;
    }

    private function httpFailure(int $status): string
    {
        return match ($status) {
            401, 403 => __('commerce.connection_test.woocommerce.credentials_rejected'),
            404 => __('commerce.connection_test.woocommerce.api_not_found'),
            default => __('commerce.connection_test.woocommerce.http_error', ['status' => $status]),
        };
    }
}
