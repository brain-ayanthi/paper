<?php

namespace App\Services\Backup;

use App\Exceptions\InvalidBackupArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Replaces the live database with the contents of a backup's `dump.sql`.
 *
 * There is no transaction wrapping this — DDL is auto-committed on MySQL, so a
 * mid-restore failure can't be rolled back at the database level. The restore
 * wizard's safety net is the pre-restore snapshot, not a transaction.
 *
 * Driver-aware (MySQL production, SQLite tests) for the foreign-key guard and
 * identifier quoting. The dump is executed whole via `unprepared()` so the PDO
 * driver parses statement boundaries correctly — naive `;`-splitting would
 * break on semicolons and newlines living inside string values.
 */
class DatabaseRestorer
{
    /**
     * Drop every table currently in the database — including any not present in
     * the backup (e.g. a table a half-applied update added). The dump then
     * recreates exactly the tables that existed at backup time.
     */
    public function dropAllTables(): void
    {
        $driver = DB::connection()->getDriverName();
        $tables = $this->currentSchemaTables($driver);

        if ($driver === 'sqlite') {
            DB::unprepared('PRAGMA foreign_keys=OFF;');
            foreach ($tables as $table) {
                $bare = $this->bare($table);
                DB::statement('DROP TABLE IF EXISTS "'.$bare.'"');
            }
            DB::unprepared('PRAGMA foreign_keys=ON;');

            return;
        }

        DB::unprepared('SET FOREIGN_KEY_CHECKS=0;');
        foreach ($tables as $table) {
            DB::statement('DROP TABLE IF EXISTS `'.$this->bare($table).'`');
        }
        DB::unprepared('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function restore(string $sqlPath): void
    {
        $sql = file_get_contents($sqlPath);
        if ($sql === false) {
            throw new RuntimeException('Could not read the backup database dump.');
        }

        $this->assertDumpLooksLikeOurs($sql);

        // The dump carries its own FK guard (SET FOREIGN_KEY_CHECKS / PRAGMA),
        // so table order inside the file doesn't matter.
        DB::connection()->unprepared($sql);
    }

    /**
     * Refuse a dump containing statements our own backups never produce —
     * security register SEC #20 (defect #77).
     *
     * The restore executes the uploaded file wholesale, so whatever it
     * contains runs with the application's database privileges. RunBackup only
     * ever emits DROP TABLE / CREATE TABLE / INSERT / the FK-guard pragmas, so
     * anything else in a dump means it did not come from us.
     *
     * What this DOES stop is a dump escaping the "just data" model: reading or
     * writing server files (LOAD DATA INFILE, INTO OUTFILE), granting database
     * privileges, creating users, or installing triggers and stored routines
     * that would keep executing long after the restore finished.
     *
     * What it does NOT stop, and cannot: a dump that is structurally
     * legitimate but carries attacker-chosen ROWS — an extra admin user, say.
     * A restore replaces all data by design, so such a file is indistinguishable
     * from a genuine backup containing the same row. Closing that needs
     * provenance (a signed manifest, cf. SEC #35) plus not letting untrusted
     * people upload backups at all. Recorded on the register as such.
     */
    private function assertDumpLooksLikeOurs(string $sql): void
    {
        // Strip string literals first, so a product description that happens
        // to contain the word "grant" cannot trip the scan.
        $stripped = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $sql) ?? $sql;

        $forbidden = [
            'LOAD DATA'         => '/\bLOAD\s+DATA\b/i',
            'INTO OUTFILE'      => '/\bINTO\s+(OUT|DUMP)FILE\b/i',
            'GRANT'             => '/\bGRANT\s+/i',
            'REVOKE'            => '/\bREVOKE\s+/i',
            'CREATE USER'       => '/\bCREATE\s+USER\b/i',
            'DROP USER'         => '/\bDROP\s+USER\b/i',
            'SET GLOBAL'        => '/\bSET\s+GLOBAL\b/i',
            'CREATE TRIGGER'    => '/\bCREATE\s+(OR\s+REPLACE\s+)?TRIGGER\b/i',
            'CREATE PROCEDURE'  => '/\bCREATE\s+(OR\s+REPLACE\s+)?PROCEDURE\b/i',
            'CREATE FUNCTION'   => '/\bCREATE\s+(OR\s+REPLACE\s+)?FUNCTION\b/i',
            'CREATE EVENT'      => '/\bCREATE\s+(OR\s+REPLACE\s+)?EVENT\b/i',
            'ATTACH DATABASE'   => '/\bATTACH\s+DATABASE\b/i',
        ];

        foreach ($forbidden as $label => $pattern) {
            if (preg_match($pattern, $stripped)) {
                throw new InvalidBackupArchive(
                    __('restore.errors.unsafe_dump', ['statement' => $label])
                );
            }
        }
    }

    /**
     * Read every row of the given tables so they can be re-applied after the
     * restore. Used to keep operational/audit tables (backup history, restore
     * history, update history) continuous across a restore instead of
     * reverting them to their backup-time state.
     *
     * @param  string[]  $tables
     * @return array<string, array<int, array<string,mixed>>>
     */
    public function snapshot(array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $out[$table] = array_map(static fn ($r) => (array) $r, DB::table($table)->get()->all());
            }
        }

        return $out;
    }

    /**
     * Re-apply a {@see snapshot()} on top of the freshly restored data,
     * upserting by id. The backup's rows form the base; these live rows win,
     * so anything that happened after the backup (including the in-flight
     * restore's own log row) survives. Foreign-key checks are disabled because
     * a preserved row may reference an entity that only exists post-backup.
     *
     * @param  array<string, array<int, array<string,mixed>>>  $snapshot
     */
    public function overlay(array $snapshot): void
    {
        $driver = DB::connection()->getDriverName();
        DB::unprepared($driver === 'sqlite' ? 'PRAGMA foreign_keys=OFF;' : 'SET FOREIGN_KEY_CHECKS=0;');

        try {
            foreach ($snapshot as $table => $rows) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (! array_key_exists('id', $row)) {
                        continue;
                    }
                    DB::table($table)->updateOrInsert(['id' => $row['id']], $row);
                }
            }
        } finally {
            DB::unprepared($driver === 'sqlite' ? 'PRAGMA foreign_keys=ON;' : 'SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    private function bare(string $table): string
    {
        return str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
    }

    /**
     * Base tables in THIS connection's database only — mirrors the scoping in
     * RunBackup. `Schema::getTableListing()` can leak tables from OTHER
     * databases on a shared MySQL server, so we query information_schema scoped
     * to the current schema (base tables only, no views).
     *
     * @return array<int, string>
     */
    private function currentSchemaTables(string $driver): array
    {
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return Schema::getTableListing();
        }

        $database = DB::connection()->getDatabaseName();
        $rows = DB::select(
            'SELECT TABLE_NAME AS name FROM information_schema.tables '
            ."WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
            [$database]
        );

        return array_map(static fn ($r) => $r->name, $rows);
    }
}
