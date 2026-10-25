<?php

namespace App\Services\WhatsApp;

final class ClickToChat
{
    public function url(string $recipientE164, string $body): string
    {
        return 'https://wa.me/'.ltrim($recipientE164, '+').'?text='.rawurlencode($body);
    }
}
