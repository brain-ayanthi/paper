<?php

return [
    'title'        => 'Brands',
    'sub'          => 'Manage the manufacturers and labels behind your products.',
    'crumb_parent' => 'Catalog',

    'actions' => [
        'new'             => 'New brand',
        'save'            => 'Save changes',
        'create'          => 'Create brand',
        'discard'         => 'Discard',
        'delete'          => 'Delete',
        'delete_confirm'  => 'Delete this brand? Any products under it will be moved to the default brand unless you pick another.',
        'close'           => 'Close',
        'export'          => 'Export',
        'export_csv'      => 'Download CSV',
        'export_xlsx'     => 'Download Excel (XLSX)',
        'import'          => 'Import',
        'bulk_logos'      => 'Bulk logos',
    ],

    // CSV / XLSX import wizard (mirrors categories + products).
    'import' => [
        'title'        => 'Import brands',
        'sub'          => 'Upload a CSV or Excel file to add or update brands in bulk. Logo files are uploaded separately in the next step.',
        'back'         => 'Back to brands',
        'cancel'       => 'Cancel',

        'sample_title' => 'Start from a sample file',
        'sample_sub'   => 'Download a ready-to-fill sample with the right columns and example rows. Replace the examples with your brands, then upload it below.',
        'sample_csv'   => 'Download sample (CSV)',
        'sample_xlsx'  => 'Download sample (Excel)',

        'guide_title'    => 'How to fill it in',
        'guide_required' => 'Required column: Name. Description, Logo and Active are optional.',
        'guide_identity' => 'Name identifies the brand: a name that already exists updates that brand, a new name creates one. Re-importing the same file is safe.',
        'guide_logo'     => 'Logo column: put the image FILENAME here (e.g. acme.png) — not the image itself. After importing, use “Bulk logos” to drop the actual files and they\'ll match automatically.',
        'guide_bool'     => 'Active accepts Yes or No. Blank counts as Yes.',
        'guide_extra'    => 'You can add, remove or reorder columns — the next step lets you map each column to a field before anything is saved.',

        'upload_label'  => 'Choose your file',
        'upload_hint'   => 'CSV or Excel (.xlsx), up to 5 MB.',
        'upload_button' => 'Upload and continue',

        'review_title'   => 'Review columns',
        'review_sub'     => 'Confirm which column maps to which field. Anything set to “Ignore” is left out.',
        'col_header'     => 'Your column',
        'col_maps_to'    => 'Maps to',
        'ignore_column'  => 'Ignore this column',
        'preview_table'  => 'Preview (first :count rows)',
        'no_data'        => 'No data rows found in the file.',
        'commit_button'  => 'Import brands',

        'fields' => [
            'name'        => 'Name',
            'description' => 'Description',
            'logo'        => 'Logo (filename or URL)',
            'logo_url'    => 'Logo URL (we download it)',
            'is_active'   => 'Active',
        ],

        'done_title'     => 'Import finished',
        'done_sub'       => ':created created · :updated updated · :skipped skipped',
        'done_back'      => 'Back to brands',
        'count_created'  => 'Created',
        'count_updated'  => 'Updated',
        'count_skipped'  => 'Skipped',
        'errors_heading' => 'Rows that were skipped',
        'error_row'      => 'Row :row',

        'logos_title'  => 'Now add the logos',
        'logos_sub'    => ':count brand(s) named a logo file. Drop that folder of images and we\'ll match each file to its brand.',
        'logos_button' => 'Upload logos',

        'errors' => [
            'file_required'   => 'Choose a CSV or Excel file to import.',
            'bad_type'        => 'That file type isn\'t supported. Upload a .csv or .xlsx file.',
            'unreadable'      => 'That file couldn\'t be read (:error). Check it opens in Excel, then try again.',
            'not_a_sheet'     => 'That doesn\'t look like a spreadsheet — no columns were found. Upload a .csv or .xlsx file.',
            'session_expired' => 'The uploaded file is no longer available. Please upload it again.',
            'name_required'   => 'Map one of your columns to “Name” — brands are matched by name.',
            'row_failed'      => 'Row :row could not be saved: :reason',
            'schema_outdated' => 'your database is missing a column this feature needs. Run the database update and try again.',
            'duplicate'       => 'a brand with this name already exists.',
            // Deliberately vague — the raw driver message leaked table and
            // index names to the importer (SEC #105). Detail goes to the log.
            'generic'         => 'the row could not be saved. Ask your administrator to check the application log.',
        ],
    ],

    // Bulk logo uploader — the second half of the import.
    'bulk_logos' => [
        'title'          => 'Bulk brand logos',
        // Sits above the tab strip, so it has to describe both routes.
        'sub'            => 'Attach logos to many brands at once — upload a folder of image files, or give us image links and we download them for you.',
        'back'           => 'Back to brands',
        'how_title'      => 'How it works',
        'how_1'          => 'In your brand import, fill the Logo column with each brand\'s image filename (e.g. acme.png). No need to rename your files.',
        'how_2'          => 'Drop your logo files below. We match each file to its brand by that filename, then you confirm.',
        'how_3'          => 'Logos upload in small batches, so it works on any hosting — you can leave the tab open while it runs.',
        'pending_hint'   => ':count brand(s) have declared a logo filename but no image yet.',
        'no_pending'     => 'No brands are currently waiting for a declared logo.',
        'drop_title'     => 'Drop logo files here',
        'drop_hint'      => 'or click to choose · JPG, PNG or WEBP · up to :size MB each',
        'matching'       => 'Matching filenames…',
        'summary'        => ':matched matched · :unmatched unmatched',
        'matched_title'  => 'Matched (:count)',
        'unmatched_title' => 'Unmatched (:count)',
        'reason_no_match' => 'No brand declared this filename',
        'reason_duplicate' => 'More than one brand claims this filename',
        'has_image_note' => 'already has a logo — will be replaced',
        'upload_button'  => 'Upload :count logo(s)',
        'uploading'      => 'Uploading… :done of :total',
        'done_summary'   => ':stored logo(s) attached.',
        'clear'          => 'Clear',
        'start_over'     => 'Upload more',
        'unmatched_download' => 'Download unmatched list',
        'nothing_matched' => 'None of these files matched a brand. Check that the filenames match your import\'s Logo column.',
        'error_generic'  => 'Some logos could not be uploaded. See the list above.',

        // Tab strip. "Files" is the original screen and stays the default.
        'tab_files' => 'Upload files',
        'tab_urls'  => 'Fetch from URLs',

        // Second tab — the server downloads logos from links instead.
        'urls' => [
            'title'     => 'Fetch logos from URLs',
            'how_title' => 'How it works',
            'how_1'     => 'Put the link in your import\'s Logo (or Logo URL) column — or paste brand names and URLs below.',
            'how_2'     => 'We check each link, download the image and attach it to the brand. Only JPG, PNG and WEBP over HTTPS.',
            'how_3'     => 'Logos are fetched a few at a time, so it works on any hosting. Keep this tab open while it runs.',

            'source_pending' => 'Waiting from import',
            'source_paste'   => 'Paste a list',

            'pending_hint' => ':count brand(s) have a logo URL waiting to be downloaded.',
            'no_pending'   => 'No brands are waiting for a logo download right now.',
            'load_pending' => 'Show them',

            'paste_label'       => 'One brand per line: the brand name, then the image URL',
            'paste_hint'        => 'Separate them with a comma or a tab — you can paste two columns straight from a spreadsheet.',
            'paste_placeholder' => "Coca-Cola, https://cdn.example.com/coca-cola.png\nPepsi, https://cdn.example.com/pepsi.png",
            'paste_button'      => 'Check these URLs',
            'paste_empty'       => 'Paste at least one brand name and URL first.',

            'matching'        => 'Checking URLs…',
            'summary'         => ':matched ready · :unmatched skipped',
            'matched_title'   => 'Ready to download (:count)',
            'unmatched_title' => 'Skipped (:count)',
            'nothing_matched' => 'Nothing to download. Check that the brand names match brands in your list.',
            'has_image_note'  => 'already has a logo — will be replaced',

            'fetch_button'       => 'Download :count logo(s)',
            'fetching'           => 'Downloading… :done of :total',
            'done_summary'       => ':fetched logo(s) attached.',
            'clear'              => 'Clear',
            'start_over'         => 'Start over',
            'failed_download'    => 'Download failed list',
            'unmatched_download' => 'Download skipped list',

            'reason_bad_format' => 'Line needs a brand name and a URL',
            'reason_bad_url'    => 'Not a http/https link',
            'reason_no_match'   => 'No brand with this name',
            'reason_duplicate'  => 'This brand is listed more than once',

            'err_blocked_url'        => 'Link refused — must be an https:// address on the public internet',
            'err_too_many_redirects' => 'Too many redirects',
            'err_unreachable'        => 'Could not connect',
            'err_http_error'         => 'The link did not return an image (404 or similar)',
            'err_too_large'          => 'Image is too large',
            'err_not_an_image'       => 'Not a JPG, PNG or WEBP image',
            'err_store_failed'       => 'Could not save the image',
            'err_not_found'          => 'No brand with this name',
            'err_generic'            => 'Could not download',

            'blocked_title'    => 'Nothing could be downloaded',
            'blocked_body'     => 'None of the first few links could be reached. Many shared hosts block outgoing connections, which would stop every download here. Ask your host to allow outgoing HTTPS, or upload the image files on the other tab instead.',
            'blocked_continue' => 'Try the rest anyway',
            'error_generic'    => 'Some logos could not be downloaded. See the list above.',
        ],
    ],

    'list' => [
        'title'        => 'All brands',
        'products'     => 'products',
        'empty_title'  => 'No brands yet',
        'empty_sub'    => 'Click “New brand” to add the first one.',
    ],

    'row' => [
        'toggle_active' => 'Toggle active',
    ],

    'editor' => [
        'empty_title' => 'Select a brand',
        'empty_sub'   => 'Pick a row to view its details, or click “New brand” to add one.',
    ],

    'drawer' => [
        'edit_title'    => 'Edit brand',
        'new_title'     => 'New brand',
        'edit_id'       => 'ID',
        'edit_updated'  => 'updated',
        'new_sub'       => 'Group products by manufacturer or label for cleaner reporting and filtering.',
        'name'          => 'Display name',
        'name_placeholder' => 'e.g. Coca-Cola',
        'logo'          => 'Logo',
        'description'   => 'Description',
        'description_placeholder' => 'Optional notes about this brand',
        'is_active'     => 'Active (visible in product picker)',
        'stats_products' => 'Products',
        'stats_revenue'  => 'Revenue · 30d',
    ],

    'errors' => [
        'title'                 => 'Please review the highlighted fields',
        'default_protected'     => '“:name” is the default brand and cannot be deleted.',
        'replacement_is_self'   => 'Cannot move products into the same brand being deleted.',
        'replacement_not_found' => 'The replacement brand could not be found.',
    ],

    'actions_extra' => [
        'saving'   => 'Saving…',
        'creating' => 'Creating…',
    ],

    'flash' => [
        'created'           => 'Created “:name”.',
        'updated'           => 'Updated “:name”.',
        'deleted'           => 'Deleted “:name”.',
        'deleted_with_move' => 'Deleted “:name” and moved :count product(s) to “:target”.',
    ],

    'delete_dialog' => [
        'title'            => 'Delete “:name”?',
        'no_products'      => 'This brand has no products. Deleting it is safe.',
        'has_products'     => ':count product(s) are linked to this brand. Pick where to move them.',
        'move_label'       => 'Move products to…',
        'move_placeholder' => 'Search brands',
        'default_hint'     => 'Defaults to “:name” if you leave this blank.',
        'confirm'          => 'Delete brand',
        'cancel'           => 'Cancel',
    ],
];
