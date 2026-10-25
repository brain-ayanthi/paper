<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use InvalidArgumentException;

final class WhatsAppProviderRegistry
{
    /** @var array<string, WhatsAppProvider> */
    private array $providers = [];

    /** @param iterable<WhatsAppProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(WhatsAppProvider $provider): void
    {
        $this->providers[$provider->code()] = $provider;
    }

    public function get(string $code): WhatsAppProvider
    {
        return $this->providers[$code]
            ?? throw new InvalidArgumentException(__('whatsapp.errors.unsupported_provider', ['provider' => $code]));
    }

    /** @return array<string, WhatsAppProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
