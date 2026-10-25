<?php

namespace App\Console\Commands;

use App\Actions\Commerce\DrainCommerceWork;
use Illuminate\Console\Command;

class CommerceWorkCommand extends Command
{
    protected $signature = 'commerce:work {--max-time=50} {--connection=} {--batch=}';

    public function __construct()
    {
        parent::__construct();

        $this->setDescription(__('commerce.worker.description'));
    }

    public function handle(DrainCommerceWork $work): int
    {
        $maxTime = max(1, min(3600, (int) $this->option('max-time')));
        $connection = filled($this->option('connection')) ? (int) $this->option('connection') : null;
        $batch = filled($this->option('batch')) ? max(1, min(250, (int) $this->option('batch'))) : null;

        $result = $work->run($maxTime, $connection ?: null, $batch);
        $this->info(__('commerce.worker.completed', [
            'outbound' => $result['outbox']['processed'],
            'inbound' => $result['webhooks']['processed'],
            'retried' => $result['outbox']['retried'] + $result['webhooks']['retried'],
            'failed' => $result['outbox']['failed'] + $result['webhooks']['failed'],
            'skipped' => $result['outbox']['skipped'] + $result['webhooks']['ignored'],
        ]));

        return self::SUCCESS;
    }
}
