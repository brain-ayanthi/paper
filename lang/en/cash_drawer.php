<?php

return [
    'panel' => [
        'title' => 'Cash drawer',
        'sub'   => 'Record cash added or removed mid-shift, or log a no-sale drawer opening.',
    ],

    'entries' => [
        'title' => 'Drawer movements',
        'sub'   => 'Every pay-in, pay-out, and no-sale drawer open against this shift.',
    ],

    'types' => [
        'pay_in'              => 'Pay-in',
        'pay_out'             => 'Pay-out',
        'pay_supplier'        => 'Pay supplier',
        'drawer_open_no_sale' => 'No-sale open',
    ],

    'categories' => [
        'expense'        => 'Expense (petty cash)',
        'bank_deposit'   => 'Bank deposit',
        'change_to_safe' => 'Moved to safe',
        'other'          => 'Other',
    ],

    'fields' => [
        'amount'                => 'Amount',
        'available'             => 'Cash in drawer',
        'reason'                => 'Reason',
        'category'              => 'What is this for?',
        'expense_category'      => 'Expense category',
        'expense_category_none' => 'Uncategorised',
        'reference'             => 'Reference',
    ],

    'help' => [
        'pay_in_amount'      => 'Cash being added to the drawer right now.',
        'pay_in_reason'      => 'e.g. "Float top-up", "Manager refill from safe".',
        'pay_out_amount'     => 'Cash being removed from the drawer right now.',
        'pay_out_available'  => 'The drawer holds :amount. A pay-out can\'t be more than that.',
        'pay_out_reason'     => 'e.g. "Petty expense — stationery", "Bank deposit".',
        'pay_out_category_expense' => 'An expense record is created automatically, so this shows up in Expenses and the P&L — not just the drawer.',
        'pay_out_category_other'   => 'The drawer is reduced, but no expense record is created.',
        'expense_category'   => 'Which account this is charged to. Leave blank if you\'re not sure — you can set it later from Expenses.',
        'reference'          => 'Optional bill or receipt number.',
        'drawer_open_reason' => 'e.g. "Customer needed change". Logged for audit; no money movement.',
        'pay_supplier'       => 'Pay a supplier against their unpaid invoices. A cash payment is taken from this till and reduces expected cash; other methods don\'t touch the drawer.',
    ],

    // Cash in/out modal on the cashier screen itself, so a drawer movement
    // doesn't require a trip to the back office.
    'till' => [
        'overflow_open'   => 'Cash in / out',
        'title'           => 'Cash in / out',
        'cash_out'        => 'Take cash out',
        'cash_in'         => 'Put cash in',
        'reason_required' => 'Say what this is for.',
    ],

    'actions' => [
        'record_pay_in'       => 'Record pay-in',
        'record_pay_out'      => 'Record pay-out',
        'record_drawer_open'  => 'Open drawer',
        'pay_supplier'        => 'Pay supplier',
        'take_all'            => 'Take all',
    ],

    'columns' => [
        'when'   => 'When',
        'type'   => 'Type',
        'reason' => 'Reason',
        'by'     => 'By',
        'amount' => 'Amount',
    ],

    'flash' => [
        'pay_in_recorded'          => 'Pay-in recorded.',
        'pay_out_recorded'         => 'Pay-out recorded.',
        'pay_out_expense_recorded' => 'Pay-out recorded and booked as expense :number.',
        'drawer_opened_no_sale'    => 'No-sale drawer open recorded.',
        'recorded'                 => 'Drawer entry recorded.',
    ],

    'errors' => [
        'amount_must_be_positive' => 'Amount must be greater than zero.',
        'insufficient_cash'       => 'The drawer only holds :available, so you can\'t take out :requested. Record a pay-in first if cash was added.',
        'over_drawer'             => 'That\'s more than the drawer holds.',
        'over_drawer_max'         => 'That\'s more than the drawer holds — the most you can take out right now is :available.',
        'no_cash_method'          => 'No active cash payment method is configured, so this pay-out can\'t be booked as an expense. Add one under Settings → Payment methods.',
    ],
];
