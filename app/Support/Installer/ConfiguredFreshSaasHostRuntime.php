<?php

namespace App\Support\Installer;

use App\Contracts\Installer\FreshSaasHostRuntime;
use Illuminate\Mail\Mailer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Host operator binds this explicitly after reviewing the target and runner. */
final class ConfiguredFreshSaasHostRuntime implements FreshSaasHostRuntime
{
    private readonly Mailer $mailer;

    public function __construct(
        private readonly string $installationId,
        private readonly string $addonItemId,
        private readonly string $validatorUrl,
        #[\SensitiveParameter] private readonly string $validatorApiKey,
        private readonly string $privateDirectory,
        private readonly string $targetId,
        Mailer $mailer,
        private readonly \Closure $hostReady,
        private readonly \Closure $hostHandoff,
        private readonly ?\Closure $progressReader = null,
        private readonly bool $unreleased = false,
    ) {
        $this->mailer = clone $mailer;
        $this->mailer->alwaysTo(null);
    }

    public function ready(): bool
    {
        try {
            $url = parse_url($this->validatorUrl);
            $private = realpath($this->privateDirectory);
            $public = realpath(public_path());

            return preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D', $this->installationId) === 1
                && (preg_match('/\A[1-9][0-9]*\z/D', $this->addonItemId) === 1
                    || ($this->unreleased && $this->addonItemId === 'unreleased'))
                && is_array($url) && ($url['scheme'] ?? null) === 'https'
                && ! empty($url['host']) && ! isset($url['user']) && ! isset($url['pass'])
                && ! isset($url['query']) && ! isset($url['fragment'])
                && trim($this->validatorApiKey) !== ''
                && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D', $this->targetId) === 1
                && $private !== false && $public !== false && ! is_link($this->privateDirectory)
                && ! is_link($private) && is_dir($private) && is_writable($private)
                && $private !== $public && ! str_starts_with($private, $public.DIRECTORY_SEPARATOR)
                && (PHP_OS_FAMILY === 'Windows' || (fileperms($private) & 0077) === 0)
                && $this->mailer->getSymfonyTransport() instanceof EsmtpTransport
                && ($this->hostReady)() === true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function installationId(): string { return $this->installationId; }

    public function addonItemId(): string { return $this->addonItemId; }

    public function validatorUrl(): string { return $this->validatorUrl; }

    public function validatorApiKey(): string { return $this->validatorApiKey; }

    public function privateDirectory(): string { return $this->privateDirectory; }

    public function targetId(): string { return $this->targetId; }

    public function sendOwnerEmail(string $email, #[\SensitiveParameter] string $token): bool
    {
        if (! $this->ready() || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 254 || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            return false;
        }

        try {
            return $this->mailer->raw(__('installer.saas_owner.mail_body', ['code' => $token]),
                static function ($message) use ($email): void {
                    $message->to($email)->subject(__('installer.saas_owner.mail_subject'));
                }) !== null;
        } catch (\Throwable) {
            // Mail exceptions can contain recipient and transport secrets.
            return false;
        }
    }

    public function deliverHostHandoff(#[\SensitiveParameter] string $reference): ?RedirectResponse
    {
        if (! $this->ready() || preg_match('/\A[a-f0-9]{64}\z/D', $reference) !== 1) {
            return null;
        }

        try {
            $redirect = ($this->hostHandoff)($reference, $this->installationId, $this->targetId);

            return $redirect instanceof RedirectResponse
                && preg_match('~\A/(?!/)[A-Za-z0-9/_-]*\z~D', $redirect->getTargetUrl()) === 1
                ? $redirect : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function platformProgress(): ?array
    {
        if (! $this->ready() || $this->progressReader === null) return null;
        try {
            $value = ($this->progressReader)($this->installationId, $this->targetId);
            if (!is_array($value) || ($value['installation_id'] ?? null) !== $this->installationId) return null;
            return array_intersect_key($value, array_flip([
                'status', 'completed_steps', 'total_steps', 'current_step', 'error', 'ready', 'observed_at',
            ]));
        } catch (\Throwable) {
            return null;
        }
    }
}
