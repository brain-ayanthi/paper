<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppWebhookEvent extends Model
{
    protected $table = 'whatsapp_webhook_events';

    protected $fillable = ['whatsapp_connection_id', 'event_id', 'event_type', 'payload', 'processed_at', 'received_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }
}
