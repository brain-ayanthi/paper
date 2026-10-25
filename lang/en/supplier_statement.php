<?php

return [
    // Payables mirror of `customer_statement.php`. The sign convention is
    // inverted: a purchase CREDITS the supplier (we owe more), a payment
    // or return DEBITS them (we owe less).
    'kpis' => [
        'opening' => 'Opening balance',
        'credits' => 'Credits (purchases)',
        'debits'  => 'Debits (payments + returns)',
        'closing' => 'Closing balance',
    ],

    'columns' => [
        'date'      => 'Date',
        'type'      => 'Type',
        'reference' => 'Reference',
        'debit'     => 'Debit',
        'credit'    => 'Credit',
        'running'   => 'Balance',
    ],

    'types' => [
        'opening'     => 'Opening',
        'purchase'    => 'Purchase',
        'payment'     => 'Payment',
        'return'      => 'Return',
        // One payment allocated across several POs collapses to one line.
        'split'       => 'Across :count purchases',
        'unallocated' => 'On account',
    ],

    'empty' => [
        'title' => 'No activity in this period',
        'sub'   => 'Try widening the date range, or this supplier has had no transactions yet.',
    ],
];
