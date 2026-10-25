<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shelf / rack locations — where a product physically lives in a store.
 *
 * Deliberately a NEW TABLE and nothing else. Existing installs carry live
 * catalogs, so this migration touches no existing column: every product
 * simply has zero location rows until someone fills one in, and every
 * screen left-joins so an untouched install looks exactly as it did.
 *
 * Shape mirrors `product_store_prices` — same (store, product, variant)
 * targeting — because location is a property of the product AT A STORE.
 * Branch A keeps a SKU on rack 12; branch B keeps it on rack 3.
 *
 * A product may sit in SEVERAL places at one store, which is the norm in
 * a pharmacy: a dispensing rack out front, a reserve carton in the
 * godown, insulin in the fridge, schedule-H stock in a locked cabinet.
 * `kind` labels which is which; `is_primary` marks the pick face — the
 * one the shelf label, cashier badge and count sheet default to.
 *
 * IMPORTANT: locations carry no quantities. This is labelling, not
 * bin-level inventory — stock stays per (store, product, batch) exactly
 * as before, and `RecordStockMovement` is untouched. Per-location
 * quantities would rewrite the stock ledger, FEFO picking and every
 * inventory report; that is its own project, not a column here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();

            // primary | backstock | cold | controlled | other
            // See App\Models\ProductLocation::KINDS.
            $table->string('kind', 16)->default('primary');

            // The pick face. At most one per (store, product, variant) —
            // enforced in SyncProductLocations, since MySQL has no
            // partial unique index and the rule is business logic.
            $table->boolean('is_primary')->default(false);

            // Four free-text coordinates. Strings, not FKs: a shop should
            // be able to type "A3" and move on, with no master data to set
            // up first. Which of the four are shown (and what they're
            // called) is per-company — see Company::locationFields().
            $table->string('aisle', 16)->nullable();
            $table->string('rack', 16)->nullable();
            $table->string('shelf', 16)->nullable();
            $table->string('bin', 16)->nullable();

            $table->string('note', 64)->nullable();

            // Walk-order key: the four coordinates with every numeric run
            // zero-padded, so "A3" sorts before "A10" instead of after it.
            // Without this a picking list is in the wrong order and the
            // whole feature stops being useful. See ProductLocation::makeSortKey().
            $table->string('sort_key', 80)->nullable();

            // Display order among one product's locations at one store.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['store_id', 'product_id', 'variant_id'], 'product_locations_target_idx');
            $table->index(['store_id', 'sort_key'], 'product_locations_walk_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_locations');
    }
};
