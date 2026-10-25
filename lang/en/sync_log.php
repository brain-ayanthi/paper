<?php

return [
    'title'        => 'Sync log',
    'sub'          => 'Every cashier-complete round-trip with payload + result. Offline-completed sales show up here once they sync.',
    'crumb_parent' => 'Insights',

    'summary' => [
        'total'    => 'Entries',
        'success'  => 'Success',
        'conflict' => 'Conflict',
        'failed'   => 'Failed',
    ],
    'list_title'   => 'Entries',

    'tabs' => [
        'all'      => 'All',
        'success'  => 'Successful',
        'failed'   => 'Failed',
        'conflict' => 'Conflicts',
    ],

    'columns' => [
        'synced_at'  => 'Synced at',
        'entity'     => 'Entity',
        'local_uuid' => 'Local UUID',
        'result'     => 'Result',
        'user'       => 'Cashier',
        'message'    => 'Message',
    ],

    'status' => [
        'success'   => 'Success',
        'failed'    => 'Failed',
        'conflict'  => 'Conflict',
        'dismissed' => 'Dismissed',
    ],

    'actions' => [
        'retry'      => 'Retry sync',
        'dismiss'    => 'Dismiss',
        'undismiss'  => 'Un-dismiss',
        'view'       => 'View details',
    ],

    'confirm_retry' => [
        'title'   => 'Retry this sync?',
        'message' => 'The stored payload is re-sent to the server exactly as the till recorded it. Safe to repeat — a sale that already landed will not be duplicated.',
        'confirm' => 'Retry now',
    ],

    'confirm_dismiss' => [
        'title'   => 'Dismiss this entry?',
        'message' => 'Use this when the sale can never sync — the stock genuinely was not there, or it was re-rung by hand. The entry and its payload are kept for audit; it just stops counting as outstanding.',
        'confirm' => 'Dismiss',
    ],

    'flash' => [
        'retry_success' => 'Synced. The sale is now recorded on the server.',
        'retry_failed'  => 'Retry rejected by the server: :message',
        'dismissed'     => 'Entry dismissed.',
        'undismissed'   => 'Entry reopened.',
    ],

    'retry_hint' => 'A retry replays this payload as the original cashier. If your store enforces shifts and theirs has closed, reopen a shift for them first.',

    'filter' => [
        'from'   => 'From',
        'to'     => 'To',
        'search' => 'Search UUID or message…',
    ],

    'fields' => [
        'entity'      => 'Entity',
        'user'        => 'Cashier',
        'sale'        => 'Sale',
        'grand_total' => 'Grand total',
        'message'     => 'Message',
        'payload'     => 'Original payload',
        'resolved'    => 'Resolved',
        'resolved_by' => 'Resolved by',
    ],

    'columns_actions' => 'Actions',

    'empty' => [
        'title' => 'No sync events yet',
        'sub'   => 'Cashier completions land here as soon as a sale syncs (online or after an offline outage).',
    ],
];
