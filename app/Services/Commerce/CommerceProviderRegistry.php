<?php

namespace App\Services\Commerce;

use App\Services\Commerce\Contracts\CommerceProvider;
use InvalidArgumentException;

final class CommerceProviderRegistry
{
    /** @var array<string, CommerceProvider> */
    private array $providers = [];

    /** @param iterable<CommerceProvider> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->code()] = $provider;
        }
    }

    public function get(string $code): CommerceProvider
    {
        return $this->providers[$code]
            ?? throw new InvalidArgumentException(__('commerce.validation.unsupported_provider', ['provider' => $code]));
    }

    /** @return array<string, CommerceProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
