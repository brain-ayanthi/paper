<?php

namespace App\Actions\Products;

use App\Exceptions\ProductHasSales;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * Soft-delete an explicit, bounded selection while preserving every safeguard
 * and extension point in {@see DeleteProduct}.
 *
 * Products referenced by sales are skipped rather than aborting the entire
 * run. That lets a mixed selection finish safely while keeping historical
 * sale lines intact.
 */
class BulkDeleteProducts
{
    public function __construct(private DeleteProduct $delete) {}

    /**
     * @param  Collection<int, Product>  $products
     * @return array{processed: int, deleted: int, blocked: int}
     */
    public function __invoke(Collection $products): array
    {
        $processed = 0;
        $deleted   = 0;
        $blocked   = 0;

        foreach ($products as $product) {
            $processed++;

            try {
                ($this->delete)($product);
                $deleted++;
            } catch (ProductHasSales) {
                $blocked++;
            }
        }

        do_action('product.bulk_deleted', $deleted, $blocked);

        return compact('processed', 'deleted', 'blocked');
    }
}
