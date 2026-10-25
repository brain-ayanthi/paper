<?php

namespace App\Support\Installer;

/** Reads the publisher fingerprint shipped with Hyper-POS core. */
final class FreshSaasPublisherTrust
{
    public function __construct(private readonly string $path) {}

    public function digest(): ?string
    {
        try {
            if ($this->path === '' || ! is_file($this->path) || is_link($this->path)
                || filesize($this->path) > 128
                || (PHP_OS_FAMILY !== 'Windows' && (fileperms($this->path) & 0022) !== 0)) {
                return null;
            }

            $digest = trim((string) file_get_contents($this->path));

            return preg_match('/\A[a-f0-9]{64}\z/D', $digest) === 1 ? $digest : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
