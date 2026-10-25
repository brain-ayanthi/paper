<?php

namespace App\Console\Commands;

use App\Models\PostingFailure;
use App\Support\Accounting\PostingGuard;
use Illuminate\Console\Command;

/**
 * Replays journal postings that failed — defect #104.
 *
 * The auto-posters swallow failures on purpose so a bookkeeping problem can
 * never stop a cashier taking money. `PostingGuard` now records each one in
 * `posting_failures`; this is what drains that queue once the cause is fixed
 * (a mapping filled in, a period unlocked). Every poster is idempotent per
 * reference, so replaying a posting that in fact succeeded is a no-op.
 *
 * Runs nightly from the scheduler and can be run by hand after fixing a
 * mapping. `--dry-run` lists what would be attempted without touching the
 * books, which is the right first move on a customer install.
 */
class RepostFailedEntries extends Command
{
    protected $signature = 'pos:accounting-repost
                            {--dry-run : List what would be replayed without posting anything}
                            {--limit=200 : Maximum failures to work through in one run}';

    protected $description = 'Replay journal entries that failed to post';

    public function handle(): int
    {
        $failures = PostingFailure::query()
            ->unresolved()
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($failures->isEmpty()) {
            $this->info('Nothing to replay — every posting is up to date.');

            return self::SUCCESS;
        }

        $this->line("{$failures->count()} unresolved posting failure(s).");

        $replayed = 0;
        $stillFailing = 0;
        $orphaned = 0;

        foreach ($failures as $failure) {
            $reference = $failure->reference();
            $label = class_basename((string) $failure->poster).' → '
                       .class_basename((string) $failure->reference_type).' #'.$failure->reference_id;

            // The record was deleted after the failure — there is nothing left
            // to post, so stop carrying it as outstanding work.
            if (! $reference) {
                $this->warn("  skip    {$label} (record no longer exists)");
                $orphaned++;
                if (! $this->option('dry-run')) {
                    $failure->update(['resolved_at' => now()]);
                }

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  would replay  {$label}");

                continue;
            }

            $poster = $failure->poster;

            if (! class_exists($poster)) {
                $this->warn("  skip    {$label} (poster class is gone)");
                $orphaned++;

                continue;
            }

            // Some batch posters take `(array $inserted,
            // $model)` — the inserted rows are not reconstructable from the
            // failure row, so those cannot be replayed blind. Supplier payment
            // failures now reference a PurchasePayment and reload their batch;
            // other multi-argument posters remain manual.
            if (! $this->isReplayable($poster)) {
                $this->warn("  manual  {$label} (needs the original payment rows — re-post from the record itself)");
                $stillFailing++;

                continue;
            }

            PostingGuard::attempt(
                (string) $failure->hook,
                $poster,
                fn () => app($poster)($reference),
                $reference,
            );

            if ($failure->fresh()?->resolved_at) {
                $this->info("  posted  {$label}");
                $replayed++;
            } else {
                $this->error("  failed  {$label} — ".($failure->fresh()?->message ?? ''));
                $stillFailing++;
            }
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("Replayed: {$replayed}   Still failing: {$stillFailing}   Skipped: {$orphaned}");

        // Still-failing entries are a real problem, but not one the scheduler
        // should treat as a crashed command — they need a human to fix a
        // mapping or unlock a period.
        return self::SUCCESS;
    }

    /**
     * Can this poster be driven from the business record alone?
     *
     * Most take `__invoke($record)`. Some batch payment posters take
     * `(array $inserted, $model)` — the inserted rows are gone by the time we
     * replay, so calling them here would just manufacture a second, misleading
     * failure.
     */
    private function isReplayable(string $poster): bool
    {
        try {
            $invoke = new \ReflectionMethod($poster, '__invoke');
        } catch (\ReflectionException) {
            return false;
        }

        return $invoke->getNumberOfRequiredParameters() <= 1;
    }
}
