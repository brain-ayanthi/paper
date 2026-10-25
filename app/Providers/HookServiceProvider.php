<?php

namespace App\Providers;

use App\Actions\Accounting\PostCashDrawerEntry;
use App\Actions\Accounting\PostCustomerCreditConversionEntry;
use App\Actions\Accounting\PostCustomerPaymentEntry;
use App\Actions\Accounting\PostExpenseEntry;
use App\Actions\Accounting\PostPurchaseEntry;
use App\Actions\Accounting\PostPurchaseReturnEntry;
use App\Actions\Accounting\PostSaleEntry;
use App\Actions\Accounting\PostSaleReturnEntry;
use App\Actions\Accounting\PostSaleVoidEntry;
use App\Actions\Accounting\PostShiftVarianceEntry;
use App\Actions\Accounting\PostStockAdjustmentEntry;
use App\Actions\Accounting\PostSupplierPaymentEntry;
use App\Actions\Accounting\RepostExpenseEntry;
use App\Actions\Commerce\RecordCommerceOutboxEvent;
use App\Actions\Reports\MarkDailyMetricsStale;
use App\Actions\Reports\RefreshDailyMetrics;
use App\Actions\WhatsApp\SendWhatsAppDocument;
use App\Hooks\HookManager;
use App\Models\CommerceOutboxEvent;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\Accounting\PostingGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wires up the action / filter dispatcher.
 *
 *   - `register()` binds {@see HookManager} as a singleton so every
 *      call into `app(HookManager::class)` (and therefore every
 *      `do_action` / `apply_filters` helper) shares the same registry.
 *
 *   - `boot()` is where built-in listeners belong. Plugin packages get
 *      their own service providers; this one is reserved for the
 *      hooks that ship with core (e.g. audit-log defaults).
 */
class HookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HookManager::class, fn () => new HookManager);
    }

    public function boot(HookManager $hooks): void
    {
        // Commerce outbox â€” these listeners only write local work rows. They
        // never call WooCommerce or Shopify inside a sale/product transaction.
        $hooks->addAction('stock.after_movement', function ($movement) {
            $stockLevel = StockLevel::query()
                ->where('store_id', $movement->store_id)
                ->where('product_id', $movement->product_id)
                ->where('variant_id', $movement->variant_id)
                ->first();

            app(RecordCommerceOutboxEvent::class)->execute(
                CommerceOutboxEvent::TYPE_INVENTORY_SYNC,
                $stockLevel ?? $movement,
                [
                    'store_id' => $movement->store_id,
                    'product_id' => $movement->product_id,
                    'variant_id' => $movement->variant_id,
                ],
                storeId: (int) $movement->store_id,
                dedupeSource: 'movement-'.$movement->getKey(),
                coalescePending: (bool) $stockLevel,
            );
        });

        foreach (['product.after_create', 'product.after_update', 'product.after_delete', 'product.variants.synced', 'product.prices.synced', 'product.locations.synced'] as $hook) {
            $hooks->addAction($hook, function ($product) use ($hook) {
                app(RecordCommerceOutboxEvent::class)->execute(
                    CommerceOutboxEvent::TYPE_PRODUCT_SYNC,
                    $product,
                    ['source_hook' => $hook],
                    'sync_products',
                );
            });
        }

        $queueCatalogProducts = function (iterable $productIds, string $sourceHook, int $sourceId): void {
            $ids = collect($productIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
            if ($ids === []) {
                return;
            }
            $sourceVersion = (string) Str::uuid();

            Product::query()->whereKey($ids)->chunkById(100, function ($products) use ($sourceHook, $sourceId, $sourceVersion): void {
                foreach ($products as $product) {
                    app(RecordCommerceOutboxEvent::class)->execute(
                        CommerceOutboxEvent::TYPE_PRODUCT_SYNC,
                        $product,
                        ['source_hook' => $sourceHook],
                        requiredSetting: 'sync_products',
                        dedupeSource: $sourceHook.'-'.$sourceId.'-'.$sourceVersion,
                        coalescePending: true,
                    );
                }
            });
        };

        $hooks->addAction('category.after_update', function ($category) use ($queueCatalogProducts): void {
            $categoryIds = [$category->getKey(), ...$category->descendantIds()];
            $queueCatalogProducts(
                Product::query()->whereIn('category_id', $categoryIds)->pluck('id'),
                'category.after_update',
                (int) $category->getKey(),
            );
        });
        $hooks->addAction('category.after_delete', function ($category, $reparentedIds = [], $productIds = []) use ($queueCatalogProducts): void {
            $queueCatalogProducts($productIds, 'category.after_delete', (int) $category->getKey());
        });
        $hooks->addAction('categories.reordered', function ($rows) use ($queueCatalogProducts): void {
            $categoryIds = collect($rows)->pluck('id')->map(fn ($id) => (int) $id)->filter()->all();
            $queueCatalogProducts(
                Product::query()->whereIn('category_id', $categoryIds)->pluck('id'),
                'categories.reordered',
                0,
            );
        });
        $hooks->addAction('brand.after_update', function ($brand) use ($queueCatalogProducts): void {
            $queueCatalogProducts(
                Product::query()->where('brand_id', $brand->getKey())->pluck('id'),
                'brand.after_update',
                (int) $brand->getKey(),
            );
        });
        $hooks->addAction('brand.after_delete', function ($brand, $productIds = []) use ($queueCatalogProducts): void {
            $queueCatalogProducts($productIds, 'brand.after_delete', (int) $brand->getKey());
        });

        foreach (['customer.after_create', 'customer.after_update'] as $hook) {
            $hooks->addAction($hook, function ($customer) use ($hook) {
                app(RecordCommerceOutboxEvent::class)->execute(
                    CommerceOutboxEvent::TYPE_CUSTOMER_SYNC,
                    $customer,
                    ['source_hook' => $hook],
                    'sync_customers',
                );
            });
        }

        // ── Admin bell notifications ──────────────────────────────────────
        // Each listener turns a domain event into a database notification for
        // super-admins. Failures here never break the originating operation —
        // HookManager catches listener exceptions.

        $hooks->addAction('updater.update_available', function ($version, $release = null) {
            $key = 'update_available:'.$version;
            if (! admins_have_unread_notification($key)) {
                notify_admins(
                    $key,
                    __('notifications.update.title'),
                    __('notifications.update.message', ['version' => $version]),
                    'download',
                    route('admin.settings.updates.index'),
                    'updater.check',
                );
            }
        });

        $hooks->addAction('backup.failed', function ($log = null, $e = null) {
            notify_admins(
                'backup_failed:'.(optional($log)->id ?? ''),
                __('notifications.backup.title'),
                __('notifications.backup.message'),
                'database',
                route('admin.settings.backup.edit'),
                'backup.run',
            );
        });

        $hooks->addAction('sale.after_void', function ($sale) {
            $number = $sale->number ?? $sale->sale_number ?? ('#'.$sale->id);
            notify_admins(
                'sale_voided:'.$sale->id,
                __('notifications.void.title'),
                __('notifications.void.message', ['number' => $number]),
                'refund',
                url('/admin/sales/'.$sale->id),
                'sales.view_all',
            );
        });

        $hooks->addAction('sale.after_return', function ($return) {
            $saleId = $return->sale_id ?? null;
            $number = optional($return->sale ?? null)->number ?? ('#'.($saleId ?? $return->id));
            notify_admins(
                'sale_returned:'.$return->id,
                __('notifications.return.title'),
                __('notifications.return.message', ['number' => $number]),
                'refund',
                $saleId ? url('/admin/sales/'.$saleId) : null,
                'sales.view_all',
            );
        });

        // ── Daily-metrics upkeep ──────────────────────────────────────────
        // Keep the daily_metrics pre-aggregation (docs §13) consistent with
        // the trading it summarises. Closing a shift finalises that store's
        // day(s); a later void/return can mutate an already-settled day, so we
        // flag it stale for the nightly refresh (and the reader serves live in
        // the meantime). All best-effort — HookManager swallows failures so
        // these never break the originating operation.

        $hooks->addAction('shift.after_close', function ($shift) {
            $storeId = (int) ($shift->store_id ?? 0);
            if (! $storeId) {
                return;
            }
            $refresh = app(RefreshDailyMetrics::class);
            $dates = array_unique(array_filter([
                optional($shift->opened_at)->format('Y-m-d'),
                optional($shift->closed_at)->format('Y-m-d'),
            ]));
            foreach ($dates as $date) {
                $refresh($storeId, $date);
            }
        });

        $hooks->addAction('sale.after_void', function ($sale) {
            if (! empty($sale->store_id) && ! empty($sale->sale_date)) {
                app(MarkDailyMetricsStale::class)((int) $sale->store_id, $sale->sale_date);
            }
        });

        $hooks->addAction('sale.after_return', function ($return) {
            if (! empty($return->store_id) && ! empty($return->return_date)) {
                app(MarkDailyMetricsStale::class)((int) $return->store_id, $return->return_date);
            }
        });

        // ── Accounting: auto-post journal entries ─────────────────────────
        // A completed sale / received purchase generates its double-entry
        // journal (docs/features/accounting.md §5). Best-effort: a posting
        // failure is logged but never breaks checkout or goods-receipt — the
        // entry can be backfilled (the poster is idempotent per reference).

        $hooks->addAction('sale.after_complete', function ($sale) {
            PostingGuard::attempt('sale.after_complete', PostSaleEntry::class, fn () => app(PostSaleEntry::class)($sale), $sale);
        });

        // Automatic receipts are queued only after the surrounding sale
        // transaction commits. Provider outages can never roll back checkout.
        $hooks->addAction('sale.after_complete', function ($sale) {
            $connection = WhatsAppConnection::forStore((int) $sale->store_id);
            if (! $connection
                || $connection->setting('mode') !== 'automatic'
                || ! $connection->setting('auto_send_receipts', false)
                || ! $connection->setting('templates.sale_receipt.name')
                || ! $sale->customer_id) {
                return;
            }

            DB::afterCommit(function () use ($sale): void {
                try {
                    app(SendWhatsAppDocument::class)->execute(
                        $sale->fresh(),
                        null,
                        $sale->created_by ? User::query()->find($sale->created_by) : null,
                    );
                } catch (Throwable $e) {
                    report($e);
                }
            });
        });

        $hooks->addAction('purchase.after_receive', function ($purchase) {
            PostingGuard::attempt('purchase.after_receive', PostPurchaseEntry::class, fn () => app(PostPurchaseEntry::class)($purchase), $purchase);
        });

        $hooks->addAction('expense.after_create', function ($expense) {
            PostingGuard::attempt('expense.after_create', PostExpenseEntry::class, fn () => app(PostExpenseEntry::class)($expense), $expense);
        });

        $hooks->addAction('customer_payment.after_create', function ($inserted, $customer, $applied = null, $credit = null) {
            PostingGuard::attempt('customer_payment.after_create', PostCustomerPaymentEntry::class, fn () => app(PostCustomerPaymentEntry::class)((array) $inserted, $customer, $applied, $credit), $customer);
        });

        $hooks->addAction('customer_credit.converted', function ($conversion) {
            PostingGuard::attempt('customer_credit.converted', PostCustomerCreditConversionEntry::class, fn () => app(PostCustomerCreditConversionEntry::class)($conversion), $conversion);
        });

        $hooks->addAction('supplier_payment.after_create', function ($inserted, $supplier) {
            $rows = (array) $inserted;
            $reference = $rows[0] ?? $supplier;

            PostingGuard::attempt('supplier_payment.after_create', PostSupplierPaymentEntry::class, fn () => app(PostSupplierPaymentEntry::class)($rows, $supplier), $reference);
        });

        $hooks->addAction('sale.after_void', function ($sale) {
            PostingGuard::attempt('sale.after_void', PostSaleVoidEntry::class, fn () => app(PostSaleVoidEntry::class)($sale), $sale);
        });

        $hooks->addAction('sale.after_return', function ($return) {
            PostingGuard::attempt('sale.after_return', PostSaleReturnEntry::class, fn () => app(PostSaleReturnEntry::class)($return), $return);
        });

        $hooks->addAction('stock_adjustment.after_post', function ($adjustment) {
            PostingGuard::attempt('stock_adjustment.after_post', PostStockAdjustmentEntry::class, fn () => app(PostStockAdjustmentEntry::class)($adjustment), $adjustment);
        });

        $hooks->addAction('shift.after_close', function ($shift) {
            PostingGuard::attempt('shift.after_close', PostShiftVarianceEntry::class, fn () => app(PostShiftVarianceEntry::class)($shift), $shift);
        });

        foreach (['cash_drawer.pay_in_recorded', 'cash_drawer.pay_out_recorded'] as $drawerHook) {
            $hooks->addAction($drawerHook, function ($entry) use ($drawerHook) {
                PostingGuard::attempt($drawerHook, PostCashDrawerEntry::class, fn () => app(PostCashDrawerEntry::class)($entry), $entry);
            });
        }

        // Sending goods back to a supplier was moving stock and the supplier
        // balance while the books carried on as if the purchase were
        // untouched — inventory, input tax and A/P all overstated by the value
        // of every return ever made (defect #101). `SOURCE_PURCHASE_RETURN`
        // and the hook both already existed; only the poster and this
        // listener were missing.
        $hooks->addAction('purchase.after_return', function ($return) {
            PostingGuard::attempt('purchase.after_return', PostPurchaseReturnEntry::class, fn () => app(PostPurchaseReturnEntry::class)($return), $return);
        });

        // An expense could be edited or deleted with the books left showing
        // the original figures for ever (defects #102 / #103). Correcting them
        // is a reversal plus a fresh posting — a posted entry is an immutable
        // historical record and is never edited in place.
        $hooks->addAction('expense.after_update', function ($expense) {
            PostingGuard::attempt('expense.after_update', RepostExpenseEntry::class, fn () => app(RepostExpenseEntry::class)->repost($expense), $expense);
        });

        $hooks->addAction('expense.after_delete', function ($expense) {
            PostingGuard::attempt('expense.after_delete', RepostExpenseEntry::class, fn () => app(RepostExpenseEntry::class)->reverse($expense), $expense);
        });

        // There are two ways to settle a credit sale, and only one of them
        // reached the books (defect #327). The multi-sale Customer Payments
        // screen fires `customer_payment.after_create`, which is wired above;
        // settling from the sale's own page fires `sale_payment.recorded` and
        // nothing listened, so the cash arrived and accounts receivable was
        // never cleared — A/R stayed overstated for every sale settled that
        // way. This fires the same poster for the single payment row.
        //
        // Safe against double-posting: `sale_payment.recorded` is raised ONLY
        // by RecordCustomerPayment (settle-later). A tender taken at checkout
        // is already inside the sale's own entry via PostSaleEntry.
        $hooks->addAction('sale_payment.recorded', function ($payment, $sale) {
            $customer = $sale->customer ?? ($sale->customer_id ? Customer::find($sale->customer_id) : null);

            if (! $customer) {
                return;   // a walk-in cannot carry a balance to settle
            }

            PostingGuard::attempt(
                'sale_payment.recorded',
                PostCustomerPaymentEntry::class,
                fn () => app(PostCustomerPaymentEntry::class)([$payment], $customer),
                $payment,
            );
        });
    }
}
