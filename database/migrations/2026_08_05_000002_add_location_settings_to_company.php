<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company configuration for the shelf-location fields.
 *
 * A pharmacy says "Rack", a warehouse says "Bin", a supermarket says
 * "Aisle" — rather than forcing our vocabulary on every buyer, the four
 * coordinate fields can be renamed and switched off individually.
 * A small shop enables one; a warehouse enables all four.
 *
 * Nullable with no backfill: absent means "defaults", resolved in
 * Company::locationFields(). Nothing to migrate on an existing install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->json('location_settings')->nullable()->after('scale_settings');
        });
    }

    public function down(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn('location_settings');
        });
    }
};
