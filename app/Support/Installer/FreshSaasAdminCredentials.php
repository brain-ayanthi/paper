<?php

namespace App\Support\Installer;

/** Private, APP_KEY-authenticated admin credentials for one signed fresh SaaS install. */
final class FreshSaasAdminCredentials
{
    public function __construct(private readonly string $directory, private readonly string $key)
    {
        if (strlen($key) < 32) throw new \RuntimeException('Installer key unavailable.');
    }

    public function save(array $mode, string $name, string $email,
        #[\SensitiveParameter] string $password): array
    {
        $this->requireMode($mode);
        if (($mode['handoff_started'] ?? false) !== false) {
            throw new \RuntimeException('Installer handoff already started.');
        }
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || mb_strlen($name) > 191
            || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191
            || strlen($password) < 12 || strlen($password) > 72
            || str_contains($password, "\0")) {
            throw new \InvalidArgumentException('Invalid installer administrator details.');
        }
        return $this->locked($mode, function (string $path) use ($mode, $name, $email, $password): array {
            if (file_exists($path) || is_link($path)) {
                $saved = $this->readFile($path, $mode);
                if ($saved['name'] !== $name || $saved['email'] !== $email
                    || ! password_verify($password, $saved['password_hash'])) {
                    throw new \RuntimeException('Installer administrator already differs.');
                }
                return $saved;
            }
            $payload = [
                'version' => 1,
                'operation_id' => $mode['operation_id'],
                'prepared_source_sha256' => $mode['prepared_source_sha256'],
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                'created_at' => time(),
            ];
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $record = json_encode(['payload' => $encoded,
                'mac' => hash_hmac('sha256', 'fresh-saas-admin-v1:'.$encoded, $this->key)], JSON_THROW_ON_ERROR);
            $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
            try {
                $stream = fopen($temporary, 'x+b');
                if ($stream === false) throw new \RuntimeException('Administrator state unavailable.');
                try {
                    if (PHP_OS_FAMILY !== 'Windows' && ! chmod($temporary, 0600)) {
                        throw new \RuntimeException('Administrator state is not private.');
                    }
                    $offset = 0;
                    while ($offset < strlen($record)) {
                        $written = fwrite($stream, substr($record, $offset));
                        if (! is_int($written) || $written < 1) {
                            throw new \RuntimeException('Administrator state write failed.');
                        }
                        $offset += $written;
                    }
                    if (! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
                        throw new \RuntimeException('Administrator state write failed.');
                    }
                } finally {
                    fclose($stream);
                }
                if (file_exists($path) || is_link($path) || ! rename($temporary, $path)) {
                    throw new \RuntimeException('Administrator state changed.');
                }
                return $payload;
            } finally {
                if (is_file($temporary)) unlink($temporary);
            }
        });
    }

    public function read(array $mode): ?array
    {
        $this->requireMode($mode);
        return $this->locked($mode, function (string $path) use ($mode): ?array {
            if (! file_exists($path) && ! is_link($path)) return null;
            return $this->readFile($path, $mode);
        });
    }

    private function readFile(string $path, array $mode): array
    {
        if (! is_file($path) || is_link($path) || filesize($path) > 4096
            || (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0077) !== 0)) {
            throw new \RuntimeException('Administrator state is unsafe.');
        }
        $record = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($record) || array_keys($record) !== ['payload', 'mac']
            || ! is_string($record['payload']) || ! is_string($record['mac'])
            || ! hash_equals(hash_hmac('sha256', 'fresh-saas-admin-v1:'.$record['payload'], $this->key), $record['mac'])) {
            throw new \RuntimeException('Administrator state authentication failed.');
        }
        $payload = json_decode($record['payload'], true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || array_keys($payload) !== [
            'version', 'operation_id', 'prepared_source_sha256', 'name', 'email',
            'password_hash', 'created_at',
        ] || $payload['version'] !== 1 || $payload['operation_id'] !== $mode['operation_id']
            || $payload['prepared_source_sha256'] !== $mode['prepared_source_sha256']
            || ! is_string($payload['name']) || trim($payload['name']) === ''
            || mb_strlen($payload['name']) > 191 || ! is_string($payload['email'])
            || ! filter_var($payload['email'], FILTER_VALIDATE_EMAIL)
            || $payload['email'] !== strtolower($payload['email'])
            || ! is_string($payload['password_hash'])
            || password_get_info($payload['password_hash'])['algoName'] !== 'bcrypt'
            || (int) (password_get_info($payload['password_hash'])['options']['cost'] ?? 0) < 12
            || ! is_int($payload['created_at']) || $payload['created_at'] < 1) {
            throw new \RuntimeException('Administrator state binding differs.');
        }
        return $payload;
    }

    private function locked(array $mode, \Closure $action): mixed
    {
        $this->privateDirectory();
        $lockPath = $this->directory.'/admin.lock';
        if (is_link($lockPath)) throw new \RuntimeException('Administrator lock is linked.');
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) throw new \RuntimeException('Administrator lock unavailable.');
        try {
            if (PHP_OS_FAMILY !== 'Windows' && ! chmod($lockPath, 0600)) {
                throw new \RuntimeException('Administrator lock is not private.');
            }
            if (! flock($lock, LOCK_EX)) throw new \RuntimeException('Administrator lock unavailable.');
            return $action($this->directory.'/'.$mode['operation_id'].'.json');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function privateDirectory(): void
    {
        for ($cursor = $this->directory; ; $cursor = dirname($cursor)) {
            if (is_link($cursor)) throw new \RuntimeException('Administrator directory is linked.');
            if ($cursor === dirname($cursor)) break;
        }
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true)) {
            throw new \RuntimeException('Administrator directory unavailable.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && is_dir($this->directory)
            && (fileperms($this->directory) & 0077) !== 0) {
            if (! @chmod($this->directory, 0700)) {
                throw new \RuntimeException('Administrator directory is not private.');
            }
            clearstatcache(true, $this->directory);
        }
        if (! is_dir($this->directory)
            || (PHP_OS_FAMILY !== 'Windows' && (fileperms($this->directory) & 0077) !== 0)) {
            throw new \RuntimeException('Administrator directory is not private.');
        }
    }

    private function requireMode(array $mode): void
    {
        if (($mode['status'] ?? null) !== 'valid' || ($mode['mode'] ?? null) !== 'saas'
            || ! preg_match('/\A[a-f0-9]{32}\z/D', $mode['operation_id'] ?? '')
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $mode['prepared_source_sha256'] ?? '')) {
            throw new \RuntimeException('Fresh SaaS installation state unavailable.');
        }
    }
}
