<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypted, company-wide custom header/footer script configuration.
 *
 * The model encrypts the JSON payload before it reaches this LONGTEXT column.
 * Besides keeping snippets out of plain database exports, authenticated
 * encryption prevents a database-only attacker from silently replacing them
 * with browser-executed JavaScript.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->longText('custom_script_settings')->nullable()->after('location_settings');
        });
    }

    public function down(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn('custom_script_settings');
        });
    }
};
