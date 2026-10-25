<?php

namespace App\Contracts\Installer;

/**
 * Pre-business installer bridge exposed by a verified SaaS add-on.
 *
 * Hosting reports and selections contain no credentials, paths or command output.
 */
interface FreshSaasInstallerJourney
{
    public function available(): bool;

    /** @return array<string, mixed> */
    public function hostingCapabilities(): array;

    public function selectedHostingMode(): ?string;

    public function selectHostingMode(string $mode): bool;
}
