<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand logos from a URL — the mirror of what
 * 2026_08_12_000002_add_image_src_url_to_products did for products.
 *
 * `logo_ref` holds a FILENAME the customer drops in themselves; `logo_src_url`
 * holds a link the SERVER fetches. Both may be set on one brand, and neither
 * disables the other.
 *
 * See the products migration for why these are TEXT + a plain string status
 * rather than VARCHAR + an enum.
 *
 * Idempotent: re-run safely on already-migrated installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (! Schema::hasColumn('brands', 'logo_src_url')) {
                $table->text('logo_src_url')->nullable()->after('logo_ref');
            }

            if (! Schema::hasColumn('brands', 'logo_fetch_status')) {
                $table->string('logo_fetch_status', 16)->nullable()->after('logo_src_url')->index();
            }

            if (! Schema::hasColumn('brands', 'logo_fetch_error')) {
                $table->string('logo_fetch_error', 255)->nullable()->after('logo_fetch_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (Schema::hasColumn('brands', 'logo_fetch_error')) {
                $table->dropColumn('logo_fetch_error');
            }

            if (Schema::hasColumn('brands', 'logo_fetch_status')) {
                $table->dropIndex(['logo_fetch_status']);
                $table->dropColumn('logo_fetch_status');
            }

            if (Schema::hasColumn('brands', 'logo_src_url')) {
                $table->dropColumn('logo_src_url');
            }
        });
    }
};
