<?php

/*
|--------------------------------------------------------------------------
| System Health page strings
|--------------------------------------------------------------------------
| Admin → System Configuration → System Health. A read-only diagnostics
| board. See App\Actions\System\CheckSystemHealth + the controller.
*/

return [
    'title'        => 'System Health',
    'sub'          => 'A quick read-only check of your install — configuration, database, storage, background jobs, and server requirements.',
    'crumb_parent' => 'System Configuration',

    'refresh'      => 'Re-run checks',
    'last_checked' => 'Checked just now',
    'expand_all'   => 'Expand all',
    'collapse_all' => 'Collapse all',

    'clear_sample' => [
        'title'          => 'Clear sample data',
        'sub'            => 'Installed with demo data? Remove it and start clean.',
        'warning'        => 'This permanently deletes ALL sales, purchases, products, customers, suppliers, expenses, stock, and accounting entries. Your company, stores, users, settings, tax setup, and chart of accounts are kept. A backup is taken first.',
        'button'         => 'Clear sample data',
        'confirm_title'  => 'Clear all sample data?',
        'confirm_message'=> 'Every sale, product, customer, supplier, expense, stock record and journal entry will be permanently deleted. Company, stores, users and settings are kept. A backup is taken first. This cannot be undone.',
        'confirm_button' => 'Yes, clear it',
        'cancel'         => 'Cancel',
        'flash'          => 'Sample data cleared — :rows rows removed. A backup was taken first.',
    ],

    // Rebuilds sale_items.unit_cost_snapshot for sales rung up before the
    // cost snapshot existed. Preview first, then apply.
    'backfill_costs' => [
        'title'   => 'Rebuild historical sale costs',
        'sub'     => 'For sales recorded before cost tracking was added.',
        'explain' => 'Older sales were saved without a cost, so they show 100% margin on the profit reports. This walks your stock ledger and works out what each item actually cost at the moment it sold. It only fills costs that are missing — nothing already recorded is changed. Preview first; nothing is written until you confirm.',

        'found'          => 'Sales lines with no cost',
        'costed'         => 'Can be costed',
        'approximate'    => '…of those, approximate (no purchase history)',
        'left_zero'      => 'No cost found anywhere — left as is',
        'cogs'           => 'Total cost this will record',

        'preview_button' => 'Preview',
        'apply_button'   => 'Rebuild costs',
        'confirm_title'  => 'Rebuild historical costs?',
        'confirm_message'=> 'Costs will be written to the sales lines listed above, and your profit reports for past periods will change to match. Sales that already have a cost are untouched.',
        'confirm_button' => 'Yes, rebuild',

        'flash_preview'  => 'Preview only — :lines line(s) can be costed. Nothing has been written yet.',
        'flash_applied'  => 'Rebuilt :lines sale line(s). :cogs of cost recorded.',
    ],

    'overall' => [
        'ok'    => 'Everything looks healthy',
        'warn'  => 'Some things need attention',
        'fail'  => 'Action required',
        'ok_sub'   => 'All :count checks passed.',
        'warn_sub' => ':warn warning(s) to review.',
        'fail_sub' => ':fail problem(s) to fix:warn_extra.',
        'warn_extra' => ', plus :warn warning(s)',
    ],

    'status' => [
        'ok'   => 'OK',
        'warn' => 'Warning',
        'fail' => 'Problem',
    ],

    'groups' => [
        'application'  => 'Application',
        'database'     => 'Database',
        'storage'      => 'Storage & filesystem',
        'jobs'         => 'Background jobs',
        'php'          => 'PHP & server',
        'integrations' => 'Optional integrations',
    ],

    'rows' => [
        'version'          => 'App version',
        'environment'      => 'Environment',
        'debug'            => 'Debug mode',
        'dev_routes'       => 'Developer routes',
        'app_key'          => 'Application key',
        'timezone'         => 'Timezone',
        'db_connection'    => 'Database connection',
        'db_version'       => 'Database version',
        'db_driver'        => 'Database driver',
        'storage_writable' => 'storage/ writable',
        'cache_writable'   => 'bootstrap/cache writable',
        'storage_link'     => 'Storage symlink (public/storage)',
        'disk_free'        => 'Free disk space',
        'scheduler'        => 'Scheduler (cron job)',
        'queue_driver'     => 'Queue driver',
        'jobs_pending'     => 'Pending jobs',
        'jobs_failed'      => 'Failed jobs',
        'mail'             => 'Email (SMTP)',
        'realtime'         => 'Real-time (Pusher)',
    ],

    'values' => [
        'set'            => 'Set',
        'missing'        => 'Missing',
        'connected'      => 'Connected',
        'failed'         => 'Could not connect',
        'writable'       => 'Writable',
        'not_writable'   => 'Not writable',
        'present'        => 'Present',
        'configured'     => 'Configured',
        'polling'        => 'Polling (no Pusher)',
        'not_configured' => 'Not configured',
        'none'           => 'None',
        'enabled'        => 'Enabled',
        'disabled'       => 'Disabled',
        'jobs_pending'   => '{1} :count job (oldest :mins min)|[2,*] :count jobs (oldest :mins min)',
        'scheduler_ok'      => 'Running (:human)',
        'scheduler_delayed' => 'Slow (:human)',
        'scheduler_stale'   => 'Stopped (:human)',
        'scheduler_never'   => 'Never run',
    ],

    'hints' => [
        'environment'      => 'For a live store set APP_ENV=production in your .env.',
        'debug'            => 'Set APP_DEBUG=false in .env on a live store — debug mode can expose sensitive details.',
        'dev_routes'       => 'Remove POS_DEV_ROUTES from .env on a live store. It exposes /migrate, /seed and /clear-cache over the web, and /migrate?fresh=1 erases the whole database.',
        'app_key'          => 'Run the installer or `php artisan key:generate` to set APP_KEY.',
        'db_version'       => 'MySQL 8+ is recommended. Older versions may miss features the app relies on.',
        'db_driver'        => 'This app is built for MySQL. Other drivers are unsupported.',
        'db_connection'    => 'Check your database credentials in .env (DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD).',
        'storage_writable' => 'chmod 775 the storage/ directory (or set write permission in your host\'s File Manager).',
        'cache_writable'   => 'chmod 775 the bootstrap/cache directory.',
        'storage_link'     => 'Run `php artisan storage:link` so uploaded images are publicly served.',
        'disk_free'        => 'Disk space is running low — free some space or upgrade your hosting plan.',
        'scheduler_never'   => 'No cron job has ever run on this server, so automatic backups, scheduled reports and low-stock alerts are not happening. Open Settings → Scheduler for the exact command to add.',
        'scheduler_stale'   => 'The cron job has stopped. Check it still exists in your hosting control panel — Settings → Scheduler can test it.',
        'scheduler_delayed' => 'The cron job runs less often than every minute, so scheduled tasks may start late. Fine if your host limits cron frequency.',
        'queue_driver'     => 'On shared hosting set QUEUE_CONNECTION=database and run the scheduler via cron.',
        'jobs_stale'       => 'Jobs are queued but not being processed — make sure your cron job runs `schedule:run` every minute.',
        'jobs_failed'      => 'Some background jobs failed. Review them and retry once the cause is fixed.',
        'mail'             => 'Configure SMTP in Settings → Email to send receipts and notifications.',
    ],
];
