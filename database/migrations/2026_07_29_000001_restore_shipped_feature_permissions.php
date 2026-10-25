<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs the collateral damage from
 * `2026_06_27_000001_remove_unbuilt_feature_permissions` — security register
 * SEC #44 (defect #136).
 *
 * That migration pruned permissions for modules that had no UI at the time.
 * Accounting and Expenses then SHIPPED, and PermissionsSeeder seeds their keys
 * again — but an existing install only runs `migrate` on update, never a
 * reseed. So the update deletes `accounting.view`, `accounting.lock_period`,
 * `accounting.unlock_period`, `accounting.manual_entry` and the four
 * `expenses.*` keys along with every role grant pointing at them, while the
 * controllers that gate on those keys are live in the same release. The
 * customer updates and loses Accounting and Expenses access outright, with no
 * way back short of hand-editing roles.
 *
 * This migration:
 *
 *  1. Re-inserts the eight keys (idempotent — matching PermissionsSeeder's
 *     catalog entries exactly, so a later reseed is a no-op).
 *  2. Re-grants them to roles that plainly held the group before, inferred
 *     from a sibling key in the same group that the prune left alone
 *     (`accounting.reverse_entry`, `expenses.export`). The pivot rows
 *     themselves were deleted, so a sibling is the only evidence left of what
 *     a role used to have — and a role holding one accounting key but not
 *     `accounting.view` cannot open the module at all, which is the broken
 *     state we are repairing.
 *
 * Super admins are unaffected either way (Gate::before bypasses permissions).
 */
return new class extends Migration
{
    /**
     * The keys to restore, and the surviving sibling that evidences a role
     * once held the group.
     *
     * @var array<int, array{key: string, group: string, label: string, dangerous: bool, sibling: string}>
     */
    private const RESTORE = [
        ['key' => 'accounting.view',            'group' => 'Accounting', 'label' => 'View chart of accounts, journal, and financial reports', 'dangerous' => false, 'sibling' => 'accounting.reverse_entry'],
        ['key' => 'accounting.manual_entry',    'group' => 'Accounting', 'label' => 'Post manual journal entries',                            'dangerous' => false, 'sibling' => 'accounting.reverse_entry'],
        ['key' => 'accounting.lock_period',     'group' => 'Accounting', 'label' => 'Lock a fiscal period',                                   'dangerous' => false, 'sibling' => 'accounting.reverse_entry'],
        ['key' => 'accounting.unlock_period',   'group' => 'Accounting', 'label' => 'Unlock a locked fiscal period',                          'dangerous' => true,  'sibling' => 'accounting.reverse_entry'],
        ['key' => 'expenses.view',              'group' => 'Expenses',   'label' => 'View expenses',                                          'dangerous' => false, 'sibling' => 'expenses.export'],
        ['key' => 'expenses.create',            'group' => 'Expenses',   'label' => 'Record expenses',                                        'dangerous' => false, 'sibling' => 'expenses.export'],
        ['key' => 'expenses.update',            'group' => 'Expenses',   'label' => 'Edit expenses',                                          'dangerous' => false, 'sibling' => 'expenses.export'],
        ['key' => 'expenses.delete',            'group' => 'Expenses',   'label' => 'Delete expenses',                                        'dangerous' => false, 'sibling' => 'expenses.export'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::RESTORE as $row) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $row['key']],
                [
                    'label'        => $row['label'],
                    'group'        => $row['group'],
                    'description'  => null,
                    'is_dangerous' => $row['dangerous'],
                    'updated_at'   => $now,
                    'created_at'   => $now,
                ],
            );
        }

        // Map key → id once; the inserts above guarantee every one resolves.
        $ids = DB::table('permissions')
            ->whereIn('key', array_merge(
                array_column(self::RESTORE, 'key'),
                array_unique(array_column(self::RESTORE, 'sibling')),
            ))
            ->pluck('id', 'key');

        foreach (self::RESTORE as $row) {
            $permissionId = $ids[$row['key']]    ?? null;
            $siblingId    = $ids[$row['sibling']] ?? null;

            if ($permissionId === null || $siblingId === null) {
                continue;   // sibling never shipped on this install — nothing to infer from
            }

            $roleIds = DB::table('role_permission')
                ->where('permission_id', $siblingId)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                $exists = DB::table('role_permission')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $exists) {
                    DB::table('role_permission')->insert([
                        'role_id'       => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Deliberately irreversible: rolling this back would re-break the
        // installs it exists to repair.
    }
};
