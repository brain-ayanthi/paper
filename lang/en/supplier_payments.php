<?php

return [
    'title'         => 'Supplier payments',
    'sub'           => 'Payments and supplier credit applied against outstanding purchases.',

    'summary' => [
        'total'     => 'Total paid',
        'count'     => 'Entries',
        'suppliers' => 'Suppliers',
    ],
    'crumb_parent'  => 'Supply',
    'new'           => 'Record payment',
    'new_sub'       => 'Pay one or more purchases for a supplier. Allocate the amount across POs manually or with auto FIFO.',

    'list' => [
        'title'               => 'All payments (:count)',
        'unallocated_credit'  => 'Supplier credit',
    ],

    'credit_application' => [
        'method' => 'Supplier credit',
        'note'   => 'Supplier credit automatically applied to :number.',
    ],

    'badges' => [
        'voided' => 'Voided',
    ],

    'void' => [
        'action'        => 'Void payment',
        'title'         => 'Void details',
        'confirm_title' => 'Void this payment?',
        'confirm_body'  => 'Reverses the cash effect — the linked purchase reopens and the supplier outstanding goes back up. The row stays in history with a voided marker.',
        'voided_at'     => 'Voided at',
        'voided_by'     => 'Voided by',
        'reason'        => 'Reason',
    ],

    'columns' => [
        'date'      => 'Date',
        'supplier'  => 'Supplier',
        'purchase'  => 'Purchase',
        'method'    => 'Method',
        'reference' => 'Reference',
        'amount'    => 'Amount',
    ],

    'filter' => [
        'supplier_all' => 'All suppliers',
        'method_all'   => 'All methods',
        'from'         => 'From',
        'to'           => 'To',
        'search'       => 'Search reference, supplier, PO…',
    ],

    'empty_state' => [
        'title' => 'No payments yet',
        'sub'   => 'Record your first payment to a supplier.',
    ],

    'sections' => [
        'header'          => 'Payment details',
        'header_sub'      => 'Who, when, how — the payment metadata.',
        'allocations'     => 'Allocate to purchases',
        'allocations_sub' => 'Choose how much of this payment goes to each open purchase.',
        'total'           => 'Total',
        'notes'           => 'Notes',
    ],

    'fields' => [
        'supplier'        => 'Supplier',
        'store'           => 'Store',
        'date'            => 'Payment date',
        'method'          => 'Payment method',
        'supplier_due'    => ':amount due',
        'supplier_credit' => ':amount in credit',
        'reference'       => 'Reference',
        'reference_help'  => 'Cheque number, UPI transaction ID, bank reference, etc. Must be unique — it identifies one real payment.',
        'purchase'        => 'Purchase',
        'notes'           => 'Notes',
    ],

    'allocations' => [
        'pick_supplier'  => 'Pick a supplier first to see their open purchases.',
        'loading'        => 'Loading open purchases…',
        'none'           => 'This supplier has no open purchases. All POs are either fully paid or cancelled.',
        'auto_amount'    => 'Amount to allocate',
        'auto'           => 'Auto-allocate (FIFO)',
        'auto_help'      => 'Spreads the amount above across the oldest unpaid POs first — each PO is filled completely before moving to the next.',
        'auto_leftover'  => 'Amount left over (no more open POs to absorb it):',
        'clear'          => 'Clear all',
        'pay_in_full'    => 'Pay this PO in full',
        'columns' => [
            'number'   => 'Number',
            'date'     => 'Date',
            'balance'  => 'Balance due',
            'amount'   => 'Pay',
            'actions'  => 'Actions',
        ],
    ],

    'totals' => [
        'amount'      => 'Total payment',
        'help'        => 'Sum of the per-PO allocations on the left.',
        'credit'      => 'Unallocated credit',
        'credit_help' => 'Typed total exceeds per-PO allocations — the leftover lands as supplier credit (negative outstanding).',
    ],

    'actions' => [
        'discard'   => 'Discard',
        'record'    => 'Record payment',
        'recording' => 'Recording…',
    ],

    'flash' => [
        'created' => 'Payment recorded. Supplier balance and affected purchases updated.',
        'voided'  => 'Payment voided. Affected purchase reopened and supplier balance restored.',
    ],

    'errors' => [
        'reference_taken'          => 'That reference is already on another payment. Cheque numbers, UPI IDs and bank references identify one movement of money each — check whether this payment has already been recorded.',
        'credit_application_void'  => 'Automatic supplier-credit applications cannot be voided as cash payments.',
        'credit_already_applied'   => 'This supplier advance has already been used on a purchase and cannot be voided in full.',
    ],

    // Cash-drawer linkage — reasons stamped on the pay-out / reversing pay-in
    // when a cash supplier payment is made (or voided) during an open shift.
    'till' => [
        'pay_out_reason'      => 'Supplier payment · :supplier',
        'void_pay_in_reason'  => 'Voided supplier payment',
        'from_till_note'      => 'A cash payment while your shift is open is paid from the till — it reduces the drawer\'s expected cash.',
        'cash_available'      => 'This cash payment comes out of your open till, which holds',
        'over_drawer'         => 'That\'s more cash than your till holds. Reduce the amount, pay by another method, or record a pay-in first. Cash available:',
    ],
];
