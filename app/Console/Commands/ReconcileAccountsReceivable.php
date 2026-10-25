<?php

namespace App\Console\Commands;

use App\Actions\Accounting\ReconcileAccountsReceivable as ReconcileAction;
use Illuminate\Console\Command;

class ReconcileAccountsReceivable extends Command
{
    protected $signature = 'pos:accounting-reconcile-receivables
                            {--fix : Post a balanced adjustment for each drifted store}
                            {--store= : Limit the check to one store id}';

    protected $description = 'Compare the A/R control account with open customer-sale balances';

    public function handle(ReconcileAction $reconcile): int
    {
        $fix = (bool) $this->option('fix');
        $storeId = $this->option('store') !== null ? (int) $this->option('store') : null;
        $results = $reconcile($fix, $storeId);
        $drifted = 0;

        $this->table(
            [
                __('accounting.reconciliation.columns.store'),
                __('accounting.reconciliation.columns.subledger'),
                __('accounting.reconciliation.columns.ledger'),
                __('accounting.reconciliation.columns.drift'),
                __('accounting.reconciliation.columns.result'),
            ],
            array_map(function (array $row) use ($fix, &$drifted) {
                $hasDrift = bccomp($row['drift'], '0', 4) !== 0;
                $drifted += $hasDrift ? 1 : 0;

                if ($hasDrift && ! $fix) {
                    notify_admins(
                        'accounting-ar-drift-'.$row['store']->id,
                        __('accounting.reconciliation.notification_title'),
                        __('accounting.reconciliation.notification_body', [
                            'store' => $row['store']->name,
                            'amount' => $row['drift'],
                        ]),
                        'alert',
                        route('admin.reports.balance-sheet.index'),
                        'accounting.view',
                    );
                }

                return [
                    $row['store']->name,
                    $row['subledger'],
                    $row['ledger'],
                    $row['drift'],
                    $row['entry']
                        ? __('accounting.reconciliation.adjusted', ['number' => $row['entry']->number])
                        : ($hasDrift ? __('accounting.reconciliation.review') : __('accounting.reconciliation.ok')),
                ];
            }, $results),
        );

        if ($drifted > 0 && ! $fix) {
            $this->warn(__('accounting.reconciliation.warning'));
        }

        return self::SUCCESS;
    }
}
