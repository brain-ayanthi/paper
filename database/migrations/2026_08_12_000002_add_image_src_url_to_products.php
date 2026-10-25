<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the remote-image columns to products — the second way a bulk import can
 * declare a photo.
 *
 * `image_ref` (added earlier) holds a FILENAME the customer will supply later by
 * dropping a folder into the bulk-images screen. `image_src_url` holds a URL the
 * SERVER fetches instead. The two are independent and a product may have both:
 * an import whose `Image` cell contains a URL records the URL here AND its
 * basename in `image_ref`, so whichever path the customer runs — folder drop or
 * URL fetch — still finds its product. Neither is a mode; both stay available.
 *
 * `image_fetch_status` is 'pending' | 'done' | 'failed'; NULL is read as pending
 * so a row whose URL was set by hand (or by an older import) is still picked up.
 * It's a plain string rather than an enum so MySQL 5.7 installs and future
 * status values don't need a schema change.
 *
 * `image_src_url` is TEXT, not VARCHAR: signed CDN URLs run long, and TEXT keeps
 * its bytes off the row so a wide products table can't hit MySQL's 65,535-byte
 * row limit. It is never queried by value, so it needs no index.
 *
 * Idempotent: re-run safely on already-migrated installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'image_src_url')) {
                $table->text('image_src_url')->nullable()->after('image_ref');
            }

            if (! Schema::hasColumn('products', 'image_fetch_status')) {
                // Indexed: the URL screen counts pending rows on every visit.
                $table->string('image_fetch_status', 16)->nullable()->after('image_src_url')->index();
            }

            if (! Schema::hasColumn('products', 'image_fetch_error')) {
                $table->string('image_fetch_error', 255)->nullable()->after('image_fetch_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'image_fetch_error')) {
                $table->dropColumn('image_fetch_error');
            }

            if (Schema::hasColumn('products', 'image_fetch_status')) {
                $table->dropIndex(['image_fetch_status']);
                $table->dropColumn('image_fetch_status');
            }

            if (Schema::hasColumn('products', 'image_src_url')) {
                $table->dropColumn('image_src_url');
            }
        });
    }
};
