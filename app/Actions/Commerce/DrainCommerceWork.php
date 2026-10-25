<?php

namespace App\Actions\Commerce;

final readonly class DrainCommerceWork
{
    public function __construct(
        private ProcessCommerceOutbox $outbox,
        private ProcessCommerceWebhooks $webhooks,
    ) {}

    /**
     * @return array{
     *   outbox:array{processed:int,retried:int,failed:int,skipped:int},
     *   webhooks:array{processed:int,retried:int,failed:int,ignored:int}
     * }
     */
    public function run(
        int $maxSeconds = 50,
        ?int $connectionId = null,
        ?int $batchSize = null,
    ): array {
        $totals = [
            'outbox' => ['processed' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 0],
            'webhooks' => ['processed' => 0, 'retried' => 0, 'failed' => 0, 'ignored' => 0],
        ];
        $batchSize ??= max(1, (int) config('commerce.processing.worker_batch_size', 10));
        $deadline = hrtime(true) + (max(1, $maxSeconds) * 1_000_000_000);

        do {
            $webhooks = $this->webhooks->run($batchSize, $connectionId);
            $outbox = $this->outbox->run($batchSize, $connectionId);
            $this->merge($totals['webhooks'], $webhooks);
            $this->merge($totals['outbox'], $outbox);

            if (array_sum($webhooks) + array_sum($outbox) === 0) {
                break;
            }
        } while (hrtime(true) < $deadline);

        return $totals;
    }

    /** @param array<string, int> $totals @param array<string, int> $batch */
    private function merge(array &$totals, array $batch): void
    {
        foreach ($batch as $key => $value) {
            $totals[$key] += $value;
        }
    }
}
