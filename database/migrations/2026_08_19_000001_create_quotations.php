<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['key' => 'quotations.view_own', 'label' => 'View own quotations', 'dangerous' => false],
        ['key' => 'quotations.view_all', 'label' => 'View all quotations in store', 'dangerous' => false],
        ['key' => 'quotations.create', 'label' => 'Create quotations', 'dangerous' => false],
        ['key' => 'quotations.update', 'label' => 'Edit draft quotations', 'dangerous' => false],
        ['key' => 'quotations.send', 'label' => 'Send and revise quotations', 'dangerous' => false],
        ['key' => 'quotations.convert', 'label' => 'Convert quotations to sales', 'dangerous' => false],
        ['key' => 'quotations.cancel', 'label' => 'Cancel quotations', 'dangerous' => true],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('company', 'quotation_number_format')) {
            Schema::table('company', function (Blueprint $table) {
                $table->string('quotation_number_format', 191)->nullable()->after('hold_number_format');
            });
        }

        if (! Schema::hasTable('quotations')) {
            Schema::create('quotations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('revision_of_id')->nullable()->constrained('quotations')->nullOnDelete();
                $table->foreignId('converted_sale_id')->nullable()->unique()->constrained('sales')->nullOnDelete();
                $table->string('number', 191);
                $table->unsignedInteger('revision')->default(1);
                $table->string('status', 24)->default('draft')->index();
                $table->date('issue_date');
                $table->date('valid_until')->nullable()->index();
                $table->char('currency_code', 3);
                $table->decimal('exchange_rate_to_base', 20, 10)->default(1);
                $table->json('customer_snapshot')->nullable();
                $table->json('billing_address_snapshot')->nullable();
                $table->json('shipping_address_snapshot')->nullable();
                $table->decimal('subtotal', 15, 4)->default(0);
                $table->decimal('discount_total', 15, 4)->default(0);
                $table->decimal('tax_total', 15, 4)->default(0);
                $table->decimal('grand_total', 15, 4)->default(0);
                $table->date('expected_delivery_date')->nullable();
                $table->text('terms')->nullable();
                $table->text('notes')->nullable();
                $table->text('internal_notes')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('first_viewed_at')->nullable();
                $table->timestamp('last_viewed_at')->nullable();
                $table->unsignedInteger('view_count')->default(0);
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('declined_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('converted_at')->nullable();
                $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
                $table->unique(['store_id', 'number', 'revision']);
                $table->index(['store_id', 'issue_date']);
                $table->index(['customer_id', 'issue_date']);
            });
        }

        if (! Schema::hasTable('quotation_items')) {
            Schema::create('quotation_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
                $table->string('product_name_snapshot');
                $table->string('variant_name_snapshot')->nullable();
                $table->string('sku_snapshot', 64)->nullable();
                $table->string('barcode_snapshot', 64)->nullable();
                $table->string('hsn_snapshot', 32)->nullable();
                $table->decimal('quantity', 15, 4);
                $table->string('unit', 16);
                $table->decimal('unit_price', 15, 4);
                $table->decimal('discount_percent', 7, 4)->default(0);
                $table->decimal('discount_amount', 15, 4)->default(0);
                $table->foreignId('tax_group_id')->nullable()->constrained('tax_groups')->nullOnDelete();
                $table->json('tax_breakdown')->nullable();
                $table->decimal('tax_amount', 15, 4)->default(0);
                $table->decimal('line_subtotal', 15, 4);
                $table->decimal('line_total', 15, 4);
                $table->string('notes', 500)->nullable();
                $table->unsignedInteger('sort_order')->default(0);

                $table->index(['quotation_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('quotation_activities')) {
            Schema::create('quotation_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 32);
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['quotation_id', 'created_at']);
            });
        }

        $now = now();
        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                [
                    'group' => 'Quotations',
                    'label' => $permission['label'],
                    'description' => null,
                    'is_dangerous' => $permission['dangerous'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', array_column(self::PERMISSIONS, 'key'))
            ->pluck('id', 'key');

        foreach (['Admin', 'Manager'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if ($roleId === null) {
                continue;
            }
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permission')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        $cashierId = DB::table('roles')->where('name', 'Cashier')->value('id');
        if ($cashierId !== null) {
            foreach (['quotations.view_own', 'quotations.create', 'quotations.update', 'quotations.send', 'quotations.convert'] as $key) {
                if (isset($permissionIds[$key])) {
                    DB::table('role_permission')->updateOrInsert([
                        'role_id' => $cashierId,
                        'permission_id' => $permissionIds[$key],
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_activities');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');

        if (Schema::hasColumn('company', 'quotation_number_format')) {
            Schema::table('company', function (Blueprint $table) {
                $table->dropColumn('quotation_number_format');
            });
        }

        // Permission rows are intentionally retained so rolling back does not
        // destroy custom role configuration from a newer backup.
    }
};
