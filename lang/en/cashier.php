<?php

return [
    'title' => 'Cashier',
    'exit'  => 'Exit to admin',

    'topbar' => [
        'store' => 'Store',
    ],

    'categories' => [
        'all' => 'All',
    ],

    'search' => [
        'placeholder' => 'Search products by name / SKU…',
        'hint'        => 'Press Enter to add',
        'searching'   => 'Searching…',
        'clear'       => 'Clear search',
    ],

    'scan' => [
        'placeholder'  => 'Scan barcode…',
        'shortcut'     => 'F8',
        'camera'       => 'Scan with camera',
        'camera_title' => 'Camera scanner',
        'camera_sub'   => 'Point the camera at a barcode to add the item.',
    ],

    'fullscreen' => [
        'enter' => 'Enter fullscreen',
        'exit'  => 'Exit fullscreen',
    ],

    'tile' => [
        'has_variants'    => 'Sizes',
        'options_suffix'  => 'opts',
        'from_prefix'     => 'from',
        'kit'             => 'KIT',
    ],

    'customer' => [
        'walk_in'            => 'Walk-in customer',
        'add'                => 'Add customer',
        'sub_default'        => 'Earn loyalty, attach to order',
        'attached'           => 'Attached to order',
        'change'             => 'Change customer',
        'pick_title'         => 'Pick customer',
        'pick_placeholder'   => 'Search by name, code, phone…',
        'pick_empty'         => 'No customers match — try a different query.',
        'add_new'            => 'Add new customer',
        'add_title'          => 'Quick-add customer',
        'add_name'           => 'Full name',
        'add_phone'          => 'Phone (optional)',
        'add_email'          => 'Email (optional)',
        'add_cancel'         => 'Cancel',
        'add_save'           => 'Save & assign',
        'reset'              => 'Reset to walk-in',
        'outstanding_short'  => 'due',
        'credit_short'       => 'credit',
    ],

    'overflow' => [
        'open'           => 'More',
        'clear_cart'     => 'Clear cart',
        'reprint_last'   => 'Reprint last receipt',
        'no_recent'      => 'No completed sales in this store yet.',
        'open_shift'     => 'Open shift',
        'close_shift'    => 'Close shift',
        'recents'        => 'Recent sales',
        'shortcut_help'  => 'Keyboard shortcuts',
        'soon_suffix'    => 'Coming soon',
        'refund'         => 'Refund / Return',
        'customer_display' => 'Open customer display',
    ],

    'recents' => [
        'drawer_title' => 'Recent sales',
        'drawer_sub'   => '20 most recent completed sales in this store.',
        'loading'      => 'Loading recent sales…',
        'empty'        => 'No completed sales yet — ring one up to see it here.',
        'view_receipt' => 'View receipt',
    ],

    'shortcuts' => [
        'title'    => 'Keyboard shortcuts',
        'search'   => 'Focus search',
        'customer' => 'Pick customer',
        'discount' => 'Discount',
        'hold'     => 'Hold order',
        'scan'     => 'Focus scan input',
        'checkout' => 'Checkout',
    ],

    'hold' => [
        'label'             => 'Hold',
        'prompt_title'      => 'Hold this order',
        'prompt_sub'        => 'Optional label — table number, customer name, anything you\'ll recognise.',
        'prompt_placeholder' => 'e.g. Table 5 · Mrs. Patel · Pickup order',
        'prompt_cancel'     => 'Cancel',
        'prompt_save'       => 'Hold order',
        'prompt_saving'     => 'Holding…',
    ],

    'held' => [
        'drawer_title' => 'Held orders',
        'drawer_sub'   => 'Resume to load back into the cart, or delete to discard.',
        'empty'        => 'No held orders right now.',
        'resume'       => 'Resume',
        'delete'       => 'Delete',
        'items'        => 'items',
        'no_label'     => 'No label',
        'overflow_open' => 'Held orders',
    ],

    'checkout' => [
        'label' => 'Checkout',
    ],

    'layout' => [
        'aria'          => 'Cashier layout',
        'beam'          => 'Beam — cart on the right',
        'beam_short'    => 'Beam',
        'lane'          => 'Lane — cart on the left',
        'lane_short'    => 'Lane',
        'counter'       => 'Counter — sidebar + dense list',
        'counter_short' => 'Counter',
    ],

    'listrow' => [
        'plu_header'     => 'PLU / SKU',
        'item_header'    => 'Item',
        'price_header'   => 'Price',
        'category_label' => 'Category',
        'add_aria'       => 'Add :name to cart',
        'items_suffix'   => 'items',
        'sort_name'      => 'Sort: Name',
        'show_all'       => 'Show: All',
    ],

    'stock' => [
        'low_label'     => 'Low:',
        'out'           => 'Out of stock',
        'not_available' => 'Not available',
        'not_listed'    => 'Stock not listed yet',
    ],

    'grid' => [
        'empty_title' => 'No matches',
        'empty_sub'   => 'Try a different search or category.',
        'load_more'   => 'Load more',
    ],

    'results' => [
        'on_hand' => 'On hand',
    ],

    'empty' => [
        'title' => 'No items yet',
        'sub'   => 'Scan a barcode or type to find a product.',
    ],

    'cart' => [
        'title'         => 'Cart',
        'items'         => 'items',
        'empty'         => 'Scan, search, or tap an item to begin ringing it up.',
        'empty_title'   => 'No items yet',
        'order_eyebrow' => 'Order',
        'draft'         => 'Draft',
        'new'           => 'New',
        'clear'         => 'Clear',
        'discount'      => 'Discount',
    ],

    'totals' => [
        'items'                => 'items',
        'units'                => 'units',
        'subtotal'             => 'Subtotal',
        'discount'             => 'Discount',
        'add_discount'         => 'Add discount',
        'tax'                  => 'Tax',
        'tax_at_checkout'      => 'calculated at checkout',
        'tax_incl'             => '(incl.)',
        'tax_already_included' => 'Tax (already included)',
        'tax_included_note'    => ':amount is already included in item prices.',
        'grand'                => 'Total',
        'grand_due'            => 'Total due',
    ],

    // Loyalty redemption in the payment modal (docs/features/customers.md §9.3).
    'loyalty' => [
        'title'            => 'Redeem points',
        'balance'          => ':points points available',
        'placeholder'      => 'Points to redeem (max :max)',
        'placeholder_aria' => 'Points to redeem',
        'max'              => 'Max',
        'apply'            => 'Apply',
        'applied'          => ':points points applied — :value off',
        'remove'           => 'Remove',
        'capped'           => 'This sale can take up to :max points.',
        'totals_row'       => 'Points redeemed (:points)',
    ],

    'store_credit' => [
        'title'             => 'Customer credit',
        'method_name'       => 'Customer Credit',
        'balance'           => ':amount available',
        'amount_aria'       => 'Customer credit amount to apply',
        'max'               => 'Max',
        'apply'             => 'Apply',
        'applied'           => ':amount customer credit applied',
        'remove'            => 'Remove',
        'enter_amount'      => 'Enter the customer credit amount to apply.',
        'online_required'   => 'Customer credit needs an online balance check.',
        'retry_online'      => 'The connection was lost. Reconnect and retry this sale so the customer credit balance can be verified.',
        'legacy_conversion' => 'Converted previous customer overpayment to store credit.',
        'sale_use'          => 'Used on sale :number.',
        'void_restore'      => 'Restored from voided sale :number.',
        'errors' => [
            'positive'          => 'Customer credit amount must not be negative.',
            'customer_required' => 'Attach a customer before applying customer credit.',
            'insufficient'      => 'The customer has only :available credit available.',
        ],
    ],

    'line' => [
        'each'             => 'each',
        'remove'           => 'Remove',
        'discount'         => 'Discount this line',
        'add_note'         => 'Add a note',
        'edit_note'        => 'Edit note',
        'note_title'       => 'Line note',
        'note_placeholder' => "e.g. customer wants no ice · gift wrap · ring up separately",
        'note_save'        => 'Save note',
        'over_stock'       => 'Only :available in stock — reduce qty to continue.',
        'tax'              => 'Tax',
    ],

    'discount' => [
        'title'       => 'Order-level discount',
        'percent'     => 'Percent',
        'amount'      => 'Amount',
        'percent_off' => 'Percent off',
        'amount_off'  => 'Amount off',
        'new_total'   => 'New total',
        'remove'      => 'Remove discount',
        'apply'       => 'Apply',
        'reason_category' => 'Reason',
        'reason_none' => '— No reason —',
        'reason'      => 'Note (optional)',
        'reason_ph'   => 'e.g. price match with competitor',
        'reason_categories' => [
            'loyalty'      => 'Loyalty / regular',
            'price_match'  => 'Price match',
            'damaged'      => 'Damaged / clearance',
            'staff'        => 'Staff purchase',
            'manager_comp' => 'Manager comp',
            'promo'        => 'Promotion',
            'other'        => 'Other',
        ],
    ],

    'line_discount' => [
        'title' => 'Line discount',
    ],

    'discount_approval' => [
        'title'       => 'Manager approval needed',
        'sub'         => 'This __PCT__% discount is above the store limit. A manager must approve it.',
        'manager'     => 'Manager email',
        'manager_ph'  => 'manager@store.com',
        'password'    => 'Manager password',
        'approve'     => 'Approve discount',
        'approving'   => 'Approving…',
    ],

    'variant' => [
        'pick_sub' => 'Pick a variant to add to the cart.',
        'in_stock' => 'in stock',
    ],

    'batch' => [
        'pick_sub'        => 'Pick a batch — earliest expiry first.',
        'batch_prefix'    => 'Batch',
        'exp_prefix'      => 'Exp',
        'mrp_prefix'      => 'MRP',
        'days_left'       => 'expires in :n days',
        'expires_today'   => 'expires today',
        'expired'         => 'expired',
        'empty_title'     => 'No live batches',
        'empty_sub'       => 'Receive stock with a batch number first.',
        'blocked_tooltip' => 'Expired — cannot sell (admin override required).',
        'change'          => 'Change batch',
        'change_hint'     => 'Sell a different batch of this item',
    ],

    'weight' => [
        'sub'        => 'Enter the weighed amount.',
        'label'      => 'Weight',
        'line_total' => 'Line total',
        'add'        => 'Add to cart',
    ],

    'actions' => [
        'clear' => 'Clear',
        'pay'   => 'Pay',
    ],

    'pay' => [
        'title'                 => 'Take payment',
        'method_label'          => 'Payment method',
        'method_sub' => [
            'cash'     => 'With change',
            'ref'      => 'With reference',
            'terminal' => 'Chip · Contactless',
        ],
        'cash_given'            => 'Cash given',
        'tendered'              => 'Amount tendered',
        'reference'             => 'Reference',
        'reference_placeholder' => 'Card last 4 / UPI txn / cheque #…',
        'ref_hint'              => 'Enter the transaction reference so it lands on the receipt.',
        'reference_required'    => 'Enter the payment reference for :method.',
        'upi_scan_title'        => 'Scan to pay with UPI',
        'upi_scan_hint'         => 'Customer scans this with any UPI app (GPay, PhonePe, Paytm, etc.). After they pay, type the UTR into the reference field below.',
        'change_due'            => 'Change due',
        'credit_balance'        => 'Goes on account',
        'put_on_account'        => 'Put on account',
        'cancel'                => 'Cancel',
        'complete'              => 'Complete sale',
        'completing'            => 'Completing…',
        'accept_cash'           => 'Accept cash',
        'take_payment'          => 'Take payment',
        'send_terminal'         => 'Send to terminal',
        'terminal_title'        => 'Insert, tap, or swipe',
        'terminal_sub'          => 'Waiting on the terminal · Amount sent',

        // Split tender
        'split_so_far'          => 'Paid so far',
        'add_payment'           => 'Add payment',
        'remove_payment_aria'   => 'Remove this payment',
        'amount_charged'        => 'Amount on this method',

        // Stripe gateway (Slice — Payments)
        'stripe_title'          => 'Stripe — scan to pay',
        'stripe_starting'       => 'Creating session…',
        'stripe_pending'        => 'Waiting for customer to pay',
        'stripe_paid'           => 'Paid — completing sale',
        'stripe_failed'         => 'Payment failed or cancelled',
        'stripe_idle'           => 'Ready',
        'stripe_open_link'      => 'Open payment link',
        'stripe_cancel'         => 'Cancel session',

        // QR-chooser flow — provider-agnostic
        'qr_tile_title'         => 'Charge via QR',
        'qr_tile_sub'           => 'Customer picks the gateway',
        'qr_tile_offline'       => 'Needs internet — unavailable offline',
        'qr_idle'               => 'Ready',
        'qr_starting'           => 'Creating session…',
        'qr_waiting'            => 'Waiting for customer to scan…',
        'qr_selected'           => 'Customer picked a method — waiting for payment',
        'qr_paid'               => 'Paid — completing sale',
        'qr_failed'             => 'Payment failed',
        'qr_expired'            => 'Session expired',
        'qr_cancelled'          => 'Session cancelled',
        'qr_instructions'       => 'Ask the customer to scan with their phone. They will choose how to pay — UPI, card, wallet, or any other configured method.',
        'qr_share_link'         => 'Or share this link',
        'qr_copy'               => 'Copy link',
        'qr_open'               => 'Open link',
        'qr_cancel'             => 'Cancel payment',
        'qr_expires_in'         => 'Expires in',
        'qr_dead_hint'          => 'This QR link is no longer active. Generate a new one to take the payment.',
        'qr_regenerate'         => 'Generate new QR',
    ],

    'success' => [
        'title'  => 'Sale complete',
        'change' => 'Change',
        'view'   => 'View receipt',
        'print'  => 'Print receipt',
        'new'    => 'New sale',
    ],

    'conn' => [
        'title'         => 'Connectivity',
        'status'        => 'Status',
        'last_synced'   => 'Last synced',
        'queue'         => 'Sync queue',
        'queue_empty'   => 'empty',
        'queue_pending' => 'pending',
        'refresh'       => 'Refresh data now',
        'refreshing'    => 'Refreshing…',
        'install'       => 'Install POS app',
        'update'        => 'Update now',
    ],

    // Per-entry sync queue panel inside the connectivity popover.
    'queue' => [
        'title'        => 'Waiting to sync',
        'items'        => 'items',
        'stuck_suffix' => 'need attention',
        'retry'        => 'Retry',
        'retrying'     => 'Retrying…',
        'discard'      => 'Discard',
        'discarded'    => 'Sale discarded.',
        'confirm_discard' => [
            'title'   => 'Discard this queued sale?',
            'message' => 'This sale never reached the server, and discarding it here deletes the only copy. Only do this if the sale was cancelled or has already been re-rung.',
        ],
    ],

    'refund' => [
        'lookup_title'       => 'Refund a sale',
        'lookup_sub'         => 'Scan the receipt or type the sale number — recent sales show by default.',
        'lookup_placeholder' => 'Sale number, customer name, or scan…',
        'lookup_empty'       => 'No sales match — recent completed sales appear here.',
        'partial_tag'        => 'Partial refund',

        'title'               => 'Process refund',
        'sub'                 => 'Pick the lines and quantities being returned. Defaults to a full refund.',
        'col_item'            => 'Item',
        'col_qty'             => 'Refund qty',
        'col_unit_price'      => 'Unit price',
        'col_line_total'      => 'Line total',
        'remaining_n'         => 'Up to :n returnable',
        'reason'              => 'Reason',
        'reason_placeholder'  => 'Pick a return reason…',
        'method'              => 'Refund to',
        'method_placeholder'  => 'Cash (default)',
        'restock'             => 'Restock these items',
        'notes'               => 'Notes',
        'notes_placeholder'   => 'Anything the next cashier should know about this refund…',
        'totals_subtotal'     => 'Subtotal',
        'totals_tax'          => 'Tax',
        'totals_grand'        => 'Refund total',
        'submit'              => 'Process refund',
        'submitting'          => 'Processing…',

        'success_title'       => 'Refund processed',
        'success_sale_prefix' => 'For sale',
        'success_done'        => 'Done',
    ],

    // Customer-facing display relay (SEC #47 / #48).
    'display' => [
        'no_terminal'        => 'Select a terminal before using the customer display.',
        'snapshot_too_large' => 'The cart is too large to mirror to the customer display.',
    ],

    // Server-priced static-UPI intent (SEC #78). The cashier falls back to a
    // locally-built QR when it can't reach the server, so these surface only
    // when the server WAS reached and still could not mint a link.
    'upi' => [
        'not_configured' => 'This payment method has no UPI address configured, so no QR can be shown.',
        'no_store'       => 'Select a store before taking a UPI payment.',
        'unpriceable'    => 'This cart could not be priced, so no UPI QR was generated. Refresh the page and try again.',
        'nothing_due'    => 'There is nothing left to pay on this sale.',
    ],
];
