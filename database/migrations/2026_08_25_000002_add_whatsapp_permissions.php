<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach ($this->permissions() as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                $permission + ['created_at' => $now, 'updated_at' => $now],
            );
        }
        User::bumpPermissionsVersion();
    }

    public function down(): void
    {
        $keys = array_column($this->permissions(), 'key');
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        User::bumpPermissionsVersion();
    }

    private function permissions(): array
    {
        return [
            ['key' => 'whatsapp.send_receipt', 'group' => 'WhatsApp', 'label' => 'Send receipts and quotations', 'description' => null, 'is_dangerous' => false],
            ['key' => 'whatsapp.send_statement', 'group' => 'WhatsApp', 'label' => 'Send customer statements', 'description' => null, 'is_dangerous' => false],
            ['key' => 'whatsapp.send_supplier_voucher', 'group' => 'WhatsApp', 'label' => 'Send supplier payment vouchers', 'description' => null, 'is_dangerous' => false],
            ['key' => 'whatsapp.send_bulk', 'group' => 'WhatsApp', 'label' => 'Send bulk WhatsApp messages', 'description' => null, 'is_dangerous' => true],
            ['key' => 'whatsapp.logs.view', 'group' => 'WhatsApp', 'label' => 'View WhatsApp delivery logs', 'description' => null, 'is_dangerous' => false],
            ['key' => 'whatsapp.logs.retry', 'group' => 'WhatsApp', 'label' => 'Retry failed WhatsApp deliveries', 'description' => null, 'is_dangerous' => false],
            ['key' => 'settings.whatsapp.update', 'group' => 'WhatsApp', 'label' => 'Update WhatsApp settings and templates', 'description' => null, 'is_dangerous' => false],
            ['key' => 'settings.whatsapp.credentials', 'group' => 'WhatsApp', 'label' => 'Manage WhatsApp credentials', 'description' => null, 'is_dangerous' => true],
        ];
    }
};
