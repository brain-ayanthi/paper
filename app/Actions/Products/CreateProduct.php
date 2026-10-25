<?php

namespace App\Actions\Products;

use App\Actions\Concerns\FreesSoftDeletedUnique;
use App\Events\ProductCreated;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Persist a new product.
 *
 * Extension points:
 *   - filter `product.attributes`     → modify the attribute array
 *   - action `product.before_create`  → side-effects before insert
 *   - action `product.after_create`   → side-effects after insert
 *   - event  ProductCreated           → decoupled listeners
 */
class CreateProduct
{
    use FreesSoftDeletedUnique;

    /**
     * How many times to re-generate after a duplicate-key collision. Two
     * admins saving in the same instant can be handed the same number;
     * a third collision on top of that isn't a race, it's a bug.
     */
    private const SKU_ATTEMPTS = 3;

    public function __construct(private readonly GenerateProductSku $generateSku) {}

    /** @param  array<string, mixed>  $data  Already-validated payload from ProductRequest. */
    public function __invoke(array $data): Product
    {
        $data = apply_filters('product.attributes', $data);
        pos_guard('product.create', $data);
        do_action('product.before_create', $data);

        $autoSku = trim((string) ($data['sku'] ?? '')) === '';

        for ($attempt = 1; ; $attempt++) {
            // Blank SKU → generate one. Done here rather than in the browser
            // so imports, the API, and two tabs at once all behave the same,
            // and so a generated value can never look like something the
            // admin typed and then went stale.
            if ($autoSku) {
                $data['sku'] = ($this->generateSku)();
            }

            // A deleted product's SKU is meant to be reusable (ProductRequest only
            // checks non-trashed rows), but the DB unique index still holds it —
            // reclaim it from any trashed row before inserting, or the insert 1062s.
            $this->freeSoftDeletedUnique(
                \App\Models\Product::class,
                ['sku' => $data['sku'] ?? null, 'barcode' => $data['barcode'] ?? null],
                ['sku', 'barcode'],
                prefixMax: 64,   // products keep a readable prefix (code-review #39)
            );

            try {
                $product = Product::create($data);
                break;
            } catch (UniqueConstraintViolationException $e) {
                // Only a generated SKU is worth retrying: a typed one that
                // collides is the admin's to fix, and the form already told
                // them it was free. Anything else (barcode) rethrows.
                if (! $autoSku || $attempt >= self::SKU_ATTEMPTS || ! $this->isSkuCollision((string) $data['sku'])) {
                    throw $e;
                }
            }
        }

        do_action('product.after_create', $product);
        event(new ProductCreated($product));

        return $product;
    }

    /**
     * Was the duplicate key the SKU, or something else unique on the row
     * (barcode)? Retrying a barcode collision would loop pointlessly and
     * hide a real conflict from the admin.
     */
    private function isSkuCollision(string $sku): bool
    {
        // Do not infer the violated column from driver-specific error text.
        // Re-check the generated value against the table instead: after a
        // concurrent INSERT wins, its SKU is visible here regardless of how
        // MySQL, PostgreSQL, or SQLite worded the unique-constraint message.
        // If the SKU is still free, another unique value (normally barcode)
        // caused the exception and retrying would only hide the real error.
        return Product::query()->withTrashed()->where('sku', $sku)->exists();
    }
}
