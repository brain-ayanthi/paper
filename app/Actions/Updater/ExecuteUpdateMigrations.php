<?php

namespace App\Actions\Updater;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the new release's migrations forward. A separate action (rather than an
 * inline Artisan call) so the InstallUpdate orchestrator can inject it — which
 * also lets tests simulate a migration failure to exercise the rollback path.
 *
 * Migrations are followed by a permissions reseed (SEC #44). A release that
 * adds a permission key has nothing to run on an existing install otherwise —
 * `migrate` doesn't seed — so the new key silently doesn't exist and whatever
 * gates on it is unreachable until someone runs `db:seed` by hand. That is
 * exactly how the Accounting and Expenses keys went missing. PermissionsSeeder
 * is `updateOrInsert` over a fixed catalog, so this only ever adds or corrects
 * rows; it never touches role grants.
 */
class ExecuteUpdateMigrations
{
    /**
     * Resolve the migration service before the updater replaces vendor files.
     *
     * An update can change Laravel itself. The current HTTP request already has
     * database connection classes loaded from the old release; if the migrator
     * is first resolved after vendor/ is replaced, PHP can combine that old
     * connection with a new Migrator class. A newly-added framework method then
     * fails with errors such as MySqlConnection::hasDirectConnection missing.
     *
     * Keeping the old migrator loaded for this request preserves one coherent
     * framework generation. The following request loads the new release in the
     * normal way.
     */
    public function prepare(): void
    {
        // Laravel 13 added direct/pooled database connections. During an
        // in-place framework update the new migrator can briefly meet the old
        // Connection class that does not yet define this method. Connection is
        // Macroable, so provide the old generation with the compatibility
        // answer it would have returned: it has no direct connection.
        Connection::macro('hasDirectConnection', fn (): bool => false);

        app('migrator');
    }

    public function __invoke(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        Artisan::call('db:seed', [
            '--class' => \Database\Seeders\PermissionsSeeder::class,
            '--force' => true,
        ]);
    }
}
