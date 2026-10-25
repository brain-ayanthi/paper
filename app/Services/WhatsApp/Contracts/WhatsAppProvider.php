<?php

namespace App\Services\WhatsApp\Contracts;

use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\Data\ConnectionTestResult;
use App\Services\WhatsApp\Data\OutboundMessage;
use App\Services\WhatsApp\Data\SendResult;
use App\Services\WhatsApp\Data\WebhookStatus;
use Illuminate\Http\Request;

interface WhatsAppProvider
{
    public function code(): string;

    public function label(): string;

    /** @return array<string, array{type:string,required:bool,secret:bool,label:string}> */
    public function settingsSchema(): array;

    /** @return array<string, bool> */
    public function capabilities(): array;

    public function testConnection(WhatsAppConnection $connection): ConnectionTestResult;

    public function send(WhatsAppConnection $connection, OutboundMessage $message): SendResult;

    public function verifyWebhook(WhatsAppConnection $connection, Request $request): bool;

    /** @return list<WebhookStatus> */
    public function parseWebhook(WhatsAppConnection $connection, array $payload): array;
}
