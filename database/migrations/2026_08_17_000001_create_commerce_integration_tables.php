<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-neutral foundation for WooCommerce / Shopify sales channels.
 *
 * The POS remains the stock ledger. Remote identifiers are kept in mapping
 * tables, inbound webhooks land in a durable inbox, and outbound changes land
 * in an outbox before any network request is attempted. Channel orders are
 * deliberately separate from `sales`: one ecommerce order may be allocated
 * across several POS stores, while a Sale is intrinsically store-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional. If a later CREATE TABLE fails, tables
        // created earlier in this migration remain even though Laravel cannot
        // record the migration as completed. Guard every table so a corrected
        // deployment can safely resume instead of failing on the first remnant.
        if (! Schema::hasTable('commerce_connections')) {
            Schema::create('commerce_connections', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 24)->index();
                $table->string('name');
                $table->string('shop_url');
                $table->string('external_store_id', 191)->nullable();
                $table->string('api_version', 24)->nullable();
                $table->longText('credentials')->nullable();
                $table->json('settings')->nullable();
                $table->string('status', 24)->default('disconnected')->index();
                $table->timestamp('last_tested_at')->nullable();
                $table->timestamp('last_connected_at')->nullable();
                $table->timestamp('last_webhook_at')->nullable();
                $table->timestamp('last_reconciled_at')->nullable();
                $table->text('last_error')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                // MySQL permits multiple NULL values in a unique key, so including
                // soft-delete's nullable column would not actually prevent duplicate
                // active connections. Uniqueness is enforced by the write action,
                // which can deliberately ignore archived rows.
                $table->index(['provider', 'shop_url'], 'commerce_connections_provider_url_idx');
            });
        }

        if (! Schema::hasTable('commerce_store_mappings')) {
            Schema::create('commerce_store_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('commerce_connection_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->constrained()->restrictOnDelete();
                $table->string('remote_location_id', 191)->nullable();
                $table->string('remote_location_name')->nullable();
                $table->string('inventory_mode', 24)->default('location');
                $table->unsignedInteger('fulfillment_priority')->default(100);
                $table->decimal('safety_stock', 15, 4)->default(0);
                $table->boolean('sync_inventory')->default(true);
                $table->boolean('fulfill_orders')->default(true);
                $table->boolean('is_active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();

                $table->unique(['commerce_connection_id', 'store_id'], 'commerce_store_connection_unique');
                $table->index(['commerce_connection_id', 'remote_location_id'], 'commerce_store_remote_location_idx');
            });
        }

        if (! Schema::hasTable('commerce_entity_mappings')) {
            Schema::create('commerce_entity_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('commerce_connection_id')->constrained()->cascadeOnDelete();
                $table->string('entity_type', 32);
                $table->string('local_type', 100);
                $table->unsignedBigInteger('local_id');
                $table->string('remote_id', 191);
                $table->string('remote_parent_id', 191)->nullable();
                $table->string('local_hash', 64)->nullable();
                $table->string('remote_hash', 64)->nullable();
                $table->timestamp('remote_updated_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(
                    ['commerce_connection_id', 'entity_type', 'local_type', 'local_id'],
                    'commerce_mapping_local_unique',
                );
                $table->unique(
                    ['commerce_connection_id', 'entity_type', 'remote_id'],
                    'commerce_mapping_remote_unique',
                );
            });
        }

        if (! Schema::hasTable('commerce_webhook_events')) {
            Schema::create('commerce_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('commerce_connection_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 24);
                $table->string('event_id', 191);
                $table->string('topic', 100)->index();
                $table->longText('payload');
                $table->string('status', 24)->default('pending')->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('available_at')->nullable()->index();
                $table->timestamp('processed_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('received_at')->useCurrent();
                $table->timestamps();

                $table->unique(
                    ['commerce_connection_id', 'event_id'],
                    'commerce_webhook_connection_event_unique',
                );
            });
        }

        if (! Schema::hasTable('commerce_outbox_events')) {
            Schema::create('commerce_outbox_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('commerce_connection_id')->constrained()->cascadeOnDelete();
                $table->string('event_type', 100)->index();
                $table->string('aggregate_type', 100);
                $table->unsignedBigInteger('aggregate_id');
                $table->string('dedupe_key', 191);
                $table->longText('payload')->nullable();
                $table->string('status', 24)->default('pending')->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('available_at')->nullable()->index();
                $table->timestamp('processed_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->unique(
                    ['commerce_connection_id', 'dedupe_key'],
                    'commerce_outbox_connection_dedupe_unique',
                );
                $table->index(
                    ['commerce_connection_id', 'aggregate_type', 'aggregate_id'],
                    'commerce_outbox_aggregate_idx',
                );
            });
        }

        if (! Schema::hasTable('commerce_sync_runs')) {
            Schema::create('commerce_sync_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('commerce_connection_id')->constrained()->cascadeOnDelete();
                $table->string('kind', 32)->index();
                $table->string('direction', 16);
                $table->string('status', 24)->default('pending')->index();
                $table->json('counters')->nullable();
                $table->longText('cursor')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('channel_orders')) {
            Schema::create('channel_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('commerce_connection_id')->constrained()->cascadeOnDelete();
                $table->string('remote_order_id', 191);
                $table->string('remote_order_number', 100)->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->string('financial_status', 32)->nullable()->index();
                $table->string('fulfillment_status', 32)->nullable()->index();
                $table->char('currency_code', 3);
                $table->decimal('subtotal', 15, 4)->default(0);
                $table->decimal('discount_total', 15, 4)->default(0);
                $table->decimal('tax_total', 15, 4)->default(0);
                $table->decimal('shipping_total', 15, 4)->default(0);
                $table->decimal('grand_total', 15, 4)->default(0);
                $table->decimal('refunded_total', 15, 4)->default(0);
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->string('remote_customer_id', 191)->nullable();
                $table->string('payment_reference', 191)->nullable();
                $table->longText('billing_address')->nullable();
                $table->longText('shipping_address')->nullable();
                $table->longText('payload')->nullable();
                $table->timestamp('placed_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('remote_updated_at')->nullable();
                $table->timestamp('imported_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->unique(
                    ['commerce_connection_id', 'remote_order_id'],
                    'channel_order_connection_remote_unique',
                );
                $table->index(['commerce_connection_id', 'placed_at'], 'channel_orders_connection_placed_idx');
            });
        }

        if (! Schema::hasTable('channel_order_items')) {
            Schema::create('channel_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('channel_order_id')->constrained()->cascadeOnDelete();
                $table->string('remote_line_id', 191);
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->string('product_name_snapshot');
                $table->string('sku_snapshot', 64)->nullable();
                $table->decimal('quantity', 15, 4);
                $table->decimal('unit_price', 15, 4)->default(0);
                $table->decimal('discount_amount', 15, 4)->default(0);
                $table->decimal('tax_amount', 15, 4)->default(0);
                $table->decimal('line_total', 15, 4)->default(0);
                $table->decimal('quantity_fulfilled', 15, 4)->default(0);
                $table->decimal('quantity_refunded', 15, 4)->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['channel_order_id', 'remote_line_id'], 'channel_order_remote_line_unique');
            });
        }

        if (! Schema::hasTable('channel_order_allocations')) {
            Schema::create('channel_order_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('channel_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('store_id')->constrained()->restrictOnDelete();
                $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
                $table->string('remote_fulfillment_id', 191)->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->decimal('subtotal', 15, 4)->default(0);
                $table->decimal('discount_total', 15, 4)->default(0);
                $table->decimal('tax_total', 15, 4)->default(0);
                $table->decimal('shipping_total', 15, 4)->default(0);
                $table->decimal('grand_total', 15, 4)->default(0);
                $table->timestamp('reserved_at')->nullable();
                $table->timestamp('fulfilled_at')->nullable();
                $table->timestamps();

                $table->unique(['channel_order_id', 'store_id'], 'channel_order_store_allocation_unique');
            });
        }

        if (! Schema::hasTable('channel_order_allocation_items')) {
            Schema::create('channel_order_allocation_items', function (Blueprint $table) {
                $table->id();
                // Keep these names explicit: Laravel's generated allocation FK
                // name is 66 characters, above MySQL's 64-character limit.
                $table->foreignId('channel_order_allocation_id')
                    ->constrained('channel_order_allocations', 'id', 'coa_items_allocation_fk')
                    ->cascadeOnDelete();
                $table->foreignId('channel_order_item_id')
                    ->constrained('channel_order_items', 'id', 'coa_items_order_item_fk')
                    ->cascadeOnDelete();
                $table->decimal('quantity', 15, 4);
                $table->decimal('quantity_fulfilled', 15, 4)->default(0);
                $table->decimal('quantity_refunded', 15, 4)->default(0);
                $table->timestamps();

                $table->unique(
                    ['channel_order_allocation_id', 'channel_order_item_id'],
                    'channel_allocation_item_unique',
                );
            });
        }

        // MySQL runs the CREATE TABLE and subsequent constraints as separate
        // DDL statements. The old overlong FK could therefore leave this table
        // behind without its constraints while the migration remained unlogged.
        // Repair each missing piece independently so the updater can be retried.
        if (! Schema::hasForeignKey('channel_order_allocation_items', ['channel_order_allocation_id'])) {
            Schema::table('channel_order_allocation_items', function (Blueprint $table) {
                $table->foreign('channel_order_allocation_id', 'coa_items_allocation_fk')
                    ->references('id')
                    ->on('channel_order_allocations')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasForeignKey('channel_order_allocation_items', ['channel_order_item_id'])) {
            Schema::table('channel_order_allocation_items', function (Blueprint $table) {
                $table->foreign('channel_order_item_id', 'coa_items_order_item_fk')
                    ->references('id')
                    ->on('channel_order_items')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasIndex(
            'channel_order_allocation_items',
            ['channel_order_allocation_id', 'channel_order_item_id'],
            'unique',
        )) {
            Schema::table('channel_order_allocation_items', function (Blueprint $table) {
                $table->unique(
                    ['channel_order_allocation_id', 'channel_order_item_id'],
                    'channel_allocation_item_unique',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_order_allocation_items');
        Schema::dropIfExists('channel_order_allocations');
        Schema::dropIfExists('channel_order_items');
        Schema::dropIfExists('channel_orders');
        Schema::dropIfExists('commerce_sync_runs');
        Schema::dropIfExists('commerce_outbox_events');
        Schema::dropIfExists('commerce_webhook_events');
        Schema::dropIfExists('commerce_entity_mappings');
        Schema::dropIfExists('commerce_store_mappings');
        Schema::dropIfExists('commerce_connections');
    }
};
