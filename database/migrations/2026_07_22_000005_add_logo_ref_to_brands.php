<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand logos declared by an import (docs/features/products-inventory.md §4).
 *
 * Mirrors `products.image_ref`: the import's `Logo` column holds a bare
 * FILENAME, not the file. Afterwards the admin drops the whole folder on
 * the "Bulk logos" screen and each file fans out to the brand that
 * declared it. Indexed because the matcher looks up thousands of
 * filenames with one `WHERE logo_ref IN (…)`.
 *
 * Kept separate from `logo_path` (where the stored file actually lives)
 * so "which logo was promised" and "which logo do we have" stay
 * distinguishable — that difference is what the pending count reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('logo_ref')->nullable()->after('logo_path')->index();
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropIndex(['logo_ref']);
            $table->dropColumn('logo_ref');
        });
    }
};
