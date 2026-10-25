<?php

return [
    'title'        => 'Print labels',
    'sub'          => 'Generate a printable sheet of product labels with barcodes.',
    'crumb_parent' => 'Products',

    'products' => [
        'title'              => 'Products',
        'sub'                => 'Add the products to label and set how many of each.',
        'add'                => 'Add a product',
        'search_placeholder' => 'Search products by name, SKU, or barcode',
        'qty'                => 'Qty',
        'remove'             => 'Remove',
        'empty'              => 'No products added yet. Search above to add one.',
    ],

    'options' => [
        'title'         => 'Sheet options',
        'layout'        => 'Paper / label size',
        'layout_help'   => 'Pick an A4 sheet for a laser or inkjet printer, or your roll size for a thermal label printer.',
        'group_sheet'   => 'A4 sheets (laser / inkjet)',
        'group_roll'    => 'Label rolls (thermal)',
        'fields'        => 'Show on each label',
        'field_name'    => 'Product name',
        'field_sku'     => 'SKU',
        'field_price'   => 'Price',
        'field_barcode' => 'Barcode',
        'field_location' => 'Shelf location',
        'summary'       => ':count labels',
        'generate'      => 'Generate sheet',
        'generate_help' => 'Opens a print-ready page in a new tab.',
    ],

    'sheet' => [
        'title' => 'Product labels',
        'back'  => 'Back',
        'print' => 'Print',
        'count' => ':count labels',
        'empty' => 'No labels to print.',
    ],
];
