<?php

namespace Database\Seeders;

use App\Actions\Accounting;
use App\Actions\Expenses\CreateExpense;
use App\Actions\Expenses\SyncExpenseTillPayOut;
use App\Actions\Inventory;
use App\Actions\Purchases;
use App\Actions\Quotations\ChangeQuotationStatus;
use App\Actions\Quotations\SaveQuotation;
use App\Actions\Reports\RefreshDailyMetrics;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\PriceCart;
use App\Actions\Sales\RecordCustomerPayment;
use App\Actions\Sales\RecordCustomerPayments;
use App\Actions\Sales\RecordSaleReturn;
use App\Actions\Shifts;
use App\Hooks\HookManager;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounting\AccountMappingResolver;
use App\Support\Money;
use Database\Seeders\DemoData\DemoPharmacySupplement;
use Database\Seeders\DemoData\DemoStockTopUp;
use Database\Seeders\DemoData\DemoStoreReset;
use Database\Seeders\DemoData\DemoTablePolicy;
use Database\Seeders\DemoData\RealisticCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/** Explicit maintenance-only replacement, never called by DatabaseSeeder or the installer. */
class RealisticDemoSeeder extends Seeder
{
    private Carbon $anchor;

    private User $actor;

    private string $scale;

    private array $skus = [];

    private array $customers = [];

    private array $suppliers = [];

    private array $categories = [];

    private array $brands = [];

    private int $timeSlot = 0;

    private int $timeSlots = 1;

    public function run(): void
    {
        if (! config('demo_rebuild.authorized', false)
            || (! app()->environment('testing') && (! pos_is_demo() || ! app()->isDownForMaintenance()))) {
            throw new RuntimeException('Use pos:demo-rebuild in demo maintenance mode.');
        }
        $policy = new DemoTablePolicy;
        $plan = $policy->plan();
        $this->actor = User::query()->where('is_super_admin', true)->firstOrFail();
        $storeReset = new DemoStoreReset;
        $storePlan = config('demo_rebuild.reset_stores', false) ? $storeReset->plan() : null;
        $stores = Store::query()->when($storePlan, fn ($q) => $q->whereIn('id', $storePlan['ids']), fn ($q) => $q->where('is_active', true))->orderBy('id')->get();
        $currency = Company::current()?->base_currency_code;
        if (! $currency || $stores->isEmpty() || (! $storePlan && $stores->contains(fn ($s) => $s->currency_code !== $currency))) {
            throw new RuntimeException('Requires an installed company and active stores using its base currency.');
        }
        $this->scale = (string) config('demo_rebuild.price_scale', '1');
        if (! preg_match('/^\d+(\.\d{1,4})?$/D', $this->scale) || bccomp($this->scale, '0', 4) <= 0) {
            throw new RuntimeException('Price scale must be a positive decimal.');
        }
        foreach (['units', 'account_mappings', 'accounts', 'expense_categories', 'return_reasons'] as $table) {
            if (! DB::table($table)->exists()) {
                throw new RuntimeException("Install lookup data first: {$table} is empty.");
            }
        }
        if (! DB::table('units')->where('code', 'pc')->exists()) {
            throw new RuntimeException('The piece unit (pc) must exist.');
        }
        // DELETE is transactional; TRUNCATE and disabling FK checks are deliberately avoided.
        if (DB::getDriverName() === 'mysql') {
            $engines = DB::select('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?', ['BASE TABLE']);
            foreach ($engines as $row) {
                if (strtoupper($row->ENGINE ?? '') !== 'INNODB') {
                    throw new RuntimeException('Demo rebuild requires transactional InnoDB tables.');
                }
            }
        }
        $this->anchor = Carbon::now()->startOfMinute();
        $oldClock = Carbon::getTestNow();
        $oldHooks = app(HookManager::class);
        $before = $policy->fingerprints($plan['preserve']);
        // No receipt delivery, commerce outbox, plugins, audit observers, or external provider hooks.
        // Accounting is called explicitly below, and failures roll back the entire rebuild.
        app()->instance(HookManager::class, new HookManager);
        try {
            Model::withoutEvents(function () use ($plan, $policy, $before, $stores, $currency, $storeReset, $storePlan): void {
                DB::transaction(function () use ($plan, $policy, $before, $stores, $currency, $storeReset, $storePlan): void {
                    $this->command?->info('Clearing old demo data (changes remain uncommitted until validation passes).');
                    foreach ($plan['reset'] as $table) {
                        // Self-referencing nullable FKs can otherwise obstruct a bulk DELETE.
                        foreach (Schema::getForeignKeys($table) as $fk) {
                            if ($fk['foreign_table'] === $table) {
                                DB::table($table)->update(array_fill_keys($fk['columns'], null));
                            }
                        }
                        DB::table($table)->delete();
                    }
                    if ($storePlan) {
                        $storeReset->apply($storePlan);
                        $stores = Store::query()->whereIn('id', $storePlan['ids'])->orderBy('id')->get();
                        // Only the explicit store reset may change stores. Protect the resulting rows
                        // throughout the rest of seeding; all other original fingerprints stay intact.
                        $before['stores'] = $policy->fingerprints(['stores'])['stores'];
                    }
                    $this->at(190);
                    $this->command?->info('Creating catalogue, customers and suppliers.');
                    $this->catalog();
                    app(DemoPharmacySupplement::class)->run($this->command);
                    $this->contacts($stores->first(), $currency);
                    foreach ($stores as $store) {
                        $this->prepareStore($store);
                    }
                    // Advance all stores together so shared customer/loyalty ledgers remain chronological.
                    for ($day = 180; $day >= 0; $day--) {
                        if ($day % 15 === 0) {
                            $this->command?->info('Generating trading history: day '.(181 - $day).' of 181 across '.$stores->count().' stores.');
                        }
                        $this->timeSlots = $stores->count();
                        foreach ($stores as $slot => $store) {
                            $this->timeSlot = $slot;
                            $this->tradeDay($store, $day);
                        }
                    }
                    $this->timeSlot = 0;
                    $this->timeSlots = 1;
                    foreach ($stores as $store) {
                        $this->command?->info('Finalizing store '.$store->code.'.');
                        $this->finishStore($store);
                    }
                    $this->at(0, 23, 59);
                    $this->transfer($stores->all());
                    app(DemoStockTopUp::class)->run('5000', $this->command);
                    $this->command?->info('Validating stock, batches, accounting and preserved data.');
                    $this->validateDataset();
                    if ($before !== $policy->fingerprints($plan['preserve'])) {
                        throw new RuntimeException('Protected data changed. Entire demo rebuild rolled back.');
                    }
                });
            });
        } finally {
            Carbon::setTestNow($oldClock);
            app()->instance(HookManager::class, $oldHooks);
            Company::forgetCurrent();
            forget_default_store_id();
        }
    }

    private function at(int $days, int $hour = 9, int $minute = 0): void
    {
        // Give stores successive trading windows within the same day. Shared ledgers
        // never travel backwards in time when generating a multi-store dataset.
        $minutes = 480 + intdiv(($hour * 60 + $minute - 480) + $this->timeSlot * 720, $this->timeSlots);
        $when = $this->anchor->copy()->subDays($days)->startOfDay()->addMinutes($minutes);
        Carbon::setTestNow($when->min($this->anchor));
    }

    private function money(string $amount): string
    {
        return Money::round(bcmul($amount, $this->scale, 8));
    }

    private function insert(string $table, array $values): int
    {
        return DB::table($table)->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
    }

    private function catalog(): void
    {
        $this->skus = $this->categories = $this->brands = [];
        $unit = DB::table('units')->where('code', 'pc')->value('id');
        foreach (RealisticCatalog::products() as $index => $row) {
            $category = $this->categories[$row['category']] ??= $this->insert('categories', [
                'name' => $row['category'], 'slug' => Str::slug($row['category']), 'is_active' => true,
            ]);
            $brand = $this->brands[$row['brand']] ??= $this->insert('brands', [
                'name' => $row['brand'], 'slug' => Str::slug($row['brand']), 'is_active' => true,
            ]);
            $sku = 'DEMO-'.strtoupper(substr(Str::slug($row['brand']), 0, 10)).'-'.sprintf('%03d', $index + 1);
            $price = $this->money($row['price']);
            $cost = bcmul($price, '0.68', 4);
            $attributes = $row['attributes'] ?? [];
            $productId = $this->insert('products', [
                'sku' => $sku, 'name' => $row['brand'].' '.$row['name'], 'slug' => Str::slug($sku),
                'barcode' => $sku, 'category_id' => $category, 'brand_id' => $brand, 'unit_id' => $unit,
                'type' => $attributes ? 'variant' : 'simple', 'cost_price' => $cost, 'selling_price' => $price,
                'mrp' => bcmul($price, '1.10', 4), 'track_stock' => true,
                'track_batches' => $row['batch'], 'track_expiry' => $row['expiry'],
                'reorder_level' => '15', 'reorder_quantity' => '500', 'is_active' => true,
                'is_featured' => $index % 12 === 0, 'created_by' => $this->actor->id,
                'meta' => $attributes ? json_encode(['variant_attributes' => $attributes], JSON_THROW_ON_ERROR) : null,
            ]);
            $base = ['product_id' => $productId, 'variant_id' => null, 'sku' => $sku,
                'price' => $price, 'cost' => $cost, 'batch' => $row['batch'], 'expiry' => $row['expiry'],
                'index' => $index, 'category' => array_search($row['category'], array_keys($this->categories), true)];
            if (! $attributes) {
                $this->skus[] = $base;

                continue;
            }
            $v = 0;
            foreach ($attributes[0]['values'] as $a) {
                foreach ($attributes[1]['values'] as $b) {
                    $factor = $row['variant_multipliers'][$v] ?? bcadd('1', bcmul((string) $v, '0.15', 4), 4);
                    $variantPrice = bcmul($price, $factor, 4);
                    $variantCost = bcmul($cost, $factor, 4);
                    $variantSku = $sku.'-'.(++$v);
                    $variantId = $this->insert('product_variants', [
                        'product_id' => $productId, 'sku' => $variantSku, 'barcode' => $variantSku,
                        'attributes' => json_encode([$attributes[0]['name'] => $a, $attributes[1]['name'] => $b], JSON_THROW_ON_ERROR),
                        'cost_price' => $variantCost, 'selling_price' => $variantPrice,
                        'mrp' => bcmul($variantPrice, '1.10', 4), 'is_active' => true,
                    ]);
                    $this->skus[] = array_replace($base, ['variant_id' => $variantId, 'sku' => $variantSku, 'price' => $variantPrice, 'cost' => $variantCost]);
                }
            }
        }
        foreach (['Breakfast Essentials Gift Box', 'Office Stationery Starter Kit'] as $i => $name) {
            $components = $i === 0 ? [30, 34, 37] : [110, 111, 114];
            $total = '0';
            foreach ($components as $component) {
                $total = bcadd($total, $this->skus[$component]['price'], 4);
            }
            $id = $this->insert('products', ['sku' => 'DEMO-KIT-'.($i + 1), 'name' => $name,
                'slug' => Str::slug($name), 'unit_id' => $unit, 'type' => 'kit', 'selling_price' => $total,
                'track_stock' => false, 'is_active' => true]);
            foreach ($components as $order => $component) {
                $this->insert('product_kit_items', ['parent_product_id' => $id,
                    'component_product_id' => $this->skus[$component]['product_id'], 'quantity' => '1', 'sort_order' => $order]);
            }
        }
    }

    private function contacts(Store $store, string $currency): void
    {
        $this->customers = $this->suppliers = [];
        $first = ['Aarav', 'Priya', 'Rohan', 'Meera', 'Arjun', 'Ananya', 'Kabir', 'Diya', 'Vikram', 'Sana'];
        $last = ['Sharma', 'Patel', 'Nair', 'Shah'];
        foreach ($last as $j => $surname) {
            foreach ($first as $i => $name) {
                $this->at(188 - $j * 20 - $i * 2);
                $id = $this->insert('customers', ['code' => sprintf('C-%03d', $j * 10 + $i + 1),
                    'name' => $name.' '.$surname, 'email' => strtolower($name.'.'.$surname).'@example.test',
                    'first_store_id' => $store->id, 'is_active' => true, 'credit_limit' => $this->money('2500'),
                    'notes' => 'Fictional demo customer', 'created_by' => $this->actor->id]);
                $this->insert('customer_addresses', ['customer_id' => $id, 'label' => 'Home',
                    'line1' => (12 + $i).' '.['Park Road', 'Lake Avenue', 'Market Street', 'Garden Lane'][$j],
                    'city' => 'Bengaluru', 'country_code' => 'IN', 'is_default' => true]);
                $this->customers[] = $id;
            }
        }
        $this->at(190);
        foreach (['Cedar Grove Food Distributors', 'Sunrise Beverage Supply', 'Orchard Pantry Wholesale',
            'Evergreen Personal Care', 'Bright Home Supplies', 'Paper Trail Stationery'] as $i => $name) {
            $this->suppliers[] = $this->insert('suppliers', ['code' => 'SUP-'.($i + 1), 'name' => $name,
                'business_name' => $name, 'contact_person' => $first[$i].' '.$last[$i % 4],
                'email' => 'orders'.($i + 1).'@example.test', 'default_currency_code' => $currency,
                'payment_terms_days' => 30, 'is_active' => true, 'created_by' => $this->actor->id]);
        }
    }

    private function cashMethod(Store $store): int
    {
        $method = DB::table('payment_methods')->where('is_active', true)->where('type', 'cash')
            ->where(fn ($q) => $q->whereNull('provider')->orWhereIn('provider', ['', 'none']))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_method_store')
                ->whereColumn('payment_method_store.payment_method_id', 'payment_methods.id')
                ->where('payment_method_store.store_id', $store->id)->where('is_enabled', false))
            ->orderBy('id')->first();
        if (! $method) {
            throw new RuntimeException('An active manual cash payment method is required.');
        }

        return (int) $method->id;
    }

    private function prepareStore(Store $store): void
    {
        $this->at(185);
        foreach ($this->skus as $i => $sku) {
            $this->insert('product_locations', ['store_id' => $store->id, 'product_id' => $sku['product_id'],
                'variant_id' => $sku['variant_id'], 'kind' => 'primary', 'is_primary' => true,
                'aisle' => 'A'.($sku['category'] + 1), 'rack' => 'R'.(intdiv($i, 12) + 1),
                'shelf' => 'S'.($i % 4 + 1), 'bin' => 'B'.($i % 3 + 1), 'sort_order' => $i]);
            $this->insert('product_store_prices', ['store_id' => $store->id, 'product_id' => $sku['product_id'],
                'variant_id' => $sku['variant_id'], 'selling_price' => $sku['price'], 'cost_price' => $sku['cost'],
                'mrp' => bcmul($sku['price'], '1.10', 4)]);
        }
    }

    private function tradeDay(Store $store, int $day): void
    {
        $cash = $this->cashMethod($store);
        $products = Product::with(['taxGroup.components', 'category.taxGroup.components'])->get()->keyBy('id');
        $categoryName = ['Maintenance & Repairs', 'Transport', 'Supplies'][$day % 3];
        $expenseCategory = DB::table('expense_categories')->where('name', $categoryName)->value('id')
            ?? DB::table('expense_categories')->orderBy('id')->value('id');
        $this->at($day, 8);
        if (in_array($day, [180, 120, 60, 15, 0], true)) {
            $this->purchase($store, $day, $cash);
        }
        $this->at($day, 9);
        $shift = app(Shifts\OpenShift::class)(['opening_cash' => $this->money('200')], $this->actor, $store);
        $salesCount = now()->isWeekend() ? 7 : 4;
        for ($n = 0; $n < $salesCount; $n++) {
            $this->at($day, 10 + $n, 12 + $n);
            $lines = [];
            $resolved = [];
            for ($k = 0; $k < 2 + $n % 3; $k++) {
                // Reserve the first six SKUs for controlled low/out-of-stock cases.
                $sku = $this->skus[6 + (($day * 7 + $n * 11 + $k * 13) % (count($this->skus) - 6))];
                $batch = $sku['batch'] ? DB::table('product_batches')->where('store_id', $store->id)
                    ->where('product_id', $sku['product_id'])->where('variant_id', $sku['variant_id'])
                    ->where('quantity', '>=', 3)->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))
                    ->orderBy('expiry_date')->orderBy('id')->value('id') : null;
                $line = ['product_id' => $sku['product_id'], 'variant_id' => $sku['variant_id'],
                    'batch_id' => $batch, 'quantity' => (string) (1 + ($day + $n + $k) % 3)];
                $lines[] = $line;
                $resolved[] = ['raw' => $line, 'product' => $products[$sku['product_id']], 'index' => $k];
            }
            $priced = app(PriceCart::class)->handle($resolved, $store, $this->actor);
            $customer = $n % 2 === 0 ? $this->customers[($day + $n) % count($this->customers)] : null;
            // Only attach customers who already existed on the sale date.
            if ($customer && DB::table('customers')->where('id', $customer)->value('created_at') > now()->toDateTimeString()) {
                $customer = $this->customers[0];
            }
            $credit = $customer !== null && $day % 30 === 0 && $n === 0;
            $sale = app(CompleteSale::class)(['store_id' => $store->id, 'customer_id' => $customer,
                'currency_code' => $store->currency_code, 'sale_date' => now()->toDateString(),
                'origin' => 'cashier', 'notes' => 'Neighbourhood retail demo'], $lines,
                $credit ? [] : [['payment_method_id' => $cash, 'amount' => $priced->grandTotal,
                    'tendered_amount' => $priced->grandTotal, 'reference' => 'DEMO-CASH']], $this->actor);
            app(Accounting\PostSaleEntry::class)($sale);
            if ($day % 45 === 0 && $n === 1) {
                $return = app(RecordSaleReturn::class)(['sale_id' => $sale->id,
                    'reason_code_id' => DB::table('return_reasons')->orderBy('id')->value('id'),
                    'refund_method_id' => $cash, 'restock' => true, 'return_date' => now()->toDateString(),
                    'notes' => 'Unopened item returned with receipt',
                    'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'restock' => true]]], $this->actor);
                app(Accounting\PostSaleReturnEntry::class)($return);
            }
        }
        if ($day % 7 === 0) {
            $this->at($day, 17);
            $expense = app(CreateExpense::class)(['category_id' => $expenseCategory,
                'expense_date' => now()->toDateString(), 'amount' => $this->money((string) (35 + $day % 90)),
                'payment_method_id' => $cash, 'description' => ['Shop cleaning service', 'Local delivery fuel', 'Packing supplies'][$day % 3]], $this->actor, $store->id);
            app(Accounting\PostExpenseEntry::class)($expense);
            app(SyncExpenseTillPayOut::class)($expense, $this->actor);
        }
        if ($day % 30 === 0) {
            $sku = $this->skus[110];
            $quotation = app(SaveQuotation::class)->create(['store_id' => $store->id,
                'customer_id' => $this->customers[0], 'issue_date' => now()->toDateString(),
                'valid_until' => now()->addDays(14)->toDateString(), 'notes' => 'Office stationery replenishment',
                'items' => [['product_id' => $sku['product_id'], 'quantity' => '12']]], $this->actor);
            if ($day % 60 === 0) {
                $status = app(ChangeQuotationStatus::class);
                $quotation = $status->send($quotation, $this->actor);
                if ($day % 120 === 0) {
                    $status->accept($quotation, $this->actor);
                }
            }
        }
        $this->at($day, 20);
        $totals = app(Shifts\ComputeShiftTotals::class)($shift);
        app(Shifts\CloseShift::class)($shift, ['closing_cash_counted' => $totals['expected_cash']], $this->actor);
        app(RefreshDailyMetrics::class)($store->id, now()->toDateString());
    }

    private function finishStore(Store $store): void
    {
        $cash = $this->cashMethod($store);
        $expenseCategory = DB::table('expense_categories')->orderBy('id')->value('id');
        $this->at(0, 23, 59);
        $this->lowStock($store);
        foreach ([false, true] as $cancel) {
            $sku = $this->skus[110];
            $draft = app(Purchases\CreatePurchase::class)(['store_id' => $store->id,
                'supplier_id' => $this->suppliers[5], 'purchase_date' => now()->toDateString(),
                'currency_code' => $store->currency_code, 'notes' => 'Next stationery delivery'],
                [['product_id' => $sku['product_id'], 'quantity' => '48', 'unit_cost' => $sku['cost']]], $this->actor);
            if ($cancel) {
                app(Purchases\CancelPurchase::class)($draft, $this->actor, 'Duplicate order cancelled');
            }
        }
        $openSale = Sale::where('store_id', $store->id)->where('balance_due', '>', 0)->first();
        if ($openSale) {
            $payment = app(RecordCustomerPayment::class)($openSale,
                ['payment_method_id' => $cash, 'amount' => bcdiv((string) $openSale->balance_due, '2', 4),
                    'reference' => 'DEMO-ACCOUNT-PAYMENT'], $this->actor);
            app(Accounting\PostCustomerPaymentEntry::class)([$payment], $openSale->customer);
        }
        $customer = Customer::findOrFail($this->customers[1]);
        if ((int) $customer->first_store_id === (int) $store->id) {
            $this->customerAdvance($customer, $cash);
        }
        $take = app(Inventory\CreateStockTake::class)(['store_id' => $store->id,
            'take_date' => now()->toDateString(), 'name' => 'Monthly shelf count'], $this->actor);
        DB::table('stock_take_items')->where('stock_take_id', $take->id)->update(['counted_quantity' => DB::raw('expected_quantity')]);
        app(Inventory\PostStockTake::class)($take, $this->actor);
        $this->insert('recurring_expenses', ['store_id' => $store->id, 'name' => 'Monthly shop rent',
            'category_id' => $expenseCategory, 'amount' => $this->money('750'), 'frequency' => 'monthly',
            'start_date' => now()->toDateString(), 'is_active' => false, 'created_by' => $this->actor->id,
            'description' => 'Paused example; activate only when ready to generate expenses.']);
    }

    private function customerAdvance(Customer $customer, int $cash): void
    {
        // Match PostCustomerPaymentEntry's routing for a payment without a sale.
        $storeId = (int) (current_store_id() ?: Store::query()->value('id'));
        try {
            app(AccountMappingResolver::class)->resolve('customer_advances', $storeId);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'No account mapped for key [customer_advances].') {
                throw $exception;
            }
            $this->command?->warn('Skipping optional customer advance: customer_advances is not mapped. Existing accounting settings are unchanged.');

            return;
        }
        $deposit = $this->money('25');
        $rows = app(RecordCustomerPayments::class)([
            'customer_id' => $customer->id, 'payment_method_id' => $cash,
            'payment_date' => now()->toDateString(), 'amount' => $deposit,
            'reference' => 'DEMO-ADVANCE'], [], $this->actor);
        app(Accounting\PostCustomerPaymentEntry::class)($rows, $customer->fresh(), '0', $deposit);
    }

    private function purchase(Store $store, int $day, int $cash): void
    {
        foreach (array_chunk($this->skus, 15) as $chunkIndex => $chunk) {
            $lines = [];
            foreach ($chunk as $sku) {
                $qty = $sku['index'] < 6 ? ($day === 180 ? '20' : '0') : (string) (350 + ($sku['index'] % 5) * 75 + intdiv($day, 15) * 10);
                if ($qty === '0') {
                    continue;
                }
                $lines[] = ['product_id' => $sku['product_id'], 'variant_id' => $sku['variant_id'],
                    'quantity' => $qty, 'unit_cost' => bcmul($sku['cost'], $day >= 120 ? '0.96' : '1', 4),
                    'batch_number' => $sku['batch'] ? 'LOT-'.now()->format('ymd').'-'.$store->id.'-'.$sku['sku'] : null,
                    'manufacture_date' => $sku['batch'] ? now()->subDays(20)->toDateString() : null,
                    'expiry_date' => $sku['expiry'] ? now()->addDays($sku['index'] < 6 ? 210 : 365)->toDateString() : null,
                    'batch_selling_price' => $sku['batch'] ? $sku['price'] : null,
                    'batch_mrp' => $sku['batch'] ? bcmul($sku['price'], '1.10', 4) : null];
            }
            if (! $lines) {
                continue;
            }
            $supplier = $this->suppliers[$chunkIndex % count($this->suppliers)];
            $purchase = app(Purchases\CreatePurchase::class)(['store_id' => $store->id, 'supplier_id' => $supplier,
                'purchase_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
                'currency_code' => $store->currency_code, 'exchange_rate_to_base' => '1',
                'supplier_invoice_number' => 'INV-'.$store->id.'-'.$day.'-'.$chunkIndex,
                'notes' => 'Scheduled wholesale replenishment'], $lines, $this->actor);
            $purchase = app(Purchases\ReceivePurchase::class)($purchase, $this->actor, false);
            app(Accounting\PostPurchaseEntry::class)($purchase);
            if ($chunkIndex % 3 !== 2) {
                $amount = $chunkIndex % 3 === 0 ? (string) $purchase->grand_total : bcdiv((string) $purchase->grand_total, '2', 4);
                $payments = app(Purchases\RecordSupplierPayment::class)(['supplier_id' => $supplier, 'store_id' => $store->id,
                    'payment_method_id' => $cash, 'payment_date' => now()->toDateString(), 'amount' => $amount,
                    'reference' => 'DEMO-WHOLESALE'], [['purchase_id' => $purchase->id, 'amount' => $amount]], $this->actor);
                app(Accounting\PostSupplierPaymentEntry::class)($payments);
            }
            if ($day === 60 && $chunkIndex === 1) {
                $return = app(Purchases\CreatePurchaseReturn::class)($purchase->fresh(), ['return_date' => now()->toDateString(),
                    'notes' => 'Damaged outer packaging returned to supplier',
                    'items' => [['purchase_item_id' => $purchase->items()->first()->id, 'quantity' => '2', 'restock' => true]]], $this->actor);
                app(Accounting\PostPurchaseReturnEntry::class)($return);
            }
        }
    }

    private function lowStock(Store $store): void
    {
        $items = [];
        foreach (array_slice($this->skus, 0, 6) as $i => $sku) {
            $batch = DB::table('product_batches')->where('store_id', $store->id)->where('product_id', $sku['product_id'])->first();
            $quantity = DB::table('product_stock_levels')->where('store_id', $store->id)->where('product_id', $sku['product_id'])->value('quantity');
            $target = $i < 3 ? '0' : (string) ($i - 1);
            $items[] = ['product_id' => $sku['product_id'], 'batch_id' => $batch?->id,
                'quantity_delta' => bcsub($target, (string) $quantity, 4), 'unit_cost' => (string) ($batch?->cost_price ?? $sku['cost'])];
        }
        $adjustment = app(Inventory\CreateStockAdjustment::class)(['store_id' => $store->id,
            'adjustment_date' => now()->toDateString(), 'reason' => 'Damaged packaging removed during shelf inspection',
            'items' => $items], $this->actor);
        $adjustment = app(Inventory\PostStockAdjustment::class)($adjustment, $this->actor);
        app(Accounting\PostStockAdjustmentEntry::class)($adjustment);
    }

    private function transfer(array $stores): void
    {
        if (count($stores) < 2) {
            return; // Never create a new store or change cashier store assignments.
        }
        $sku = collect($this->skus)->first(
            fn (array $item): bool => $item['variant_id'] === null && ! $item['batch'] && $item['index'] >= 100,
        );
        if (! $sku) {
            throw new RuntimeException('A non-batch simple product is required for the demo stock transfer.');
        }
        $transfer = app(Inventory\CreateStockTransfer::class)(['from_store_id' => $stores[0]->id,
            'to_store_id' => $stores[1]->id, 'transfer_date' => now()->toDateString(),
            'items' => [['product_id' => $sku['product_id'], 'variant_id' => null,
                'requested_quantity' => '24', 'unit_cost' => $sku['cost']]]], $this->actor);
        $transfer = app(Inventory\DispatchStockTransfer::class)($transfer, $this->actor);
        app(Inventory\ReceiveStockTransfer::class)($transfer, $transfer->items->map(fn ($item) => [
            'id' => $item->id, 'received_quantity' => (string) $item->requested_quantity,
        ])->all(), $this->actor);
    }

    private function validateDataset(): void
    {
        foreach (DB::table('product_stock_levels')->get() as $level) {
            $ledger = DB::table('stock_movements')->where('store_id', $level->store_id)
                ->where('product_id', $level->product_id)->where('variant_id', $level->variant_id)->sum('quantity_delta');
            if (bccomp((string) $level->quantity, (string) $ledger, 4) !== 0 || bccomp((string) $level->quantity, '0', 4) < 0) {
                throw new RuntimeException('Stock ledger validation failed.');
            }
            if (DB::table('products')->where('id', $level->product_id)->value('track_batches')) {
                $batches = DB::table('product_batches')->where('store_id', $level->store_id)
                    ->where('product_id', $level->product_id)->where('variant_id', $level->variant_id)->sum('quantity');
                if (bccomp((string) $level->quantity, (string) $batches, 4) !== 0) {
                    throw new RuntimeException('Batch stock validation failed.');
                }
            }
        }
        foreach (DB::table('journal_entries')->pluck('id') as $id) {
            $lines = DB::table('journal_lines')->where('journal_entry_id', $id);
            if (bccomp((string) (clone $lines)->sum('debit'), (string) (clone $lines)->sum('credit'), 4) !== 0) {
                throw new RuntimeException('Unbalanced demo journal.');
            }
        }
        // Validate every physical FK, including composite keys, while constraints remain enabled.
        foreach (Schema::getTableListing(DB::getDriverName() === 'sqlite' ? 'main' : DB::connection()->getDatabaseName(), false) as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                $query = DB::table($table.' as child')->whereNotExists(function ($q) use ($fk): void {
                    $q->selectRaw('1')->from($fk['foreign_table'].' as parent');
                    foreach ($fk['columns'] as $i => $column) {
                        $q->whereColumn('child.'.$column, 'parent.'.$fk['foreign_columns'][$i]);
                    }
                });
                foreach ($fk['columns'] as $column) {
                    $query->whereNotNull('child.'.$column);
                }
                if ($query->exists()) {
                    throw new RuntimeException("Foreign key validation failed for {$table}.");
                }
            }
        }
    }
}
