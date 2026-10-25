<?php

return [
    'title'         => 'Suppliers',
    'sub'           => 'The people and businesses you buy from — identity, contact, tax, payables.',
    'crumb_parent'  => 'Supply',
    'new'           => 'New supplier',

    'columns' => [
        'code'           => 'Code',
        'name'           => 'Name',
        'contact_person' => 'Contact person',
        'phone'          => 'Phone',
        'city'           => 'City',
        'outstanding'    => 'Outstanding',
        'status'         => 'Status',
        'actions'        => 'Actions',
    ],

    'summary' => [
        'total'   => 'Suppliers',
        'payable' => 'Total payable',
        'active'  => 'Active',
    ],

    'list' => [
        'title' => 'All suppliers (:count)',
        'of'    => 'of',    // "12 of 340" — matched count of unfiltered total
    ],

    'badges' => [
        'active'   => 'Active',
        'inactive' => 'Inactive',
    ],

    'filter' => [
        'status_active'   => 'Active',
        'status_inactive' => 'Inactive',
        'status_all'      => 'All',
        'search'          => 'Search name, code, contact, phone, email…',
    ],

    'empty_state' => [
        'title' => 'No suppliers yet',
        'sub'   => 'Add your first supplier to start tracking purchases and payables.',
    ],

    'sections' => [
        'profile'      => 'Profile',
        'profile_sub'  => 'Identity and contact details.',
        'tax'          => 'Tax & registration',
        'tax_sub'      => 'Tax identifiers used on purchase invoices.',
        'address'      => 'Primary address',
        'address_sub'  => 'Used on purchase orders and statements.',
        'terms'        => 'Terms',
        'terms_sub'    => 'Default currency and payment window.',
        'notes'        => 'Notes',
        'notes_sub'    => 'Internal notes, visible to staff only.',
    ],

    'fields' => [
        'name'                     => 'Name',
        'code'                     => 'Code',
        'code_help'                => 'Leave blank to auto-generate (S-000123).',
        'business_name'            => 'Business name',
        'contact_person'           => 'Contact person',
        'phone'                    => 'Phone',
        'email'                    => 'Email',
        'gstin'                    => 'GSTIN',
        'gstin_placeholder'        => '15 characters',
        'pan'                      => 'PAN',
        'pan_placeholder'          => '10 characters',
        'tax_reg'                  => 'Tax registration number',
        'default_currency'         => 'Default currency',
        'default_currency_help'    => 'Currency used on this supplier’s invoices. Defaults to the company base currency.',
        'payment_terms_days'       => 'Payment terms (days)',
        'payment_terms_days_help'  => 'Net days — e.g. 30 = Net 30. Used to derive due dates on new purchases.',
        'days'                     => 'days',
        'address_line1'            => 'Address line 1',
        'address_line2'            => 'Address line 2',
        'city'                     => 'City',
        'state'                    => 'State',
        'postal_code'              => 'Postal code',
        'country'                  => 'Country',
        'notes'                    => 'Notes',
        'is_active'                => 'Active',
    ],

    // Unified supplier view — one page, tabbed. Mirrors the customer view;
    // no Addresses tab because a supplier's address is flat columns on the
    // row, so it lives in Overview.
    'tabs' => [
        'overview'  => 'Overview',
        'purchases' => 'Purchases',
        'returns'   => 'Returns',
        'statement' => 'Statement',
        'payments'  => 'Payments',
    ],

    'open_purchases' => [
        'title'    => 'Open purchases',
        'sub'      => ':count purchase(s) with a balance owed — oldest first. Click any row to open the purchase.',
        'purchase' => 'Purchase',
        'date'     => 'Date',
        'due'      => 'Due',
        'age'      => 'Age',
        'balance'  => 'Balance due',
    ],

    'purchases' => [
        'list_title'  => 'Purchases',
        'number'      => 'Purchase',
        'invoice'     => 'Supplier invoice',
        'date'        => 'Date',
        'store'       => 'Store',
        'total'       => 'Total',
        'paid'        => 'Paid',
        'balance'     => 'Balance',
        'status'      => 'Status',
        'empty_title' => 'No purchases yet',
        'empty_sub'   => 'Purchase orders raised with this supplier will appear here.',
        'capped'      => 'Showing the most recent :count. Open Purchases for the full list.',
        'view_all'    => 'All purchases',
        'search'      => 'Search purchase #…',
    ],

    'returns' => [
        'title'       => 'Returns',
        'number'      => 'Return',
        'date'        => 'Date',
        'against'     => 'Against purchase',
        'amount'      => 'Amount',
        'empty_title' => 'No returns yet',
        'empty_sub'   => 'Goods sent back to this supplier will appear here.',
        'capped'      => 'Showing the most recent :count. Open Purchase returns for the full list.',
        'view_all'    => 'All returns',
        'search'      => 'Search return #…',
    ],

    'payments' => [
        'title'       => 'Payments made',
        'sub'         => 'Money paid to this supplier, including advances and credit applied to purchases.',
        'date'        => 'Date',
        'reference'   => 'Purchase',
        'method'      => 'Method',
        'amount'      => 'Amount',
        'note'        => 'Reference / note',
        'unallocated' => 'On account',
        'voided'      => 'Voided',
        'empty_title' => 'No payments yet',
        'empty_sub'   => 'Payments made to this supplier will appear here.',
        'capped'      => 'Showing the most recent :count. Open Supplier payments for the full list.',
        'view_all'    => 'All payments',
        'search'      => 'Search purchase #…',
        'record'      => 'Record payment',
    ],

    'statement_tab' => [
        'title' => 'Statement of account',
        'sub'   => ':from to :to — running balance of what we owe this supplier.',
    ],

    'kpis' => [
        'lifetime_spend'  => 'Lifetime spend',
        'lifetime_sub'    => ':count purchase(s), net of returns',
        'outstanding'     => 'Outstanding',
        'outstanding_sub' => 'Credit on account',
        'last_order'      => 'Last order',
        'last_order_none' => 'Never',
        'since'           => 'Supplier since',
        'terms'           => 'Payment terms',
    ],

    'actions' => [
        'export'      => 'Export',
        'export_csv'  => 'CSV',
        'export_xlsx' => 'Excel (XLSX)',
        'discard'       => 'Discard',
        'save'          => 'Save changes',
        'create'        => 'Create supplier',
        'edit'          => 'Edit',
        'view'          => 'View profile',
        'delete'        => 'Delete',
        'toggle_active' => 'Toggle active',
        'tip_active_on'  => 'Active — click to deactivate.',
        'tip_active_off' => 'Inactive — click to activate.',
    ],

    'flash' => [
        'created'     => 'Supplier ":name" created.',
        'updated'     => 'Supplier ":name" updated.',
        'deleted'     => 'Supplier ":name" deleted.',
        'activated'   => 'Supplier ":name" activated.',
        'deactivated' => 'Supplier ":name" deactivated.',
    ],

    'errors' => [
        'has_balance'        => 'Cannot delete — supplier has an outstanding balance of :amount. Settle the balance or deactivate instead.',
        'has_open_purchases' => 'Can’t retire “:name” — they still have :count open purchase(s). Close those first.',
    ],

    'confirm_delete' => [
        'title'   => 'Delete supplier ":name"?',
        'message' => 'This will soft-delete the supplier. Their historical purchases stay intact.',
        'confirm' => 'Delete',
    ],
];
