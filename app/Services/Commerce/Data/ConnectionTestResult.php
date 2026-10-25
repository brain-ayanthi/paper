<?php

namespace App\Services\Commerce\Data;

final readonly class ConnectionTestResult
{
    /**
     * @param  list<RemoteLocation>  $locations
     * @param  array<string, mixed>  $credentialUpdates
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $successful,
        public string $message,
        public ?string $remoteStoreId = null,
        public ?string $remoteStoreName = null,
        public array $locations = [],
        public array $credentialUpdates = [],
        public array $metadata = [],
    ) {}

    /** @param list<RemoteLocation> $locations */
    public static function success(
        string $message,
        ?string $remoteStoreId = null,
        ?string $remoteStoreName = null,
        array $locations = [],
        array $credentialUpdates = [],
        array $metadata = [],
    ): self {
        return new self(true, $message, $remoteStoreId, $remoteStoreName, $locations, $credentialUpdates, $metadata);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
