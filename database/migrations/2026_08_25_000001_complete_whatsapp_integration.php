<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_connections')) {
            Schema::create('whatsapp_connections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->nullable()->constrained('stores')->restrictOnDelete();
                $table->string('provider', 32)->index();
                $table->string('name');
                $table->string('sender_phone', 32)->nullable();
                $table->longText('credentials')->nullable();
                $table->json('settings')->nullable();
                $table->string('status', 24)->default('disconnected')->index();
                $table->timestamp('last_tested_at')->nullable();
                $table->timestamp('last_connected_at')->nullable();
                $table->timestamp('last_webhook_at')->nullable();
                $table->text('last_error')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['store_id', 'status'], 'whatsapp_connections_store_status_idx');
            });
        }

        if (Schema::hasTable('whatsapp_logs') && ! Schema::hasColumn('whatsapp_logs', 'whatsapp_connection_id')) {
            Schema::table('whatsapp_logs', function (Blueprint $table) {
                $table->foreignId('whatsapp_connection_id')->nullable()->after('store_id')
                    ->constrained('whatsapp_connections')->nullOnDelete();
                $table->timestamp('delivered_at')->nullable()->after('sent_at');
                $table->timestamp('read_at')->nullable()->after('delivered_at');
                $table->string('message_template', 512)->nullable()->change();
                $table->index(['store_id', 'status', 'created_at'], 'whatsapp_logs_store_status_created_idx');
            });
        }

        if (! Schema::hasTable('whatsapp_webhook_events')) {
            Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('whatsapp_connection_id')->constrained()->cascadeOnDelete();
                $table->string('event_id', 191);
                $table->string('event_type', 64)->nullable();
                $table->longText('payload');
                $table->timestamp('processed_at')->nullable();
                $table->timestamp('received_at')->useCurrent();
                $table->timestamps();

                $table->unique(['whatsapp_connection_id', 'event_id'], 'whatsapp_webhook_connection_event_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');

        if (Schema::hasTable('whatsapp_logs') && Schema::hasColumn('whatsapp_logs', 'whatsapp_connection_id')) {
            Schema::table('whatsapp_logs', function (Blueprint $table) {
                $table->dropForeign(['whatsapp_connection_id']);
                $table->dropIndex('whatsapp_logs_store_status_created_idx');
                $table->dropColumn(['whatsapp_connection_id', 'delivered_at', 'read_at']);
                $table->string('message_template', 64)->nullable()->change();
            });
        }

        Schema::dropIfExists('whatsapp_connections');
    }
};
