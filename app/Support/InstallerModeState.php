<?php

namespace App\Support;

/** APP_KEY-authenticated durable installer mode and operation selection. */
final class InstallerModeState
{
    public const STATE_KEY = 'installer_mode_state';

    public function read(?string $preparedSourceSha256): array
    {
        $sealed = InstallState::get(self::STATE_KEY);
        if ($sealed === null) {
            return ['status' => 'missing'];
        }
        try {
            $key = $this->key();
            if (! is_array($sealed) || array_keys($sealed) !== ['payload', 'mac']
                || ! is_string($sealed['payload']) || ! is_string($sealed['mac'])
                || ! hash_equals(hash_hmac('sha256', 'hyper-pos-installer-mode-v1:'.$sealed['payload'], $key), $sealed['mac'])) {
                return ['status' => 'invalid'];
            }
            $payload = json_decode($sealed['payload'], true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || array_keys($payload) !== [
                'version', 'mode', 'operation_id', 'prepared_source_sha256', 'handoff_started',
            ] || $payload['version'] !== 1
                || ! in_array($payload['mode'], ['standard', InstallerSaasEntry::MODE_SAAS], true)
                || ! is_bool($payload['handoff_started'])) {
                return ['status' => 'invalid'];
            }
            if ($payload['mode'] === InstallerSaasEntry::MODE_SAAS) {
                if (! is_string($payload['operation_id'])
                    || ! preg_match('/\A[a-f0-9]{32}\z/D', $payload['operation_id'])
                    || ($payload['prepared_source_sha256'] !== null
                        && (! is_string($payload['prepared_source_sha256'])
                            || ! preg_match('/\A[a-f0-9]{64}\z/D', $payload['prepared_source_sha256'])))) {
                    return ['status' => 'invalid'];
                }
                if ($payload['prepared_source_sha256'] !== null
                    && ($preparedSourceSha256 === null
                        || ! hash_equals($payload['prepared_source_sha256'], $preparedSourceSha256))) {
                    return ['status' => 'foreign'];
                }
            } elseif ($payload['operation_id'] !== null
                || $payload['prepared_source_sha256'] !== null
                || $payload['handoff_started'] !== false) {
                return ['status' => 'invalid'];
            }

            return ['status' => 'valid'] + $payload;
        } catch (\Throwable) {
            return ['status' => 'invalid'];
        }
    }

    public function select(string $mode, ?string $preparedSourceSha256): array
    {
        $current = $this->read($preparedSourceSha256);
        if (in_array($current['status'], ['invalid', 'foreign'], true)) {
            throw new \RuntimeException('Installer mode recovery is required.');
        }
        if ($current['status'] === 'valid' && $current['mode'] === $mode) {
            return $current;
        }
        $payload = [
            'version' => 1, 'mode' => $mode,
            'operation_id' => $mode === InstallerSaasEntry::MODE_SAAS ? bin2hex(random_bytes(16)) : null,
            'prepared_source_sha256' => $mode === InstallerSaasEntry::MODE_SAAS ? $preparedSourceSha256 : null,
            'handoff_started' => false,
        ];
        $this->write($payload);

        return ['status' => 'valid'] + $payload;
    }

    public function markHandoffStarted(?string $preparedSourceSha256): array
    {
        $state = $this->read($preparedSourceSha256);
        if (($state['status'] ?? null) !== 'valid'
            || ($state['mode'] ?? null) !== InstallerSaasEntry::MODE_SAAS) {
            throw new \RuntimeException('Installer mode recovery is required.');
        }
        if ($state['handoff_started']) {
            return $state;
        }
        $payload = [
            'version' => 1, 'mode' => $state['mode'], 'operation_id' => $state['operation_id'],
            'prepared_source_sha256' => $state['prepared_source_sha256'], 'handoff_started' => true,
        ];
        $this->write($payload);

        return ['status' => 'valid'] + $payload;
    }

    private function write(array $payload): void
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        InstallState::set(self::STATE_KEY, [
            'payload' => $encoded,
            'mac' => hash_hmac('sha256', 'hyper-pos-installer-mode-v1:'.$encoded, $this->key()),
        ]);
    }

    private function key(): string
    {
        $key = (string) config('app.key', '');
        if (strlen($key) < 32) {
            throw new \RuntimeException('Installer state key is unavailable.');
        }

        return $key;
    }
}
