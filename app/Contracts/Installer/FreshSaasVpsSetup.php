<?php

namespace App\Contracts\Installer;

interface FreshSaasVpsSetup
{
    public function available(): bool;

    /** @return array<string, mixed> */
    public function state(): array;

    /** @param array<string, mixed> $input */
    public function configure(#[\SensitiveParameter] array $input): bool;
}
