<?php

namespace App\Actions\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppLog;
use App\Services\WhatsApp\ClickToChat;
use App\Services\WhatsApp\WhatsAppMessageFactory;
use Illuminate\Database\Eloquent\Model;

final readonly class SendWhatsAppDocument
{
    public function __construct(
        private WhatsAppMessageFactory $messages,
        private ClickToChat $clickToChat,
    ) {}

    /** @return array{log:WhatsAppLog,click_url:?string} */
    public function execute(Model $reference, ?string $phone, ?User $user, bool $forceClick = false, array $context = []): array
    {
        $storeId = (int) ($reference->getAttribute('store_id') ?: ($context['store_id'] ?? current_store_id()));
        $connection = $forceClick ? null : WhatsAppConnection::forStore($storeId);
        $prepared = $this->messages->make($reference, $phone, $connection, $context);
        // Business-initiated automatic messages require an approved provider
        // template. A missing mapping falls back to honest Click-to-Chat.
        $automatic = $connection !== null
            && $connection->setting('mode', 'automatic') === 'automatic'
            && $prepared->templateName !== null;

        $log = WhatsAppLog::query()->create([
            'store_id' => $prepared->store->id,
            'whatsapp_connection_id' => $automatic ? $connection?->id : null,
            'mode' => $automatic ? 'cloud_api' : 'click_to_chat',
            'recipient_phone' => $prepared->recipient,
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'message_template' => $prepared->templateName ?: $prepared->documentType,
            'payload' => [
                'document_type' => $prepared->documentType,
                'body' => $prepared->body,
                'locale' => $prepared->locale,
                'parameters' => $prepared->parameters,
                'template_name' => $prepared->templateName,
            ],
            'status' => $automatic ? WhatsAppLog::STATUS_QUEUED : WhatsAppLog::STATUS_PREPARED,
            'created_by' => $user?->id,
        ]);

        if ($automatic) {
            SendWhatsAppMessage::dispatch($log->id)->afterCommit();

            return ['log' => $log, 'click_url' => null];
        }

        return ['log' => $log, 'click_url' => $this->clickToChat->url($prepared->recipient, $prepared->body)];
    }
}
