<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            // NULL for deleted rows permits history; live rows collapse the
            // company scope to 0 so both NULL and store scopes are unique.
            $table->unsignedBigInteger('live_scope_key')
                ->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN COALESCE(store_id, 0) ELSE NULL END');
            $table->unique('live_scope_key', 'whatsapp_connections_live_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropUnique('whatsapp_connections_live_scope_unique');
            $table->dropColumn('live_scope_key');
        });
    }
};
