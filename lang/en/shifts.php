<?php

return [
    'title' => 'Shifts',
    'sub'   => 'Open a shift to start ringing up sales. Close it to reconcile the cash drawer.',

    'list' => [
        'title' => 'Shifts (:count)',
    ],

    'empty' => [
        'title' => 'No shifts yet',
        'sub'   => 'Open your first shift to start the cash drawer.',
    ],

    'columns' => [
        'number'      => 'Shift',
        'cashier'     => 'Cashier',
        'terminal'    => 'Terminal',
        'opened'      => 'Opened',
        'closed'      => 'Closed',
        'sales_total' => 'Sales',
        'variance'    => 'Variance',
        'status'      => 'Status',
    ],

    'statuses' => [
        'open'                 => 'Open',
        'closed'               => 'Closed',
        'closed_with_variance' => 'Closed with variance',
    ],

    'summary' => [
        'total'    => 'Total shifts',
        'open'     => 'Open',
        'variance' => 'With variance',
    ],

    'open' => [
        'title' => 'Open shift',
        'sub'   => 'Count the cash in your drawer and enter the total below.',
    ],

    'close' => [
        'title'        => 'Close shift',
        'force_banner' => 'You are force-closing :name\'s shift. Their name stays on the shift; you\'re recorded as the one who closed it.',
    ],

    'sections' => [
        'sales_summary'      => 'Sales summary',
        'payments_received'  => 'Payments received',
        'cash_drawer'        => 'Cash drawer',
        'x_report'           => 'X-Report (live)',
        'x_report_sub'       => 'Current shift totals — not closed yet.',
        'z_report'           => 'Z-Report',
        'z_report_sub'       => 'Frozen totals at close.',
        'details'            => 'Details',
    ],

    'totals' => [
        'sales_count'    => 'Sales count',
        'sales_total'    => 'Sales total',
        'tax_total'      => 'Tax',
        'discount_total' => 'Discounts',
        'refunds_count'  => 'Refunds count',
        'refunds_total'  => 'Refunds total',
        'opening_cash'   => 'Opening cash',
        'cash_sales'     => 'Cash sales',
        'cash_refunds'   => 'Cash refunds',
        'pay_ins'        => 'Pay-ins',
        'pay_outs'       => 'Pay-outs',
        'supplier_payments_heading' => 'Supplier payments',
        'supplier_unknown'          => 'Unknown supplier',
        'expected_cash'  => 'Expected cash',
        'counted_cash'   => 'Counted cash',
        'variance'       => 'Variance',
    ],

    'fields' => [
        'terminal'             => 'Terminal',
        'terminal_placeholder' => 'Choose a terminal…',
        'terminal_help'        => 'The till this shift runs on. A terminal can have only one open shift at a time.',
        'opening_cash'         => 'Counted opening cash',
        'opening_cash_help'    => 'How much cash is in the drawer right now. The cashier sees this on every receipt.',
        'closing_cash_counted' => 'Counted closing cash',
        'expected_cash'        => 'Expected cash',
        'variance'             => 'Variance',
        'variance_reason'      => 'Variance reason',
        'variance_notes'       => 'Variance notes',
        'notes'                => 'Notes',
        'cashier'              => 'Cashier',
        'store'                => 'Store',
        'opened'               => 'Opened',
        'closed'               => 'Closed',
        'force_closed_by'      => 'Force-closed by',
        'shift_id'             => 'Shift',
        'shift_id_value'       => 'Shift :n',
        'variance_reason_manage' => 'Manage variance reasons',
    ],

    'variance_reasons' => [
        'none'                  => '— select reason —',
        'miscount'              => 'Miscount',
        'theft_suspected'       => 'Theft suspected',
        'change_dispute'        => 'Change dispute',
        'unaccounted_pay_out'   => 'Unaccounted pay-out',
        'other'                 => 'Other',
    ],

    'actions' => [
        'open'          => 'Open shift',
        'start_shift'   => 'Start shift',
        'close_shift'   => 'Close shift',
        'cancel'        => 'Cancel',
        'view_active'   => 'View active shift',
        'view_x_report' => 'X-Report',
        'print'         => 'Print',
        'print_z_report' => 'Print Z-Report',
        'force_close'   => 'Force close',
    ],

    // Stale open-shift flag on the admin shifts list.
    'stale' => [
        'label'   => ':hours h open',
        'tooltip' => 'This shift has been open a long time — the cashier may have left without closing it. A manager can force-close it.',
    ],

    // Warning when a cashier with an open shift tries to log out.
    'logout_warning' => [
        'title'         => 'You still have an open shift',
        'message'       => 'Shift :number is still open. Logging out won\'t close it — close it first to reconcile your drawer, or a manager will have to force-close it later.',
        'logout_anyway' => 'Log out anyway',
    ],

    // Denomination helper (Slice C) — the drawer-counting grid.
    'denom' => [
        'toggle'    => 'Count by denomination',
        'total'     => 'Counted total',
        'use_total' => 'Use this total',
    ],

    'flash' => [
        'opened' => 'Shift :number opened.',
        'closed' => 'Shift :number closed.',
    ],

    'errors' => [
        'already_open'                => 'You already have an open shift (:number). Close it before opening a new one.',
        'not_open'                    => 'This shift isn\'t open.',
        'variance_reason_required'    => 'A variance reason is required when the cash variance is past the tolerance.',
        'no_store_selected'           => 'Pick a store first before opening a shift.',
        'shift_required'              => 'Open a shift before ringing up sales at this store.',
        'terminal_busy'               => 'Another cashier already has an open shift on this terminal. They must close it first.',
        'terminal_required'           => 'Select a terminal to open a shift.',
        'terminal_invalid'            => 'That terminal isn\'t available for this store.',
    ],

    // Cashier shift gate (Slice A) — the blocking overlay shown on the
    // cashier screen when the store enforces shifts and none is open.
    // Slice B adds the terminal-selection step.
    'gate' => [
        'title'         => 'Open your shift',
        'sub'           => 'Count the cash in your drawer to start selling.',
        'starting'      => 'Opening…',
        'sell_without'  => 'Sell without a shift',
        'terminal_title' => 'Choose your terminal',
        'terminal_sub'   => 'Pick the till this device is running before you start.',
        'terminal_label' => 'Terminal',
        'terminal_continue' => 'Continue',
        // Way out of the gate — it covers the whole screen and can't be dismissed.
        'leave'          => 'Back to dashboard',
    ],
];
