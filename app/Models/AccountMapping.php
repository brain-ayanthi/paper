<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Indirection between a business-event key (e.g. `sales_revenue`, `cogs`,
 * `accounts_payable_suppliers`) and the actual {@see Account} an auto-posted
 * journal entry should hit. A null `store_id` is the global default; a row
 * with a `store_id` overrides it for that store. Resolved by
 * {@see \App\Services\Accounting\AccountMappingResolver}.
 */
class AccountMapping extends Model
{
    /**
     * The account TYPE each business event must post to — security register
     * SEC #42 (defect #117).
     *
     * The update endpoint validated only that the account id existed, so a
     * crafted request could point `sales_revenue` at an expense account, or at
     * a deactivated one. Every future posting then follows that mapping
     * silently: the trial balance still balances, so nothing surfaces it, and
     * the books are quietly wrong until someone reconciles by hand.
     *
     * The types mirror the shipped defaults in ChartOfAccountsSeeder (1xxx
     * asset, 2xxx liability, 4xxx income, 5xxx-7xxx expense), so a legitimate
     * re-point — swapping one revenue account for another — still passes.
     */
    public const EXPECTED_TYPES = [
        'sales_revenue'                 => 'income',
        'service_revenue'               => 'income',
        'sales_returns'                 => 'income',
        'sales_discounts'               => 'income',
        'inventory'                     => 'asset',
        'tax_output'                    => 'liability',
        'tax_input'                     => 'asset',
        'cogs'                          => 'expense',
        'inventory_wastage'             => 'expense',
        'inventory_shrinkage'           => 'expense',
        'inventory_adjustment_income'   => 'income',
        'accounts_receivable_customers' => 'asset',
        'accounts_payable_suppliers'    => 'liability',
        'customer_advances'             => 'liability',
        'advances_to_suppliers'         => 'asset',
        'store_credit_liability'        => 'liability',
        'cash_overage'                  => 'income',
        'cash_shortage'                 => 'expense',
        'rounding_adjustment_income'    => 'income',
        'rounding_adjustment_expense'   => 'expense',
        'payment_gateway_fees'          => 'expense',
        'cheque_in_hand'                    => 'asset',
        'cash_drawer_cash'                  => 'asset',
        'cash_drawer_transfer'              => 'asset',
        'cash_drawer_suspense'              => 'expense',
        'receivable_reconciliation_offset' => 'equity',
    ];

    protected $fillable = ['key', 'account_id', 'store_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
