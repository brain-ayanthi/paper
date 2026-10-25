<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Add the Sales Channels permissions on existing installations. */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['key' => 'commerce.view', 'label' => 'View sales channels, mappings, orders, and sync health', 'dangerous' => false],
        ['key' => 'commerce.manage', 'label' => 'Manage sales-channel connections and credentials', 'dangerous' => true],
        ['key' => 'commerce.orders.manage', 'label' => 'Allocate and fulfill ecommerce orders', 'dangerous' => false],
        ['key' => 'commerce.sync.retry', 'label' => 'Retry and reconcile ecommerce synchronization', 'dangerous' => false],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $row) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $row['key']],
                [
                    'label' => $row['label'],
                    'group' => 'Sales Channels',
                    'description' => null,
                    'is_dangerous' => $row['dangerous'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $adminRoleId = DB::table('roles')->where('name', 'Admin')->value('id');
        if ($adminRoleId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', array_column(self::PERMISSIONS, 'key'))
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permission')->updateOrInsert([
                'role_id' => $adminRoleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        // Keep permission rows/grants on rollback. Removing a catalog entry
        // can invalidate role configuration restored from a newer backup.
    }
};
