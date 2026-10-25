<?php

return [
    'title'        => 'Stock Reconciliation',
    'sub'          => 'Compare physical stock with recorded inventory and keep your stock accurate.',
    'scan' => [
        // Take counts an existing snapshot row rather than adding one.
        'placeholder'    => 'Scan a barcode to count…',
        'empty'          => 'No barcode was provided.',
        'not_found'      => 'No product found for barcode :barcode.',
        'not_in_take'    => ':name isn\'t part of this stock take.',
        'counted'        => ':name — counted :count',
        'locate_prompt'  => ':name — type the counted quantity',
        'mode_label'     => 'On scan',
        'mode_locate'    => 'Enter count',
        'mode_increment' => 'Add 1 each',
    ],
    'crumb_parent' => 'Inventory',
    'list_title'   => 'Stock takes',

    'summary' => [
        'count'  => 'Stock takes',
        'posted' => 'Posted',
        'draft'  => 'Draft',
    ],
    'new'          => 'Start a stock take',
    'edit_title'   => 'Editing :number',

    'create_sub' => 'Pick the store and start counting — every product with a stock level row is added to the count sheet automatically.',

    'import' => [
        'title' => 'Import stock counts',
        'sub' => 'Upload actual counted quantities in bulk. A stock-take draft is created for review and is never posted automatically.',
        'back' => 'Back to stock takes',
        'sample_title' => 'Download a sample file',
        'sample_sub' => 'Use Product SKU, Variant SKU, or Barcode and enter the actual quantity counted.',
        'sample_csv' => 'Sample CSV',
        'sample_xlsx' => 'Sample Excel',
        'sample_front_note' => 'Counted on front shelf',
        'sample_back_note' => 'Back room included',
        'guide_title' => 'How this import works',
        'guide_count' => 'Counted Quantity means the final physical quantity, not an amount to add or remove.',
        'guide_document' => 'One upload creates one stock-take draft for the selected store and date.',
        'guide_unlisted' => 'Products not listed in the file remain skipped in the draft; they are not treated as zero.',
        'guide_levels' => 'A product must already appear in the selected store’s stock list. Use a stock adjustment to add first-time stock.',
        'guide_draft' => 'Stock changes only when somebody reviews and posts the stock-take draft.',
        'guide_batch' => 'Batch-tracked products are skipped because stock takes currently count only product and variant totals. Use a batch-aware stock adjustment for those products.',
        'upload_label' => 'Choose stock-count file',
        'upload_hint' => 'CSV or XLSX up to 5 MB.',
        'upload_button' => 'Upload and map columns',
        'cancel' => 'Cancel',
        'review_title' => 'Map stock-count columns',
        'review_sub' => 'Confirm how the spreadsheet columns map before creating the stock-take draft.',
        'col_header' => 'Column header',
        'col_maps_to' => 'Maps to',
        'ignore_column' => '— Ignore this column —',
        'unnamed_column' => 'Column :number',
        'preview_table' => 'File preview (first :count rows)',
        'no_data' => 'No data rows were found in the file.',
        'commit_button' => 'Create stock-take draft',
        'done_title' => 'Stock-count import complete',
        'done_sub' => ':imported counts added and :skipped rows skipped. No stock has been posted.',
        'count_imported' => 'Counts added',
        'count_skipped' => 'Rows skipped',
        'errors_heading' => 'Rows needing correction',
        'error_row' => 'Row :row',
        'open_draft' => 'Review stock-take draft',
        'no_draft' => 'No draft was created because the file contained no valid stock counts.',
        'import_again' => 'Import another file',
        'fields' => [
            'product_sku' => 'Product SKU',
            'variant_sku' => 'Variant SKU',
            'barcode' => 'Barcode',
            'counted_quantity' => 'Counted quantity',
            'notes' => 'Line notes',
        ],
        'errors' => [
            'file_required' => 'Choose a CSV or Excel file to import.',
            'bad_type' => 'Only CSV and Excel (XLSX) files are supported.',
            'unreadable' => 'The file could not be read (:error). Export a fresh copy and try again.',
            'not_a_sheet' => 'The file does not contain a usable header row. Download the sample template and try again.',
            'session_expired' => 'The staged upload expired. Choose the file again.',
            'invalid_staged_path' => 'The staged upload path is invalid. Choose the file again.',
            'count_mapping_required' => 'Map the Counted Quantity column.',
            'batch_tracked' => ':product tracks batches and cannot be counted safely in this file. Use a batch-aware stock adjustment.',
            'not_in_stock_list' => ':product is not yet in the selected store’s stock list. Add its first stock through an adjustment.',
            'duplicate_line' => 'This product and variant already appears on row :row.',
        ],
    ],

    'columns' => [
        'number'  => 'Number',
        'name'    => 'Name',
        'date'    => 'Date',
        'store'   => 'Store',
        'items'   => 'Items',
        'status'  => 'Status',
        'by'      => 'By',
        'actions' => 'Actions',
    ],

    'filter' => [
        'store_all'  => 'All stores',
        'status_all' => 'All statuses',
        'date_from'  => 'From',
        'date_to'    => 'To',
        'search'     => 'Search number or name…',
    ],

    'status' => [
        'draft'     => 'Draft',
        'posted'    => 'Posted',
        'cancelled' => 'Cancelled',
    ],

    'fields' => [
        'store'              => 'Store',
        'store_help'         => 'Only one store per take. Counts at multiple stores need separate takes.',
        'take_date'          => 'Count date',
        'name'               => 'Name',
        'name_help'          => 'Optional label — useful when running monthly or department-scoped counts.',
        'name_placeholder'   => 'e.g. Monthly cycle — Aisle 3',
        'notes'              => 'Notes',
        'notes_placeholder'  => 'Anything the next person reviewing this should know…',
    ],

    'kpis' => [
        'lines_total'         => 'Lines',
        'lines_counted'       => 'Counted',
        'lines_with_variance' => 'With variance',
        'net_variance'        => 'Net variance',
    ],

    'items_title' => 'Count sheet',

    'items' => [
        'product'           => 'Product',
        'location'          => 'Location',
        'location_all'      => 'Everywhere',
        'location_none'     => 'No location set',
        'expected'          => 'Expected',
        'counted'           => 'Counted',
        'variance'          => 'Variance',
        'notes'             => 'Notes',
        'notes_placeholder' => 'e.g. found one damaged',
        'search_placeholder' => 'Search product, SKU, or location…',
    ],

    // Printable count sheet — the paper an operator carries round the shop.
    'sheet' => [
        'title'         => 'Count sheet :number',
        'print'         => 'Print',
        'back'          => 'Back to count',
        'open'          => 'Print count sheet',
        'blind'         => 'Hide expected (blind count)',
        'show_expected' => 'Show expected quantities',
        'counted_by'    => 'Counted by',
        'date'          => 'Date',
        'unplaced'      => 'No location set',
        'line_count'    => ':count lines',
        'total_lines'   => ':count lines in total',
    ],

    'empty_state' => [
        'title' => 'No stock takes yet',
        'sub'   => 'Start a new count to capture variance against your current stock levels.',
    ],
    'empty_lines' => [
        'title' => 'Nothing to count',
        'sub'   => 'This store has no products with stock levels to count. Receive stock or create a manual adjustment first.',
    ],

    'actions' => [
        'import'         => 'Import counts',
        'export'         => 'Export',
        'export_csv'     => 'CSV (.csv)',
        'export_xlsx'    => 'Excel (.xlsx)',
        'edit'           => 'Edit',
        'view'           => 'View',
        'delete'         => 'Delete draft',
        'cancel'         => 'Cancel',
        'start_count'    => 'Start count',
        'save_draft'     => 'Save progress',
        'post'           => 'Post count',
        'continue_count' => 'Continue count',
    ],

    'confirm_delete' => [
        'title'   => 'Delete stock take :number?',
        'message' => 'The draft and every counted line will be removed. Nothing has been written to the ledger yet, so no stock levels will change.',
        'confirm' => 'Delete draft',
    ],

    'confirm_post' => [
        'title'   => 'Post stock take :number?',
        'message' => 'Every counted line with a variance will write to the ledger and update stock levels. Posted takes cannot be edited — corrections go through a new adjustment.',
    ],

    'flash' => [
        'created'     => 'Stock take :number started — start filling in the counted column.',
        'updated'     => 'Stock take :number saved.',
        'posted'      => 'Stock take :number posted. Variance lines are now in the ledger.',
        'post_failed' => 'Couldn\'t post: :error',
        'deleted'     => 'Stock take :number deleted.',
    ],

    'errors' => [
        'store_required'    => 'Pick a store before starting the count.',
        'date_required'     => 'Pick a count date.',
        'date_future'       => 'The count date can\'t be in the future — a stock take records what is on the shelf today or earlier.',
        'count_too_large'   => 'A counted quantity is too large — that looks like a scanned barcode, not a count. Clear it and enter the real quantity.',
        'count_not_numeric' => 'Counted quantity must be a number.',
        'count_negative'    => 'Counted quantity can\'t be negative.',
    ],

    'posted_meta' => 'Posted on :when by :who.',
];
