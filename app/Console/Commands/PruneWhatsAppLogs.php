<?php

namespace App\Console\Commands;

use App\Models\WhatsAppConnection;
use App\Models\WhatsAppLog;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Console\Command;

class PruneWhatsAppLogs extends Command
{
    protected $signature = 'pos:whatsapp-prune';

    protected $description = 'Delete WhatsApp delivery logs and webhook events beyond their configured retention';

    public function handle(): int
    {
        $defaultDays = max(30, min(3650, (int) config('services.whatsapp.retention_days', 365)));
        $deleted = WhatsAppLog::query()
            ->whereNull('whatsapp_connection_id')
            ->where('created_at', '<', now()->subDays($defaultDays))
            ->delete();

        WhatsAppConnection::query()->withTrashed()->select(['id', 'settings'])->chunkById(100, function ($connections) use (&$deleted, $defaultDays): void {
            foreach ($connections as $connection) {
                $days = max(30, min(3650, (int) $connection->setting('retention_days', $defaultDays)));
                $cutoff = now()->subDays($days);
                $deleted += WhatsAppLog::query()
                    ->where('whatsapp_connection_id', $connection->id)
                    ->where('created_at', '<', $cutoff)
                    ->delete();
                WhatsAppWebhookEvent::query()
                    ->where('whatsapp_connection_id', $connection->id)
                    ->where('created_at', '<', $cutoff)
                    ->delete();
            }
        });

        $this->info("Pruned {$deleted} WhatsApp delivery logs.");

        return self::SUCCESS;
    }
}
