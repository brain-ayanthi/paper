<?php

namespace App\Contracts\Installer;

use Symfony\Component\HttpFoundation\RedirectResponse;

/** Trusted host configuration and effects, registered outside browser input. */
interface FreshSaasHostRuntime
{
    public function ready(): bool;

    public function installationId(): string;

    public function addonItemId(): string;

    public function validatorUrl(): string;

    public function validatorApiKey(): string;

    public function privateDirectory(): string;

    public function targetId(): string;

    public function sendOwnerEmail(string $email, #[\SensitiveParameter] string $token): bool;

    public function deliverHostHandoff(#[\SensitiveParameter] string $reference): ?RedirectResponse;

    /** Read the root-published sanitized progress mirror; never advance the runner. */
    public function platformProgress(): ?array;
}
