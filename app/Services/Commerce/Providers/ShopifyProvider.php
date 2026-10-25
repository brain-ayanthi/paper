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
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ShopifyProvider implements CommerceProvider
{
    private const ONLINE_STOREFRONT_SCOPES = [
        'read_publications',
        'write_publications',
        'write_locations',
    ];

    /** @var array<string, array{collection_id:string,source_id:string}> */
    private array $categoryCollections = [];

    /** @var array<string, string> */
    private array $onlineStorePublicationIds = [];

    /** @var array<string, true> */
    private array $publishedResources = [];

    /** @var array<string, true> */
    private array $onlineFulfillmentLocations = [];

    public function code(): string
    {
        return 'shopify';
    }

    public function label(): string
    {
        return __('commerce.providers.shopify');
    }

    public function normalizeShopUrl(string $shopUrl): string
    {
        $value = strtolower(trim($shopUrl));
        $value = preg_replace('~^https?://~', '', $value) ?? $value;
        $value = explode('/', $value, 2)[0];

        if (! str_ends_with($value, '.myshopify.com')) {
            $value .= '.myshopify.com';
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $value)) {
            throw new InvalidArgumentException(__('commerce.connection_test.shopify.invalid_domain'));
        }

        return 'https://'.$value;
    }

    public function secretFields(): array
    {
        return ['client_secret'];
    }

    public function testConnection(
        string $shopUrl,
        array $credentials,
        ?string $apiVersion = null,
        array $settings = [],
    ): ConnectionTestResult {
        $publishOnlineStore = (bool) data_get($settings, 'publish_online_store', false);

        try {
            $url = $this->normalizeShopUrl($shopUrl);
        } catch (InvalidArgumentException $e) {
            return ConnectionTestResult::failure($e->getMessage());
        }

        if (trim((string) ($credentials['client_id'] ?? '')) === '' || trim((string) ($credentials['client_secret'] ?? '')) === '') {
            return ConnectionTestResult::failure(__('commerce.connection_test.shopify.credentials_required'));
        }

        try {
            // A connection test must obtain a fresh token so scope changes
            // approved during a Shopify app update are checked immediately.
            [$token, $credentialUpdates] = $this->accessToken($url, $credentials, true);
            $grantedScopes = (string) ($credentialUpdates['granted_scopes'] ?? $credentials['granted_scopes'] ?? '');
            $missingScopes = $publishOnlineStore ? $this->missingOnlineStoreScopes($grantedScopes) : [];
            if ($missingScopes !== []) {
                return ConnectionTestResult::failure(__('commerce.connection_test.shopify.storefront_scopes_missing', [
                    'scopes' => implode(', ', $missingScopes),
                ]));
            }

            $version = $apiVersion ?: (string) config('commerce.shopify.api_version', '2026-07');
            $query = $publishOnlineStore
                ? <<<'GRAPHQL'
                    query PosConnectionTest {
                      shop { id name myshopifyDomain }
                      locations(first: 250, includeInactive: true) {
                        nodes { id name isActive fulfillsOnlineOrders }
                      }
                      publications(first: 50, catalogType: APP) {
                        nodes {
                          id
                          channels(first: 10) { nodes { handle } }
                        }
                      }
                    }
                    GRAPHQL
                : <<<'GRAPHQL'
                    query PosConnectionTest {
                      shop { id name myshopifyDomain }
                      locations(first: 250, includeInactive: true) {
                        nodes { id name isActive fulfillsOnlineOrders }
                      }
                    }
                    GRAPHQL;
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $token])
                ->acceptJson()
                ->connectTimeout((int) config('commerce.connect_timeout', 5))
                ->timeout((int) config('commerce.timeout', 20))
                ->post("{$url}/admin/api/{$version}/graphql.json", [
                    'query' => $query,
                ]);
        } catch (ConnectionException) {
            return ConnectionTestResult::failure(__('commerce.connection_test.shopify.unreachable'));
        } catch (\RuntimeException $e) {
            return ConnectionTestResult::failure($e->getMessage());
        } catch (\Throwable) {
            return ConnectionTestResult::failure(__('commerce.connection_test.shopify.failed'));
        }

        if (! $response->successful()) {
            return ConnectionTestResult::failure($this->httpFailure(
                $response->status(),
                (string) ($credentialUpdates['granted_scopes'] ?? $credentials['granted_scopes'] ?? ''),
            ));
        }

        $errors = $response->json('errors', []);
        if (is_array($errors) && $errors !== []) {
            return ConnectionTestResult::failure(mb_substr(trim((string) ($errors[0]['message'] ?? __('commerce.connection_test.shopify.graphql_rejected'))), 0, 300));
        }

        $shop = (array) $response->json('data.shop', []);
        if (empty($shop['id'])) {
            return ConnectionTestResult::failure(__('commerce.connection_test.shopify.incomplete_response'));
        }

        $locations = collect($response->json('data.locations.nodes', []))
            ->filter(fn ($row) => is_array($row) && ! empty($row['id']))
            ->map(fn (array $row) => new RemoteLocation(
                (string) $row['id'],
                (string) ($row['name'] ?? $row['id']),
                (bool) ($row['isActive'] ?? true),
                (bool) ($row['fulfillsOnlineOrders'] ?? true),
            ))
            ->values()
            ->all();

        $onlineStorePublicationId = null;
        if ($publishOnlineStore) {
            $onlineStorePublicationId = $this->onlineStorePublicationIdFrom(
                (array) $response->json('data.publications.nodes', []),
            );
            if (! $onlineStorePublicationId) {
                return ConnectionTestResult::failure(__('commerce.connection_test.shopify.online_store_publication_missing'));
            }
        }

        return ConnectionTestResult::success(
            __('commerce.connection_test.shopify.connected'),
            (string) $shop['id'],
            (string) ($shop['name'] ?? $shop['myshopifyDomain'] ?? ''),
            $locations,
            $credentialUpdates,
            array_filter([
                'myshopify_domain' => $shop['myshopifyDomain'] ?? null,
                'online_store_publication_id' => $onlineStorePublicationId,
            ]),
        );
    }

    public function upsertProduct(CommerceConnection $connection, array $product, ?string $remoteId): ProviderSyncResult
    {
        $options = $product['options'] ?? [];
        $variants = $product['variants'] ?? [];

        if (($product['type'] ?? 'simple') !== 'variant') {
            $options = [['name' => 'Title', 'values' => ['Default Title']]];
            $variants = [[
                'local_type' => $product['local_type'],
                'local_id' => $product['local_id'],
                'remote_id' => $product['remote_variant_id'] ?? null,
                'sku' => $product['sku'] ?? '',
                'barcode' => $product['barcode'] ?? null,
                'price' => $product['price'] ?? '0',
                'active' => $product['active'] ?? true,
                'option_values' => [['name' => 'Title', 'value' => 'Default Title']],
            ]];
        }

        $image = is_array($product['image'] ?? null) ? $product['image'] : null;
        $remoteMetadata = (array) ($product['remote_metadata'] ?? []);

        $input = [
            'title' => (string) $product['name'],
            'handle' => (string) $product['handle'],
            'descriptionHtml' => nl2br(e((string) ($product['description'] ?? ''))),
            'status' => ($product['active'] ?? true) ? 'ACTIVE' : 'DRAFT',
            'vendor' => filled($product['vendor'] ?? null) ? (string) $product['vendor'] : '',
            'productType' => mb_substr((string) data_get($product, 'category.breadcrumb', ''), 0, 255),
            'files' => $image ? [$this->productFileInput($image, $remoteMetadata)] : [],
            'productOptions' => collect($options)->values()->map(fn (array $option, int $index) => [
                'name' => (string) $option['name'],
                'position' => $index + 1,
                'values' => collect($option['values'] ?? [])->map(fn ($value) => ['name' => (string) $value])->values()->all(),
            ])->all(),
            'variants' => collect($variants)->map(function (array $variant) use ($product): array {
                return array_filter([
                    'id' => $variant['remote_id'] ?? null,
                    'sku' => (string) ($variant['sku'] ?? ''),
                    'barcode' => filled($variant['barcode'] ?? null) ? (string) $variant['barcode'] : null,
                    'price' => (string) ($variant['price'] ?? '0'),
                    'compareAtPrice' => filled($variant['compare_at_price'] ?? null) ? (string) $variant['compare_at_price'] : null,
                    'published' => (bool) ($variant['active'] ?? true),
                    'inventoryItem' => ['tracked' => (bool) ($product['track_stock'] ?? true)],
                    'optionValues' => collect($variant['option_values'] ?? [])->map(fn (array $option) => [
                        'optionName' => (string) $option['name'],
                        'name' => (string) $option['value'],
                    ])->values()->all(),
                ], fn ($value) => $value !== null);
            })->values()->all(),
        ];
        $input = array_filter($input, fn ($value) => $value !== null);

        $query = <<<'GRAPHQL'
            mutation PosProductSet($input: ProductSetInput!, $identifier: ProductSetIdentifiers) {
              productSet(input: $input, identifier: $identifier, synchronous: true) {
                product {
                  id
                  handle
                  media(first: 10) {
                    nodes { id alt mediaContentType status }
                  }
                  variants(first: 250) {
                    nodes { id sku inventoryItem { id } }
                  }
                }
                userErrors { field message code }
              }
            }
            GRAPHQL;
        try {
            $data = $this->graphql($connection, $query, [
                'input' => $input,
                'identifier' => $remoteId ? ['id' => $remoteId] : ['handle' => $product['handle']],
            ], 'productSet');
        } catch (CommerceRemoteEntityMissing $e) {
            if (! $remoteId) {
                throw $e;
            }

            // A product permanently deleted in Shopify leaves local product
            // and variant IDs stale. Recreate it by the stable POS handle and
            // let the returned entities replace every local mapping.
            $input['variants'] = collect($input['variants'] ?? [])->map(function (array $variant): array {
                unset($variant['id']);

                return $variant;
            })->all();
            if ($image) {
                $input['files'] = [$this->productFileInput($image)];
            }
            $data = $this->graphql($connection, $query, [
                'input' => $input,
                'identifier' => ['handle' => $product['handle']],
            ], 'productSet');
        }

        $remoteProduct = (array) data_get($data, 'productSet.product', []);
        $parentId = (string) ($remoteProduct['id'] ?? '');
        if ($parentId === '') {
            throw new CommerceSyncException(__('commerce.sync.errors.shopify_incomplete_response'));
        }

        $parentMetadata = $remoteMetadata;
        unset($parentMetadata['image_id'], $parentMetadata['image_hash']);
        if ($image) {
            $remoteImage = collect(data_get($remoteProduct, 'media.nodes', []))
                ->first(fn ($media) => is_array($media) && ($media['mediaContentType'] ?? null) === 'IMAGE');
            if (! is_array($remoteImage) || empty($remoteImage['id'])) {
                throw new CommerceSyncException(__('commerce.sync.errors.shopify_image_not_applied'), false);
            }
            $parentMetadata['image_id'] = (string) $remoteImage['id'];
            $parentMetadata['image_hash'] = (string) $image['hash'];
        }
        $parentMetadata['handle'] = $remoteProduct['handle'] ?? $product['handle'];

        [$categoryEntities, $categorySources] = $this->syncCategoryCollections(
            $connection,
            $parentId,
            (array) data_get($product, 'category.path', []),
        );
        $this->removePreviousCategorySelections(
            $connection,
            $parentId,
            (array) ($remoteMetadata['shopify_category_sources'] ?? []),
            $categorySources,
        );
        $parentMetadata['shopify_category_sources'] = $categorySources;

        if (($product['active'] ?? true) && $this->publishesToOnlineStore($connection)) {
            $this->publishToOnlineStore($connection, [
                $parentId,
                ...collect($categoryEntities)->map(fn (RemoteEntity $entity) => $entity->remoteId)->all(),
            ]);
        }

        $entities = [...$categoryEntities, new RemoteEntity(
            CommerceEntityMapping::TYPE_PRODUCT,
            (string) $product['local_type'],
            (int) $product['local_id'],
            $parentId,
            metadata: $parentMetadata,
        )];
        $remoteVariants = collect(data_get($remoteProduct, 'variants.nodes', []))->keyBy(fn ($variant) => (string) ($variant['sku'] ?? ''));

        foreach ($variants as $variant) {
            $remoteVariant = $remoteVariants->get((string) ($variant['sku'] ?? ''));
            if (! is_array($remoteVariant) || empty($remoteVariant['id'])) {
                throw new CommerceSyncException(__('commerce.sync.errors.shopify_variant_missing', ['sku' => $variant['sku'] ?? '']));
            }
            $entities[] = new RemoteEntity(
                CommerceEntityMapping::TYPE_VARIANT,
                (string) $variant['local_type'],
                (int) $variant['local_id'],
                (string) $remoteVariant['id'],
                $parentId,
                ['inventory_item_id' => data_get($remoteVariant, 'inventoryItem.id')],
            );
        }

        return new ProviderSyncResult($entities);
    }

    /**
     * Shopify collections are flat. Each POS category path becomes a set of
     * flat manual collections, and the product is selected into every level
     * so a parent collection also contains products from its descendants.
     *
     * @param  list<array<string, mixed>>  $path
     * @return array{0:list<RemoteEntity>,1:list<array{collection_id:string,source_id:string}>}
     */
    private function syncCategoryCollections(CommerceConnection $connection, string $productId, array $path): array
    {
        $entities = [];
        $sources = [];
        $breadcrumb = [];

        foreach ($path as $category) {
            if (! is_array($category) || empty($category['local_type']) || empty($category['local_id']) || ! filled($category['name'] ?? null)) {
                continue;
            }

            $breadcrumb[] = (string) $category['name'];
            $title = implode(' > ', $breadcrumb);
            $handle = 'pos-category-'.(int) $category['local_id'];
            $cacheKey = $this->categoryCollectionCacheKey($connection, $category);
            $cached = $this->categoryCollections[$cacheKey] ?? null;
            $remoteId = (string) ($cached['collection_id'] ?? $category['remote_id'] ?? '');
            $sourceId = (string) ($cached['source_id'] ?? data_get($category, 'remote_metadata.source_id', ''));

            if ($cached === null) {
                $remoteCollection = $this->findCategoryCollection($connection, $remoteId, $handle);
                $remoteId = (string) ($remoteCollection['id'] ?? '');
                $sourceId = $this->categorySourceId((array) ($remoteCollection['sources'] ?? []), $sourceId);
            }

            if ($remoteId === '') {
                [$remoteId, $sourceId] = $this->createCategoryCollection(
                    $connection,
                    $productId,
                    $title,
                    $handle,
                    (string) ($category['description'] ?? ''),
                );
            } else {
                [$remoteId, $sourceId] = $this->updateCategoryCollection(
                    $connection,
                    $productId,
                    $remoteId,
                    $sourceId,
                    $title,
                    (string) ($category['description'] ?? ''),
                );
            }

            $this->categoryCollections[$cacheKey] = [
                'collection_id' => $remoteId,
                'source_id' => $sourceId,
            ];
            $sources[] = ['collection_id' => $remoteId, 'source_id' => $sourceId];
            $entities[] = new RemoteEntity(
                CommerceEntityMapping::TYPE_CATEGORY,
                (string) $category['local_type'],
                (int) $category['local_id'],
                $remoteId,
                metadata: [
                    'source_id' => $sourceId,
                    'handle' => $handle,
                    'title' => $title,
                ],
            );
        }

        return [$entities, $sources];
    }

    /** @param array<string, mixed> $category */
    private function categoryCollectionCacheKey(CommerceConnection $connection, array $category): string
    {
        return implode('|', [
            (string) ($connection->getKey() ?: $connection->shop_url),
            (string) $category['local_type'],
            (string) $category['local_id'],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function findCategoryCollection(CommerceConnection $connection, string $remoteId, string $handle): ?array
    {
        $query = <<<'GRAPHQL'
            query PosCategoryCollection($identifier: CollectionIdentifierInput!) {
              collectionByIdentifier(identifier: $identifier) {
                id
                handle
                sources { __typename id title }
              }
            }
            GRAPHQL;

        foreach (array_filter([
            $remoteId !== '' ? ['id' => $remoteId] : null,
            $handle !== '' ? ['handle' => $handle] : null,
        ]) as $identifier) {
            $collection = data_get(
                $this->graphql($connection, $query, ['identifier' => $identifier], 'collectionByIdentifier'),
                'collectionByIdentifier',
            );
            if (is_array($collection) && filled($collection['id'] ?? null)) {
                return $collection;
            }
        }

        return null;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function createCategoryCollection(
        CommerceConnection $connection,
        string $productId,
        string $title,
        string $handle,
        string $description,
    ): array {
        $mutation = <<<'GRAPHQL'
            mutation PosCategoryCollectionCreate($collection: CollectionCreateInput!) {
              collectionCreate(collection: $collection) {
                collection {
                  id
                  handle
                  sources { __typename id title }
                }
                userErrors { field message }
              }
            }
            GRAPHQL;
        $data = $this->graphql($connection, $mutation, [
            'collection' => [
                'title' => mb_substr($title, 0, 255),
                'handle' => $handle,
                'descriptionHtml' => nl2br(e($description)),
                'sortOrder' => 'ALPHA_ASC',
                'sources' => [[
                    'source' => [
                        'title' => __('commerce.sync.shopify.category_source'),
                        'targetType' => 'PRODUCTS',
                        'inclusion' => [
                            'selections' => [['productId' => $productId]],
                        ],
                    ],
                ]],
            ],
        ], 'collectionCreate');

        $collection = (array) data_get($data, 'collectionCreate.collection', []);
        $remoteId = (string) ($collection['id'] ?? '');
        $sourceId = $this->categorySourceId((array) ($collection['sources'] ?? []));
        if ($remoteId === '' || $sourceId === '') {
            throw new CommerceSyncException(__('commerce.sync.errors.shopify_collection_incomplete'));
        }

        return [$remoteId, $sourceId];
    }

    /** @return array{0:string,1:string} */
    private function updateCategoryCollection(
        CommerceConnection $connection,
        string $productId,
        string $remoteId,
        string $sourceId,
        string $title,
        string $description,
    ): array {
        $collection = [
            'id' => $remoteId,
            'title' => mb_substr($title, 0, 255),
            'descriptionHtml' => nl2br(e($description)),
        ];
        if ($sourceId === '') {
            $collection['sourcesToCreate'] = [[
                'source' => [
                    'title' => __('commerce.sync.shopify.category_source'),
                    'targetType' => 'PRODUCTS',
                    'inclusion' => [
                        'selections' => [['productId' => $productId]],
                    ],
                ],
            ]];
        } else {
            $collection['sourcesToUpdate'] = [[
                'condition' => [
                    'id' => $sourceId,
                    'inclusion' => [
                        'selectionsToAdd' => [['productId' => $productId]],
                    ],
                ],
            ]];
        }

        $mutation = <<<'GRAPHQL'
            mutation PosCategoryCollectionUpdate($collection: CollectionUpdateInput!) {
              collectionUpdate(collection: $collection) {
                collection {
                  id
                  handle
                  sources { __typename id title }
                }
                userErrors { field message }
              }
            }
            GRAPHQL;
        $data = $this->graphql($connection, $mutation, ['collection' => $collection], 'collectionUpdate');
        $remoteCollection = (array) data_get($data, 'collectionUpdate.collection', []);
        $updatedRemoteId = (string) ($remoteCollection['id'] ?? '');
        $updatedSourceId = $this->categorySourceId((array) ($remoteCollection['sources'] ?? []), $sourceId);
        if ($updatedRemoteId === '' || $updatedSourceId === '') {
            throw new CommerceSyncException(__('commerce.sync.errors.shopify_collection_incomplete'));
        }

        return [$updatedRemoteId, $updatedSourceId];
    }

    /** @param list<array<string, mixed>> $sources */
    private function categorySourceId(array $sources, string $preferredId = ''): string
    {
        $conditionSources = collect($sources)
            ->filter(fn ($source) => is_array($source) && ($source['__typename'] ?? null) === 'CollectionConditionsSource');

        if ($preferredId !== '') {
            $preferred = $conditionSources->first(fn ($source) => ($source['id'] ?? null) === $preferredId);
            if (is_array($preferred)) {
                return $preferredId;
            }
        }

        $managed = $conditionSources->first(fn ($source) => ($source['title'] ?? null) === __('commerce.sync.shopify.category_source'));

        return is_array($managed) ? (string) ($managed['id'] ?? '') : '';
    }

    /**
     * Remove this product only from category sources previously managed by
     * the POS. Other Shopify collections and sources are never modified.
     *
     * @param  list<array{collection_id?:string,source_id?:string}>  $previous
     * @param  list<array{collection_id:string,source_id:string}>  $current
     */
    private function removePreviousCategorySelections(
        CommerceConnection $connection,
        string $productId,
        array $previous,
        array $current,
    ): void {
        $currentIds = collect($current)->pluck('collection_id')->filter()->all();
        $mutation = <<<'GRAPHQL'
            mutation PosCategoryCollectionRemove($id: ID!, $productIds: [ID!]!) {
              collectionRemoveProducts(id: $id, productIds: $productIds) {
                job { id done }
                userErrors { field message }
              }
            }
            GRAPHQL;

        foreach ($previous as $source) {
            if (! is_array($source)) {
                continue;
            }
            $collectionId = (string) ($source['collection_id'] ?? '');
            $sourceId = (string) ($source['source_id'] ?? '');
            if ($collectionId === '' || $sourceId === '' || in_array($collectionId, $currentIds, true)) {
                continue;
            }

            $existing = $this->findCategoryCollection($connection, $collectionId, '');
            if (! is_array($existing) || $this->categorySourceId((array) ($existing['sources'] ?? []), $sourceId) === '') {
                continue;
            }

            $this->graphql($connection, $mutation, [
                'id' => $collectionId,
                'productIds' => [$productId],
            ], 'collectionRemoveProducts');
        }
    }

    /**
     * @param  array{url:string,filename:string,alt:string,hash:string}  $image
     * @param  array<string, mixed>  $remoteMetadata
     * @return array<string, string>
     */
    private function productFileInput(array $image, array $remoteMetadata = []): array
    {
        if (($remoteMetadata['image_hash'] ?? null) === $image['hash'] && filled($remoteMetadata['image_id'] ?? null)) {
            return [
                'id' => (string) $remoteMetadata['image_id'],
                'alt' => (string) $image['alt'],
            ];
        }

        return [
            'originalSource' => (string) $image['url'],
            'filename' => (string) $image['filename'],
            'contentType' => 'IMAGE',
            'duplicateResolutionMode' => 'REPLACE',
            'alt' => (string) $image['alt'],
        ];
    }

    public function archiveProduct(CommerceConnection $connection, string $remoteId): void
    {
        $lookup = <<<'GRAPHQL'
            query PosProductArchiveLookup($id: ID!) {
              product(id: $id) { id status }
            }
            GRAPHQL;

        $product = (array) data_get(
            $this->graphql($connection, $lookup, ['id' => $remoteId], 'product'),
            'product',
            [],
        );
        if (empty($product['id']) || ($product['status'] ?? null) === 'ARCHIVED') {
            return;
        }

        $mutation = <<<'GRAPHQL'
            mutation PosProductArchive($product: ProductUpdateInput!) {
              productUpdate(product: $product) {
                product { id status }
                userErrors { field message }
              }
            }
            GRAPHQL;

        $this->graphql($connection, $mutation, [
            'product' => ['id' => $remoteId, 'status' => 'ARCHIVED'],
        ], 'productUpdate');
    }

    public function upsertCustomer(CommerceConnection $connection, array $customer, ?string $remoteId): ProviderSyncResult
    {
        if (empty($customer['email']) && empty($customer['phone'])) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.customer_contact_required'));
        }

        $rawAddress = (array) ($customer['address'] ?? []);
        $countryCode = strtoupper(trim((string) ($rawAddress['country_code'] ?? '')));
        $countryCode = preg_match('/^[A-Z]{2}$/', $countryCode) ? $countryCode : null;
        $provinceCode = strtoupper(trim((string) ($rawAddress['state'] ?? '')));
        $provinceCode = preg_match('/^[A-Z0-9-]{1,6}$/', $provinceCode) ? $provinceCode : null;
        $address = array_filter([
            'address1' => $rawAddress['line1'] ?? null,
            'address2' => $rawAddress['line2'] ?? null,
            'city' => $rawAddress['city'] ?? null,
            'provinceCode' => $provinceCode,
            'zip' => $rawAddress['postal_code'] ?? null,
            'countryCode' => $countryCode,
            'firstName' => $customer['first_name'] ?? null,
            'lastName' => $customer['last_name'] ?? null,
            'phone' => $customer['phone'] ?? null,
        ], fn ($value) => filled($value));
        $input = array_filter([
            'email' => $customer['email'] ?? null,
            'phone' => $customer['phone'] ?? null,
            'firstName' => $customer['first_name'] ?? null,
            'lastName' => $customer['last_name'] ?? null,
            'note' => $customer['notes'] ?? null,
            'addresses' => $address === [] ? null : [$address],
        ], fn ($value) => $value !== null && $value !== '');
        // Customer webhooks store numeric IDs; GraphQL requires a Customer GID.
        // Also accept existing mappings already returned by GraphQL unchanged.
        $identifier = $remoteId
            ? ['id' => ctype_digit($remoteId) ? 'gid://shopify/Customer/'.$remoteId : $remoteId]
            : (filled($customer['email'] ?? null) ? ['email' => $customer['email']] : ['phone' => $customer['phone']]);

        $query = <<<'GRAPHQL'
            mutation PosCustomerSet($input: CustomerSetInput!, $identifier: CustomerSetIdentifiers) {
              customerSet(input: $input, identifier: $identifier) {
                customer { id }
                userErrors { field message }
              }
            }
            GRAPHQL;
        $data = $this->graphql($connection, $query, compact('input', 'identifier'), 'customerSet');
        $remoteCustomerId = (string) data_get($data, 'customerSet.customer.id', '');
        if ($remoteCustomerId === '') {
            throw new CommerceSyncException(__('commerce.sync.errors.shopify_incomplete_response'));
        }

        return new ProviderSyncResult([new RemoteEntity(
            CommerceEntityMapping::TYPE_CUSTOMER,
            (string) $customer['local_type'],
            (int) $customer['local_id'],
            $remoteCustomerId,
        )]);
    }

    public function setInventory(CommerceConnection $connection, array $levels): void
    {
        $quantities = collect($levels)->map(function (array $level): array {
            $inventoryItemId = data_get($level, 'metadata.inventory_item_id');
            if (! filled($inventoryItemId) || ! filled($level['remote_location_id'] ?? null)) {
                throw new CommerceSyncException(__('commerce.sync.errors.shopify_inventory_mapping_missing'));
            }

            return [
                'inventoryItemId' => (string) $inventoryItemId,
                'locationId' => (string) $level['remote_location_id'],
                'quantity' => max(0, (int) $level['quantity']),
            ];
        })->values()->all();

        if ($quantities === []) {
            return;
        }

        if ($this->publishesToOnlineStore($connection)) {
            $this->enableOnlineFulfillment($connection, collect($quantities)->pluck('locationId')->unique()->all());
        }

        // A Shopify inventory item must be stocked at a location before its
        // quantity can be changed. Product creation gives us the inventory
        // item identity but does not activate every POS-mapped location.
        $activationQuery = <<<'GRAPHQL'
            mutation PosInventoryActivate($inventoryItemId: ID!, $inventoryItemUpdates: [InventoryBulkToggleActivationInput!]!) {
              inventoryBulkToggleActivation(inventoryItemId: $inventoryItemId, inventoryItemUpdates: $inventoryItemUpdates) {
                inventoryItem { id }
                inventoryLevels { id location { id } }
                userErrors { field message code }
              }
            }
            GRAPHQL;
        collect($quantities)
            ->groupBy('inventoryItemId')
            ->each(function ($itemQuantities, string $inventoryItemId) use ($connection, $activationQuery): void {
                $this->graphql($connection, $activationQuery, [
                    'inventoryItemId' => $inventoryItemId,
                    'inventoryItemUpdates' => $itemQuantities
                        ->pluck('locationId')
                        ->unique()
                        ->map(fn (string $locationId) => [
                            'locationId' => $locationId,
                            'activate' => true,
                        ])
                        ->values()
                        ->all(),
                ], 'inventoryBulkToggleActivation');
            });

        $query = <<<'GRAPHQL'
            mutation PosInventorySet($input: InventorySetQuantitiesInput!, $idempotencyKey: String!) {
              inventorySetQuantities(input: $input) @idempotent(key: $idempotencyKey) {
                inventoryAdjustmentGroup {
                  createdAt
                  reason
                  changes {
                    name
                    quantityAfterChange
                    item { id }
                    location { id }
                  }
                }
                userErrors { field message code }
              }
            }
            GRAPHQL;
        $data = $this->graphql($connection, $query, [
            'idempotencyKey' => (string) Str::uuid(),
            'input' => [
                'name' => 'available',
                'reason' => 'correction',
                // Shopify 2026-04+ removed ignoreCompareQuantity. Passing
                // changeFromQuantity explicitly as null preserves POS as the
                // inventory source of truth without a stale-value comparison.
                'quantities' => collect($quantities)
                    ->map(fn (array $quantity): array => array_replace($quantity, [
                        'changeFromQuantity' => null,
                    ]))
                    ->all(),
            ],
        ], 'inventorySetQuantities');

        $this->confirmInventoryQuantities($connection, $quantities, $data);
    }

    public function fulfillOrder(CommerceConnection $connection, array $fulfillment): ?string
    {
        $remoteOrderId = trim((string) $fulfillment['remote_order_id']);
        $remoteLocationId = trim((string) ($fulfillment['remote_location_id'] ?? ''));
        if ($remoteOrderId === '' || $remoteLocationId === '') {
            throw new CommerceSyncException(__('commerce.sync.errors.shopify_fulfillment_mapping_missing'), false);
        }

        $orderId = str_starts_with($remoteOrderId, 'gid://')
            ? $remoteOrderId
            : 'gid://shopify/Order/'.$remoteOrderId;
        $locationId = str_starts_with($remoteLocationId, 'gid://')
            ? $remoteLocationId
            : 'gid://shopify/Location/'.$remoteLocationId;

        $query = <<<'GRAPHQL'
            query PosFulfillmentOrders($orderId: ID!) {
              order(id: $orderId) {
                fulfillmentOrders(first: 250) {
                  nodes {
                    id
                    status
                    assignedLocation { location { id } }
                    lineItems(first: 250) {
                      nodes {
                        id
                        remainingQuantity
                        lineItem { id }
                      }
                    }
                  }
                }
              }
            }
            GRAPHQL;
        $data = $this->graphql($connection, $query, ['orderId' => $orderId], 'order');
        $orders = collect(data_get($data, 'order.fulfillmentOrders.nodes', []));
        if ($orders->isEmpty()) {
            throw new CommerceSyncException(__('commerce.sync.errors.shopify_fulfillment_orders_missing'), false);
        }

        $requested = collect($fulfillment['lines'])
            ->mapWithKeys(fn (array $line) => [(string) $line['remote_line_id'] => max(0, (int) $line['quantity'])])
            ->all();
        $found = array_fill_keys(array_keys($requested), false);
        foreach ($orders as $fulfillmentOrder) {
            foreach ((array) data_get($fulfillmentOrder, 'lineItems.nodes', []) as $remoteLine) {
                $lineId = $this->legacyId((string) data_get($remoteLine, 'lineItem.id', ''));
                if (array_key_exists($lineId, $found)) {
                    $found[$lineId] = true;
                }
            }
        }
        $groups = [];

        foreach ($orders as $fulfillmentOrder) {
            if (! is_array($fulfillmentOrder)
                || data_get($fulfillmentOrder, 'assignedLocation.location.id') !== $locationId
                || in_array(strtoupper((string) ($fulfillmentOrder['status'] ?? '')), ['CANCELLED', 'CLOSED'], true)) {
                continue;
            }

            $items = [];
            foreach ((array) data_get($fulfillmentOrder, 'lineItems.nodes', []) as $remoteLine) {
                $lineId = $this->legacyId((string) data_get($remoteLine, 'lineItem.id', ''));
                if (! array_key_exists($lineId, $requested)) {
                    continue;
                }

                $remaining = max(0, (int) ($remoteLine['remainingQuantity'] ?? 0));
                $take = min($remaining, $requested[$lineId]);
                if ($take <= 0) {
                    continue;
                }

                $items[] = ['id' => (string) $remoteLine['id'], 'quantity' => $take];
                $requested[$lineId] -= $take;
            }

            if ($items !== []) {
                $groups[] = [
                    'fulfillmentOrderId' => (string) $fulfillmentOrder['id'],
                    'fulfillmentOrderLineItems' => $items,
                ];
            }
        }

        $moveRequests = [];
        foreach ($orders as $fulfillmentOrder) {
            if (! is_array($fulfillmentOrder)
                || data_get($fulfillmentOrder, 'assignedLocation.location.id') === $locationId
                || in_array(strtoupper((string) ($fulfillmentOrder['status'] ?? '')), ['CANCELLED', 'CLOSED'], true)) {
                continue;
            }

            $items = [];
            foreach ((array) data_get($fulfillmentOrder, 'lineItems.nodes', []) as $remoteLine) {
                $lineId = $this->legacyId((string) data_get($remoteLine, 'lineItem.id', ''));
                if (! array_key_exists($lineId, $requested) || $requested[$lineId] <= 0) {
                    continue;
                }
                $take = min(max(0, (int) ($remoteLine['remainingQuantity'] ?? 0)), $requested[$lineId]);
                if ($take <= 0) {
                    continue;
                }
                $items[] = ['id' => (string) $remoteLine['id'], 'quantity' => $take];
                $requested[$lineId] -= $take;
            }
            if ($items !== []) {
                $moveRequests[] = ['id' => (string) $fulfillmentOrder['id'], 'items' => $items];
            }
        }

        if ($moveRequests !== []) {
            if (($fulfillment['location_reassigned'] ?? false) === true) {
                throw new CommerceSyncException(__('commerce.sync.errors.shopify_fulfillment_move_incomplete'), false);
            }
            $mutation = <<<'GRAPHQL'
                mutation PosFulfillmentOrderMove($id: ID!, $newLocationId: ID!, $lineItems: [FulfillmentOrderLineItemInput!]) {
                  fulfillmentOrderMove(id: $id, newLocationId: $newLocationId, fulfillmentOrderLineItems: $lineItems) {
                    movedFulfillmentOrder { id status }
                    originalFulfillmentOrder { id status }
                    userErrors { field message }
                  }
                }
                GRAPHQL;
            foreach ($moveRequests as $move) {
                $this->graphql($connection, $mutation, [
                    'id' => $move['id'],
                    'newLocationId' => $locationId,
                    'lineItems' => $move['items'],
                ], 'fulfillmentOrderMove');
            }

            return $this->fulfillOrder($connection, [...$fulfillment, 'location_reassigned' => true]);
        }

        $unmatched = collect($found)->filter(fn (bool $wasFound) => ! $wasFound);
        if ($unmatched->isNotEmpty()) {
            throw new CommerceSyncException(__('commerce.sync.errors.shopify_fulfillment_lines_missing'), false);
        }
        if ($groups === []) {
            // The requested quantities have already been fulfilled remotely.
            // Treat this as idempotent success so a lost API response does not
            // turn into a permanent retry failure.
            return 'shopify:already-fulfilled';
        }

        $mutation = <<<'GRAPHQL'
            mutation PosFulfillmentCreate($fulfillment: FulfillmentInput!) {
              fulfillmentCreate(fulfillment: $fulfillment) {
                fulfillment { id status }
                userErrors { field message }
              }
            }
            GRAPHQL;
        $result = $this->graphql($connection, $mutation, [
            'fulfillment' => [
                'lineItemsByFulfillmentOrder' => $groups,
                'notifyCustomer' => true,
            ],
        ], 'fulfillmentCreate');
        $fulfillmentId = (string) data_get($result, 'fulfillmentCreate.fulfillment.id', '');
        if ($fulfillmentId === '') {
            throw new CommerceSyncException(__('commerce.sync.errors.shopify_incomplete_response'));
        }

        return $fulfillmentId;
    }

    public function prepareOrderWebhookPayload(CommerceConnection $connection, string $topic, array $payload): array
    {
        return $payload;
    }

    public function normalizeOrderWebhook(string $topic, array $payload): ?RemoteOrder
    {
        if (! str_starts_with(strtolower($topic), 'orders/')) {
            return null;
        }

        $financialStatus = strtolower((string) ($payload['financial_status'] ?? 'pending'));
        $fulfillmentStatus = strtolower((string) ($payload['fulfillment_status'] ?? 'unfulfilled'));
        $paid = in_array($financialStatus, ['paid', 'partially_paid'], true);
        $cancelled = filled($payload['cancelled_at'] ?? null) || str_ends_with(strtolower($topic), '/cancelled');
        $refundedByLine = $this->shopifyRefundedQuantities((array) ($payload['refunds'] ?? []));
        $refundedTotal = $this->shopifyRefundedTotal((array) ($payload['refunds'] ?? []));
        $lines = [];

        foreach ((array) ($payload['line_items'] ?? []) as $line) {
            $quantity = (string) ($line['quantity'] ?? '0');
            $fulfillable = (string) ($line['fulfillable_quantity'] ?? $quantity);
            $fulfilled = max(0, (float) $quantity - (float) $fulfillable);
            $tax = collect($line['tax_lines'] ?? [])->sum(fn ($row) => (float) ($row['price'] ?? 0));
            $discount = (string) ($line['total_discount'] ?? '0');
            $lineTotal = bcsub(bcmul((string) ($line['price'] ?? '0'), $quantity, 4), $discount, 4);

            $lines[] = new RemoteOrderLine(
                (string) ($line['id'] ?? hash('sha256', json_encode($line))),
                isset($line['product_id']) ? (string) $line['product_id'] : null,
                isset($line['variant_id']) ? (string) $line['variant_id'] : null,
                (string) ($line['name'] ?? $line['title'] ?? __('commerce.orders.unnamed_product')),
                filled($line['sku'] ?? null) ? (string) $line['sku'] : null,
                $quantity,
                (string) ($line['price'] ?? '0'),
                $discount,
                (string) $tax,
                $lineTotal,
                (string) $fulfilled,
                $financialStatus === 'refunded'
                    ? $quantity
                    : (string) ($refundedByLine[(string) ($line['id'] ?? '')] ?? '0'),
                ['properties' => $line['properties'] ?? []],
            );
        }

        return new RemoteOrder(
            (string) $payload['id'],
            isset($payload['name']) ? (string) $payload['name'] : (isset($payload['order_number']) ? (string) $payload['order_number'] : null),
            $cancelled ? 'cancelled' : 'open',
            $financialStatus,
            $fulfillmentStatus,
            strtoupper((string) ($payload['currency'] ?? 'USD')),
            (string) ($payload['subtotal_price'] ?? '0'),
            (string) ($payload['total_discounts'] ?? '0'),
            (string) ($payload['total_tax'] ?? '0'),
            (string) collect($payload['shipping_lines'] ?? [])->sum(fn ($row) => (float) ($row['price'] ?? 0)),
            (string) ($payload['total_price'] ?? '0'),
            $financialStatus === 'refunded' && bccomp($refundedTotal, '0', 4) <= 0
                ? (string) ($payload['total_price'] ?? '0')
                : $refundedTotal,
            isset($payload['customer']['id']) ? (string) $payload['customer']['id'] : null,
            filled($payload['payment_gateway_names'][0] ?? null) ? (string) $payload['payment_gateway_names'][0] : null,
            (array) ($payload['billing_address'] ?? []),
            (array) ($payload['shipping_address'] ?? []),
            (array) ($payload['customer'] ?? []),
            $lines,
            $this->date($payload['created_at'] ?? null),
            $paid ? $this->date($payload['processed_at'] ?? $payload['updated_at'] ?? null) : null,
            $this->date($payload['cancelled_at'] ?? null),
            $this->date($payload['updated_at'] ?? null),
            $payload,
            $paid,
            $cancelled,
        );
    }

    public function registerWebhooks(CommerceConnection $connection, string $callbackUrl): void
    {
        $query = <<<'GRAPHQL'
            query PosWebhookSubscriptions {
              webhookSubscriptions(first: 250) {
                nodes {
                  topic
                  uri
                }
              }
            }
            GRAPHQL;
        $data = $this->graphql($connection, $query, [], 'webhookSubscriptions');
        $existing = collect(data_get($data, 'webhookSubscriptions.nodes', []));

        foreach (['ORDERS_CREATE', 'ORDERS_UPDATED', 'ORDERS_PAID', 'ORDERS_CANCELLED', 'CUSTOMERS_CREATE', 'CUSTOMERS_UPDATE'] as $topic) {
            $registered = $existing->contains(fn ($webhook) => is_array($webhook)
                && ($webhook['topic'] ?? null) === $topic
                && rtrim((string) ($webhook['uri'] ?? ''), '/') === rtrim($callbackUrl, '/'));
            if ($registered) {
                continue;
            }

            $mutation = <<<'GRAPHQL'
                mutation PosWebhookCreate($topic: WebhookSubscriptionTopic!, $subscription: WebhookSubscriptionInput!) {
                  webhookSubscriptionCreate(topic: $topic, webhookSubscription: $subscription) {
                    webhookSubscription { id topic }
                    userErrors { field message }
                  }
                }
                GRAPHQL;
            $this->graphql($connection, $mutation, [
                'topic' => $topic,
                'subscription' => [
                    'uri' => $callbackUrl,
                    'format' => 'JSON',
                ],
            ], 'webhookSubscriptionCreate');
        }
    }

    /** @param list<array<string,mixed>> $refunds @return array<string,string> */
    private function shopifyRefundedQuantities(array $refunds): array
    {
        $totals = [];
        foreach ($refunds as $refund) {
            foreach ((array) ($refund['refund_line_items'] ?? []) as $refundLine) {
                $remoteLineId = (string) ($refundLine['line_item_id'] ?? data_get($refundLine, 'line_item.id', ''));
                if ($remoteLineId === '') {
                    continue;
                }
                $totals[$remoteLineId] = bcadd(
                    $totals[$remoteLineId] ?? '0',
                    (string) ($refundLine['quantity'] ?? '0'),
                    4,
                );
            }
        }

        return $totals;
    }

    /** @param list<array<string,mixed>> $refunds */
    private function shopifyRefundedTotal(array $refunds): string
    {
        $total = '0';
        foreach ($refunds as $refund) {
            $successfulTransactions = collect($refund['transactions'] ?? [])->filter(fn ($transaction) => is_array($transaction)
                && strtolower((string) ($transaction['kind'] ?? 'refund')) === 'refund'
                && in_array(strtolower((string) ($transaction['status'] ?? 'success')), ['success', 'succeeded'], true)
            );
            if ($successfulTransactions->isNotEmpty()) {
                foreach ($successfulTransactions as $transaction) {
                    $total = bcadd($total, (string) ($transaction['amount'] ?? '0'), 4);
                }

                continue;
            }
            foreach ((array) ($refund['refund_line_items'] ?? []) as $refundLine) {
                $total = bcadd($total, (string) ($refundLine['subtotal'] ?? '0'), 4);
                $total = bcadd($total, (string) ($refundLine['total_tax'] ?? '0'), 4);
            }
        }

        return $total;
    }

    public function normalizeCustomerWebhook(string $topic, array $payload): ?array
    {
        if (! str_starts_with(strtolower($topic), 'customers/') || empty($payload['id'])) {
            return null;
        }

        $address = (array) ($payload['default_address'] ?? []);

        return [
            'remote_id' => (string) $payload['id'],
            'first_name' => (string) ($payload['first_name'] ?? ''),
            'last_name' => (string) ($payload['last_name'] ?? ''),
            'email' => filled($payload['email'] ?? null) ? strtolower((string) $payload['email']) : null,
            'phone' => filled($payload['phone'] ?? null) ? (string) $payload['phone'] : null,
            'address' => [
                'line1' => $address['address1'] ?? null,
                'line2' => $address['address2'] ?? null,
                'city' => $address['city'] ?? null,
                'state' => $address['province_code'] ?? $address['province'] ?? null,
                'postal_code' => $address['zip'] ?? null,
                'country_code' => $address['country_code'] ?? null,
                'is_default' => true,
            ],
            'remote_updated_at' => $payload['updated_at'] ?? null,
            'payload' => $payload,
        ];
    }

    private function publishesToOnlineStore(CommerceConnection $connection): bool
    {
        return (bool) data_get($connection->settings, 'publish_online_store', false);
    }

    /** @return list<string> */
    private function missingOnlineStoreScopes(string $grantedScopes): array
    {
        $granted = collect(explode(',', $grantedScopes))
            ->map(fn (string $scope) => trim($scope))
            ->filter()
            ->all();

        // Shopify write scopes also grant the matching read access, even when
        // the token response omits the redundant read scope.
        foreach ($granted as $scope) {
            if (str_starts_with($scope, 'write_')) {
                $granted[] = 'read_'.substr($scope, 6);
            }
        }

        return array_values(array_diff(self::ONLINE_STOREFRONT_SCOPES, $granted));
    }

    /** @param list<array<string, mixed>> $publications */
    private function onlineStorePublicationIdFrom(array $publications): ?string
    {
        $publication = collect($publications)->first(function ($publication): bool {
            if (! is_array($publication)) {
                return false;
            }

            return collect(data_get($publication, 'channels.nodes', []))
                ->contains(fn ($channel) => is_array($channel) && ($channel['handle'] ?? null) === 'online_store');
        });

        return is_array($publication) && filled($publication['id'] ?? null)
            ? (string) $publication['id']
            : null;
    }

    private function onlineStorePublicationId(CommerceConnection $connection): string
    {
        $connectionKey = (string) ($connection->getKey() ?: $connection->shop_url);
        if (filled($this->onlineStorePublicationIds[$connectionKey] ?? null)) {
            return $this->onlineStorePublicationIds[$connectionKey];
        }

        $publicationId = trim((string) data_get(
            $connection->settings,
            'provider_metadata.online_store_publication_id',
            '',
        ));
        if ($publicationId === '') {
            $query = <<<'GRAPHQL'
                query PosOnlineStorePublication {
                  publications(first: 50, catalogType: APP) {
                    nodes {
                      id
                      channels(first: 10) { nodes { handle } }
                    }
                  }
                }
                GRAPHQL;
            $data = $this->graphql($connection, $query, [], 'publications');
            $publicationId = (string) ($this->onlineStorePublicationIdFrom(
                (array) data_get($data, 'publications.nodes', []),
            ) ?? '');
        }

        if ($publicationId === '') {
            throw new CommerceSyncException(__('commerce.sync.errors.shopify_online_store_publication_missing'), false);
        }

        $this->onlineStorePublicationIds[$connectionKey] = $publicationId;
        $settings = $connection->settings ?? [];
        data_set($settings, 'provider_metadata.online_store_publication_id', $publicationId);
        $connection->forceFill(['settings' => $settings]);
        if ($connection->exists) {
            $connection->saveQuietly();
        }

        return $publicationId;
    }

    /** @param list<string> $resourceIds */
    private function publishToOnlineStore(CommerceConnection $connection, array $resourceIds): void
    {
        $publicationId = $this->onlineStorePublicationId($connection);
        $mutation = <<<'GRAPHQL'
            mutation PosPublishOnlineStore($id: ID!, $publicationId: ID!) {
              publishablePublish(id: $id, input: [{ publicationId: $publicationId }]) {
                publishable { publishedOnPublication(publicationId: $publicationId) }
                userErrors { field message }
              }
            }
            GRAPHQL;

        foreach (array_values(array_unique(array_filter($resourceIds))) as $resourceId) {
            $cacheKey = implode('|', [
                (string) ($connection->getKey() ?: $connection->shop_url),
                $publicationId,
                $resourceId,
            ]);
            if (isset($this->publishedResources[$cacheKey])) {
                continue;
            }

            $data = $this->graphql($connection, $mutation, [
                'id' => $resourceId,
                'publicationId' => $publicationId,
            ], 'publishablePublish');
            if (data_get($data, 'publishablePublish.publishable.publishedOnPublication') !== true) {
                throw new CommerceSyncException(__('commerce.sync.errors.shopify_publication_not_applied'), false);
            }

            $this->publishedResources[$cacheKey] = true;
        }
    }

    /** @param list<string> $locationIds */
    private function enableOnlineFulfillment(CommerceConnection $connection, array $locationIds): void
    {
        $mutation = <<<'GRAPHQL'
            mutation PosEnableOnlineFulfillment($id: ID!, $input: LocationEditInput!) {
              locationEdit(id: $id, input: $input) {
                location { id fulfillsOnlineOrders }
                userErrors { field message code }
              }
            }
            GRAPHQL;
        $enabledLocationIds = [];

        foreach (array_values(array_unique(array_filter($locationIds))) as $locationId) {
            $cacheKey = (string) ($connection->getKey() ?: $connection->shop_url).'|'.$locationId;
            if (isset($this->onlineFulfillmentLocations[$cacheKey])) {
                continue;
            }

            $data = $this->graphql($connection, $mutation, [
                'id' => $locationId,
                'input' => ['fulfillsOnlineOrders' => true],
            ], 'locationEdit');
            if (data_get($data, 'locationEdit.location.fulfillsOnlineOrders') !== true) {
                throw new CommerceSyncException(__('commerce.sync.errors.shopify_online_fulfillment_not_applied', [
                    'location' => $locationId,
                ]), false);
            }

            $this->onlineFulfillmentLocations[$cacheKey] = true;
            $enabledLocationIds[] = $locationId;
        }

        if ($enabledLocationIds === []) {
            return;
        }

        $settings = $connection->settings ?? [];
        $settings['remote_locations'] = collect((array) ($settings['remote_locations'] ?? []))
            ->map(function ($location) use ($enabledLocationIds) {
                if (is_array($location) && in_array((string) ($location['id'] ?? ''), $enabledLocationIds, true)) {
                    $location['fulfills_online_orders'] = true;
                }

                return $location;
            })
            ->all();
        $connection->forceFill(['settings' => $settings]);
        if ($connection->exists) {
            $connection->saveQuietly();
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphql(CommerceConnection $connection, string $query, array $variables, string $operation): array
    {
        [$token, $credentialUpdates] = $this->accessToken($connection->shop_url, $connection->credentials ?? []);
        if ($credentialUpdates !== []) {
            $connection->forceFill([
                'credentials' => array_replace($connection->credentials ?? [], $credentialUpdates),
            ])->saveQuietly();
        }

        try {
            $payload = ['query' => $query];
            if ($variables !== []) {
                $payload['variables'] = $variables;
            }

            $response = Http::withHeaders(['X-Shopify-Access-Token' => $token])
                ->acceptJson()
                ->connectTimeout((int) config('commerce.connect_timeout', 5))
                ->timeout((int) config('commerce.timeout', 20))
                ->post(
                    rtrim($connection->shop_url, '/').'/admin/api/'.($connection->api_version ?: config('commerce.shopify.api_version')).'/graphql.json',
                    $payload,
                );
        } catch (ConnectionException) {
            throw new CommerceSyncException(__('commerce.sync.errors.shopify_unreachable'));
        }

        if (! $response->successful()) {
            throw new CommerceSyncException(
                __('commerce.sync.errors.shopify_http', ['status' => $response->status()]),
                $response->status() === 429 || $response->serverError(),
            );
        }

        $topError = data_get($response->json(), 'errors.0');
        $topMessage = data_get($topError, 'message');
        $topCode = strtoupper((string) data_get($topError, 'extensions.code', ''));
        $userError = data_get($response->json(), "data.{$operation}.userErrors.0");
        $userMessage = data_get($userError, 'message');
        $userCode = strtoupper((string) data_get($userError, 'code', ''));
        if (filled($topMessage) || filled($userMessage)) {
            $message = mb_substr((string) ($userMessage ?: $topMessage), 0, 500);
            if ($this->remoteEntityIsMissing($operation, $userCode)) {
                throw new CommerceRemoteEntityMissing($message, false);
            }

            throw new CommerceSyncException(
                $message,
                in_array($topCode, ['THROTTLED', 'INTERNAL_SERVER_ERROR'], true),
            );
        }

        return (array) $response->json('data', []);
    }

    private function remoteEntityIsMissing(string $operation, string $code): bool
    {
        return match ($operation) {
            'productSet' => in_array($code, [
                'NOT_FOUND',
                'PRODUCT_DOES_NOT_EXIST',
                'PRODUCT_VARIANT_DOES_NOT_EXIST',
            ], true),
            'inventoryBulkToggleActivation' => $code === 'INVENTORY_ITEM_NOT_FOUND',
            'inventorySetQuantities' => $code === 'INVALID_INVENTORY_ITEM',
            default => false,
        };
    }

    /**
     * Shopify normally returns the final quantity with the mutation. When it
     * omits that detail (including a no-op update), read the mapped inventory
     * level so an ignored update cannot be recorded as synchronized.
     *
     * @param  list<array{inventoryItemId:string,locationId:string,quantity:int}>  $quantities
     * @param  array<string, mixed>  $data
     */
    private function confirmInventoryQuantities(CommerceConnection $connection, array $quantities, array $data): void
    {
        $changes = collect(data_get($data, 'inventorySetQuantities.inventoryAdjustmentGroup.changes', []));
        $verificationQuery = <<<'GRAPHQL'
            query PosInventoryVerify($inventoryItemId: ID!, $locationId: ID!) {
              inventoryItem(id: $inventoryItemId) {
                id
                tracked
                inventoryLevel(locationId: $locationId, includeInactive: true) {
                  isActive
                  location { id }
                  quantities(names: ["available"]) { name quantity }
                }
              }
            }
            GRAPHQL;

        foreach ($quantities as $quantity) {
            $confirmedByMutation = $changes->contains(function ($change) use ($quantity): bool {
                return is_array($change)
                    && data_get($change, 'item.id') === $quantity['inventoryItemId']
                    && data_get($change, 'location.id') === $quantity['locationId']
                    && ($change['name'] ?? null) === 'available'
                    && (int) ($change['quantityAfterChange'] ?? -1) === $quantity['quantity'];
            });
            if ($confirmedByMutation) {
                continue;
            }

            $verification = $this->graphql($connection, $verificationQuery, [
                'inventoryItemId' => $quantity['inventoryItemId'],
                'locationId' => $quantity['locationId'],
            ], 'inventoryItem');
            $inventoryItem = (array) data_get($verification, 'inventoryItem', []);
            $level = (array) data_get($inventoryItem, 'inventoryLevel', []);
            $available = collect($level['quantities'] ?? [])->firstWhere('name', 'available');

            if (($inventoryItem['tracked'] ?? false) === true
                && ($level['isActive'] ?? false) === true
                && is_array($available)
                && (int) ($available['quantity'] ?? -1) === $quantity['quantity']) {
                continue;
            }

            throw new CommerceSyncException(__('commerce.sync.errors.shopify_inventory_not_applied', [
                'quantity' => $quantity['quantity'],
                'location' => $quantity['locationId'],
            ]));
        }
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse((string) $value) : null;
    }

    private function legacyId(string $gid): string
    {
        return str_contains($gid, '/') ? (string) last(explode('/', $gid)) : $gid;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function accessToken(string $url, array $credentials, bool $forceRefresh = false): array
    {
        $token = trim((string) ($credentials['access_token'] ?? ''));
        $expiresAt = $credentials['access_token_expires_at'] ?? null;
        $leeway = (int) config('commerce.shopify.token_refresh_leeway', 120);

        if (! $forceRefresh && $token !== '' && $expiresAt && CarbonImmutable::parse($expiresAt)->isAfter(now()->addSeconds($leeway))) {
            return [$token, []];
        }

        $response = Http::asForm()
            ->acceptJson()
            ->connectTimeout((int) config('commerce.connect_timeout', 5))
            ->timeout((int) config('commerce.timeout', 20))
            ->post("{$url}/admin/oauth/access_token", [
                'grant_type' => 'client_credentials',
                'client_id' => (string) $credentials['client_id'],
                'client_secret' => (string) $credentials['client_secret'],
            ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            $message = match ($response->status()) {
                400, 401, 403 => __('commerce.connection_test.shopify.credentials_rejected'),
                404 => __('commerce.connection_test.shopify.token_endpoint_not_found'),
                429 => __('commerce.connection_test.shopify.rate_limited'),
                default => __('commerce.connection_test.shopify.token_failed'),
            };

            throw new \RuntimeException($message);
        }

        $token = (string) $response->json('access_token');
        $expiresIn = max(300, (int) $response->json('expires_in', 86399));

        return [$token, [
            'access_token' => $token,
            'access_token_expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
            'granted_scopes' => $response->json('scope'),
        ]];
    }

    private function httpFailure(int $status, string $grantedScopes = ''): string
    {
        return match ($status) {
            401, 403 => trim($grantedScopes) !== ''
                ? __('commerce.connection_test.shopify.access_rejected_with_scopes', ['scopes' => $grantedScopes])
                : __('commerce.connection_test.shopify.access_rejected'),
            404 => __('commerce.connection_test.shopify.graphql_not_found'),
            429 => __('commerce.connection_test.shopify.rate_limited'),
            default => __('commerce.connection_test.shopify.http_error', ['status' => $status]),
        };
    }
}
