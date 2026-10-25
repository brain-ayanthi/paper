<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidBatchPrice extends RuntimeException
{
    public function __construct(string $productName, string $sellingPrice, string $mrp)
    {
        parent::__construct(__('inventory.errors.batch_mrp_below_selling_resolved', [
            'product' => $productName,
            'selling' => $sellingPrice,
            'mrp' => $mrp,
        ]));
    }
}
