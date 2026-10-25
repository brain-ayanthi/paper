<?php

namespace Database\Seeders\DemoData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class DemoTablePolicy
{
    public const PRESERVE = [
        'users', 'company', 'settings', 'stores', 'store_user', 'roles', 'permissions', 'role_permission',
        'payment_methods', 'payment_method_store', 'commerce_connections', 'commerce_store_mappings',
        'whatsapp_connections', 'whatsapp_message_templates', 'plugins', 'plugin_options',
        'backup_destination_configs', 'backup_schedules', 'backup_logs', 'restore_logs', 'update_logs',
        'install_state', 'migrations', 'personal_access_tokens', 'password_reset_tokens', 'sessions',
        'currencies', 'exchange_rates', 'languages', 'translations', 'receipt_templates', 'terminals',
        'accounts', 'account_groups', 'account_mappings', 'units', 'unit_categories', 'tax_classifications',
        'tax_components', 'tax_groups', 'tax_group_components', 'tax_exemptions', 'drug_schedules',
        'return_reasons', 'stock_adjustment_reasons', 'shift_variance_reasons', 'customer_groups',
        'expense_categories', 'saved_reports', 'scheduled_reports',
    ];

    public const RESET = [
        'ai_chat_history', 'ai_reorder_suggestions', 'ai_usage_logs', 'attachments', 'audit_logs',
        'brands', 'categories', 'cash_drawer_entries', 'channel_orders', 'channel_order_allocations',
        'channel_order_allocation_items', 'channel_order_items', 'commerce_entity_mappings',
        'commerce_outbox_events', 'commerce_sync_runs', 'commerce_webhook_events',
        'customers', 'customer_addresses', 'customer_credit_transactions', 'daily_metrics',
        'expenses', 'failed_jobs', 'import_jobs', 'import_job_errors', 'jobs', 'job_batches',
        'journal_entries', 'journal_lines', 'fiscal_years', 'fiscal_periods', 'notifications',
        'payment_webhook_log', 'posting_failures', 'pos_payment_sessions', 'print_logs',
        'products', 'product_batches', 'product_images', 'product_kit_items', 'product_locations',
        'product_stock_levels', 'product_store_prices', 'product_variants', 'purchases', 'purchase_items',
        'purchase_payments', 'purchase_returns', 'purchase_return_items', 'quotations', 'quotation_activities',
        'quotation_items', 'receipt_public_links', 'recurring_expenses', 'sales', 'sale_discounts',
        'sale_items', 'sale_payments', 'sale_returns', 'sale_return_items', 'scheduled_report_runs',
        'scheduled_task_runs', 'shifts', 'stock_adjustments', 'stock_adjustment_items', 'stock_movements',
        'stock_takes', 'stock_take_items', 'stock_transfers', 'stock_transfer_items', 'store_sale_counters',
        'suppliers', 'sync_logs', 'tax_exemption_certificates', 'whatsapp_logs', 'whatsapp_webhook_events',
        'cache', 'cache_locks',
    ];

    public function plan(): array
    {
        $tables = Schema::getTableListing(DB::getDriverName() === 'sqlite' ? 'main' : DB::connection()->getDatabaseName(), false);
        $unknown = array_diff($tables, self::PRESERVE, self::RESET);
        if ($unknown) {
            throw new RuntimeException('Unclassified tables: '.implode(', ', $unknown).'. Review the demo table policy first.');
        }
        $reset = array_values(array_intersect(self::RESET, $tables));
        $preserve = array_values(array_intersect(self::PRESERVE, $tables));
        $dependencies = [];
        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                $parent = $fk['foreign_table'];
                if (! in_array($parent, $reset, true) || $parent === $table) {
                    continue;
                }
                if (in_array($table, $preserve, true)) {
                    throw new RuntimeException("Protected table {$table} references reset table {$parent}.");
                }
                $dependencies[$parent][] = $table;
            }
        }
        $ordered = [];
        while ($reset) {
            $ready = array_filter($reset, fn ($t) => ! array_intersect($dependencies[$t] ?? [], $reset));
            if (! $ready) {
                throw new RuntimeException('Cyclic reset foreign keys require explicit review.');
            }
            array_push($ordered, ...array_values($ready));
            $reset = array_values(array_diff($reset, $ready));
        }

        return ['reset' => $ordered, 'preserve' => $preserve];
    }

    /** Raw values are hashed, never decrypted, logged, or written to an artifact. */
    public function fingerprints(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $rows = [];
            foreach (DB::table($table)->cursor() as $row) {
                $rows[] = hash('sha256', serialize((array) $row));
            }
            sort($rows, SORT_STRING);
            $result[$table] = hash('sha256', implode('', $rows));
        }

        return $result;
    }
}
