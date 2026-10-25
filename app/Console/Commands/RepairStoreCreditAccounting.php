<?php

namespace App\Console\Commands;

use App\Actions\Accounting\RepairStoreCreditAccounting as RepairAction;
use Illuminate\Console\Command;

class RepairStoreCreditAccounting extends Command
{
    protected $signature = 'pos:accounting-repair-store-credit
                            {--fix : Post missing conversion and liability-reclassification entries}
                            {--store= : Limit the check to one store id}';

    protected $description = 'Audit and repair historical store-credit accounting';

    public function handle(RepairAction $repair): int
    {
        $fix = (bool) $this->option('fix');
        $storeId = $this->option('store') !== null ? (int) $this->option('store') : null;
        $results = $repair($fix, $storeId);

        $this->table(
            [
                __('accounting.store_credit_repair.columns.store'),
                __('accounting.store_credit_repair.columns.conversions'),
                __('accounting.store_credit_repair.columns.legacy_balance'),
                __('accounting.store_credit_repair.columns.result'),
            ],
            array_map(function (array $row) use ($fix) {
                $needsRepair = $row['missingConversions'] > 0
                    || bccomp($row['legacyBalance'], '0', 4) !== 0;

                return [
                    $row['store']->name,
                    $row['missingConversions'],
                    $row['legacyBalance'],
                    $fix
                        ? __('accounting.store_credit_repair.fixed', [
                            'conversions' => $row['postedConversions'],
                            'reclassified' => $row['reclassification'] ? __('accounting.store_credit_repair.yes') : __('accounting.store_credit_repair.no'),
                        ])
                        : ($needsRepair
                            ? __('accounting.store_credit_repair.review')
                            : __('accounting.store_credit_repair.ok')),
                ];
            }, $results),
        );

        if (! $fix && collect($results)->contains(fn (array $row) => $row['missingConversions'] > 0
            || bccomp($row['legacyBalance'], '0', 4) !== 0)) {
            $this->warn(__('accounting.store_credit_repair.warning'));
        }

        return self::SUCCESS;
    }
}
