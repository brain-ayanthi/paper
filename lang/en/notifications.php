<?php

return [

    // Dropdown chrome
    'loading'      => 'Loading…',
    'view_all'     => 'View all',
    'unread_count' => ':count new',

    // Trigger messages (rendered inside the bell). Keep titles short.
    'update' => [
        'title'   => 'Update available',
        'message' => 'Version :version is ready to install.',
    ],
    'backup' => [
        'title'   => 'Backup failed',
        'message' => 'Your latest backup did not complete. Open backup settings to retry.',
    ],
    'backup_overdue' => [
        'title'       => 'Automatic backup did not finish',
        'message'     => 'The automatic backup started on :when never completed — the server most likely ran out of memory or time. Open backup settings and run one manually.',
        'interrupted' => 'Interrupted — the process stopped before the backup finished.',
    ],
    'low_stock' => [
        'title'   => 'Low stock',
        'message' => ':count product(s) are at or below their reorder level.',
    ],
    'void' => [
        'title'   => 'Sale voided',
        'message' => 'Sale :number was voided.',
    ],
    'return' => [
        'title'   => 'Refund processed',
        'message' => 'A refund was recorded against sale :number.',
    ],

];
