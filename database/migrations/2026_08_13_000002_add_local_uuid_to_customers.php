<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Code-review #65: offline customer quick-add was not idempotent — a
 * dropped response made the retry 422 on the unique phone/email (entry
 * stuck forever, its dependent sale never syncing) or, with no
 * phone/email, created a duplicate. The client's queue entry always had
 * a local_uuid; storing it lets the server return the existing row on a
 * replay. Unique (MySQL allows many NULLs) so a race between retries
 * hard-stops instead of duplicating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('local_uuid', 64)->nullable()->unique()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['local_uuid']);
            $table->dropColumn('local_uuid');
        });
    }
};
