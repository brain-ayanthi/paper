<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\ProductVariant;

/** Resolve one import row to an active stock-tracked product / variant. */
class ResolveInventoryImportProduct
{
    /**
     * @return array{product:?Product,variant:?ProductVariant,error:?string}
     */
    public function __invoke(?string $productSku, ?string $variantSku, ?string $barcode): array
    {
        $productSku = $this->clean($productSku);
        $variantSku = $this->clean($variantSku);
        $barcode = $this->clean($barcode);

        if ($productSku === null && $variantSku === null && $barcode === null) {
            return $this->failure(__('inventory.import_common.errors.identifier_required'));
        }

        $product = null;
        $variant = null;

        if ($productSku !== null) {
            $product = Product::query()
                ->where('sku', $productSku)
                ->where('is_active', true)
                ->first();

            if (! $product) {
                return $this->failure(__('inventory.import_common.errors.product_sku_unknown', ['sku' => $productSku]));
            }
        }

        if ($variantSku !== null) {
            $variant = ProductVariant::query()
                ->with('product')
                ->where('sku', $variantSku)
                ->where('is_active', true)
                ->whereHas('product', fn ($query) => $query->where('is_active', true))
                ->first();

            if (! $variant) {
                return $this->failure(__('inventory.import_common.errors.variant_sku_unknown', ['sku' => $variantSku]));
            }

            if ($product && $variant->product_id !== $product->id) {
                return $this->failure(__('inventory.import_common.errors.identifier_conflict'));
            }

            $product ??= $variant->product;
        }

        if ($barcode !== null) {
            $barcodeVariant = ProductVariant::query()
                ->with('product')
                ->where('barcode', $barcode)
                ->where('is_active', true)
                ->whereHas('product', fn ($query) => $query->where('is_active', true))
                ->first();
            $barcodeProduct = $barcodeVariant === null
                ? Product::query()->where('barcode', $barcode)->where('is_active', true)->first()
                : null;

            if (! $barcodeVariant && ! $barcodeProduct) {
                return $this->failure(__('inventory.import_common.errors.barcode_unknown', ['barcode' => $barcode]));
            }

            $resolvedProduct = $barcodeVariant?->product ?? $barcodeProduct;
            if ($product && $resolvedProduct?->id !== $product->id) {
                return $this->failure(__('inventory.import_common.errors.identifier_conflict'));
            }
            if ($variant && $barcodeVariant && $barcodeVariant->id !== $variant->id) {
                return $this->failure(__('inventory.import_common.errors.identifier_conflict'));
            }

            $product ??= $resolvedProduct;
            $variant ??= $barcodeVariant;
        }

        if (! $product) {
            return $this->failure(__('inventory.import_common.errors.identifier_required'));
        }

        if (! $product->track_stock) {
            return $this->failure(__('inventory.import_common.errors.stock_not_tracked', ['product' => $product->name]));
        }

        if ($product->type === 'variant' && ! $variant) {
            return $this->failure(__('inventory.import_common.errors.variant_required', ['product' => $product->name]));
        }

        return ['product' => $product, 'variant' => $variant, 'error' => null];
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array{product:null,variant:null,error:string} */
    private function failure(string $message): array
    {
        return ['product' => null, 'variant' => null, 'error' => $message];
    }
}
