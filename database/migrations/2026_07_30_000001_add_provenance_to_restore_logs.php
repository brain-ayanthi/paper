<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records whether a restored archive was one we made — security register
 * SEC #20 (defect #82).
 *
 * A restore replays a SQL dump into the live database. With the manifest now
 * signed, the restore can tell a backup this install produced from a file
 * somebody handed the admin. Which of the two it was is exactly the thing an
 * incident investigation needs, so it is kept on the restore's own audit row
 * rather than only in the log file.
 *
 * Values: `verified` | `unsigned` | `invalid` (see ManifestSigner).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restore_logs', function (Blueprint $table) {
            $table->string('provenance', 16)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('restore_logs', function (Blueprint $table) {
            $table->dropColumn('provenance');
        });
    }
};
