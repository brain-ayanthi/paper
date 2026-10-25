<?php

namespace App\Actions\Commerce;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CommerceConnection;
use App\Models\CommerceEntityMapping;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Commerce\CommerceProviderRegistry;
use App\Services\Commerce\Exceptions\CommerceSyncSkipped;
use Illuminate\Support\Str;

final readonly class SyncCommerceProduct
{
    public function __construct(
        private CommerceProviderRegistry $providers,
        private PersistCommerceSyncResult $persist,
        private QueueCommerceProductInventory $inventory,
    ) {}

    public function execute(CommerceConnection $connection, int $productId, bool $force = false): void
    {
        $product = Product::query()
            ->withTrashed()
            ->with(['variants' => fn ($query) => $query->withTrashed(), 'brand', 'category'])
            ->find($productId);

        if (! $product) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.product_missing'));
        }
        if ($product->isKitType()) {
            throw new CommerceSyncSkipped(__('commerce.sync.skipped.kit_not_supported'));
        }

        $categoryPath = $this->categoryPath($product->category);
        $categoryIds = collect($categoryPath)->pluck('local_id')->all();
        $brandId = $product->brand?->getKey();

        $mappings = CommerceEntityMapping::query()
            ->where('commerce_connection_id', $connection->getKey())
            ->whereIn('entity_type', [
                CommerceEntityMapping::TYPE_PRODUCT,
                CommerceEntityMapping::TYPE_VARIANT,
                CommerceEntityMapping::TYPE_CATEGORY,
                CommerceEntityMapping::TYPE_BRAND,
            ])
            ->where(function ($query) use ($product, $categoryIds, $brandId): void {
                $query
                    ->where(fn ($local) => $local->where('local_type', Product::class)->where('local_id', $product->getKey()))
                    ->orWhere(fn ($local) => $local->where('local_type', ProductVariant::class)->whereIn('local_id', $product->variants->modelKeys()));

                if ($categoryIds !== []) {
                    $query->orWhere(fn ($local) => $local->where('local_type', Category::class)->whereIn('local_id', $categoryIds));
                }
                if ($brandId) {
                    $query->orWhere(fn ($local) => $local->where('local_type', Brand::class)->where('local_id', $brandId));
                }
            })
            ->get()
            ->keyBy(fn (CommerceEntityMapping $mapping) => $mapping->entity_type.'|'.$mapping->local_type.'|'.$mapping->local_id);

        $parentMapping = $mappings->get(CommerceEntityMapping::TYPE_PRODUCT.'|'.Product::class.'|'.$product->getKey());
        if ($product->trashed()) {
            if ($parentMapping) {
                $this->providers->get($connection->provider)->archiveProduct(
                    $connection,
                    $parentMapping->remote_id,
                );
            }

            return;
        }

        $options = $product->isVariantType() ? $product->variantAttributes() : [];
        $variants = $product->isVariantType()
            ? $product->variants->map(function (ProductVariant $variant) use ($mappings, $options, $product): array {
                $mapping = $mappings->get(CommerceEntityMapping::TYPE_VARIANT.'|'.ProductVariant::class.'|'.$variant->getKey());
                $attributes = (array) $variant->getAttribute('attributes');
                $optionValues = collect($options)->map(function (array $option) use ($attributes): array {
                    $name = (string) ($option['name'] ?? 'Option');

                    return ['name' => $name, 'value' => (string) ($attributes[$name] ?? '')];
                })->all();

                return [
                    'local_type' => ProductVariant::class,
                    'local_id' => $variant->getKey(),
                    'remote_id' => $mapping?->remote_id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'price' => $variant->effective_selling_price,
                    'compare_at_price' => $variant->mrp ?: $product->mrp,
                    'active' => $product->is_active && $variant->is_active && ! $variant->trashed(),
                    'option_values' => $optionValues,
                ];
            })->values()->all()
            : [];
        $simpleVariantMapping = $mappings->get(CommerceEntityMapping::TYPE_VARIANT.'|'.Product::class.'|'.$product->getKey());

        $category = $categoryPath === [] ? null : [
            'name' => (string) end($categoryPath)['name'],
            'breadcrumb' => collect($categoryPath)->pluck('name')->implode(' > '),
            'path' => $categoryPath,
        ];
        $brand = $product->brand ? [
            'local_type' => Brand::class,
            'local_id' => (int) $product->brand->getKey(),
            'name' => (string) $product->brand->name,
            'slug' => 'pos-brand-'.$product->brand->getKey().'-'.Str::slug($product->brand->slug ?: $product->brand->name),
            'description' => (string) ($product->brand->description ?? ''),
        ] : null;

        $payload = [
            'local_type' => Product::class,
            'local_id' => $product->getKey(),
            'name' => $product->name,
            'handle' => 'pos-'.$product->getKey().'-'.Str::slug($product->slug ?: $product->name),
            'type' => $product->type,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'description' => $product->description,
            'short_description' => $product->short_description,
            'price' => $product->selling_price,
            'compare_at_price' => $product->mrp,
            'vendor' => $product->brand?->name,
            'category' => $category,
            'brand' => $brand,
            'image' => $this->imagePayload($product->image_path, $product->name),
            'featured' => $product->is_featured,
            'active' => $product->is_active && ! $product->trashed(),
            'track_stock' => $product->track_stock,
            'options' => $options,
            'variants' => $variants,
            'remote_variant_id' => $simpleVariantMapping?->remote_id,
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if (! $force && $parentMapping?->local_hash === $hash) {
            return;
        }

        $payload['remote_metadata'] = (array) ($parentMapping?->metadata ?? []);
        if (is_array($payload['category'])) {
            $payload['category']['path'] = collect($payload['category']['path'])->map(function (array $category) use ($mappings): array {
                $mapping = $mappings->get(CommerceEntityMapping::TYPE_CATEGORY.'|'.Category::class.'|'.$category['local_id']);

                return $category + [
                    'remote_id' => $mapping?->remote_id,
                    'remote_metadata' => (array) ($mapping?->metadata ?? []),
                ];
            })->all();
        }
        if (is_array($payload['brand'])) {
            $mapping = $mappings->get(CommerceEntityMapping::TYPE_BRAND.'|'.Brand::class.'|'.$payload['brand']['local_id']);
            $payload['brand'] += [
                'remote_id' => $mapping?->remote_id,
                'remote_metadata' => (array) ($mapping?->metadata ?? []),
            ];
        }

        $result = $this->providers->get($connection->provider)->upsertProduct(
            $connection,
            $payload,
            $parentMapping?->remote_id,
        );
        $this->persist->execute($connection, $result, $hash);
        $this->inventory->execute($connection, $product);
    }

    /** @return list<array<string, mixed>> */
    private function categoryPath(?Category $category): array
    {
        if (! $category) {
            return [];
        }

        $path = [];
        $seen = [];
        $cursor = $category;

        while ($cursor && ! isset($seen[$cursor->getKey()])) {
            $seen[$cursor->getKey()] = true;
            $path[] = [
                'local_type' => Category::class,
                'local_id' => (int) $cursor->getKey(),
                'name' => (string) $cursor->name,
                'slug' => 'pos-category-'.$cursor->getKey().'-'.Str::slug($cursor->slug ?: $cursor->name),
                'description' => (string) ($cursor->description ?? ''),
                'sort_order' => (int) ($cursor->sort_order ?? 0),
            ];
            $cursor = $cursor->parent_id
                ? Category::query()->withTrashed()->find($cursor->parent_id)
                : null;
        }

        return array_reverse($path);
    }

    /** @return array{url:string,filename:string,alt:string,hash:string}|null */
    private function imagePayload(?string $path, string $alt): ?array
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        return [
            'url' => url('/storage/'.ltrim($path, '/')),
            'filename' => basename(str_replace('\\', '/', $path)),
            'alt' => $alt,
            'hash' => hash('sha256', $path),
        ];
    }
}
