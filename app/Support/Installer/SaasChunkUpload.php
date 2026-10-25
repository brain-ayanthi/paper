<?php

namespace App\Support\Installer;

use Illuminate\Http\UploadedFile;

/** Session-bound, ordered upload of a signed delivery before any archive code is loaded. */
final class SaasChunkUpload
{
    private const CHUNK_BYTES = 524288;
    private const MAX_BYTES = 67108864;
    private const MAX_CHUNKS = 128;

    public function __construct(private readonly string $directory, private readonly string $key)
    {
    }

    public function append(string $sessionId, string $uploadId, int $index, int $total,
        int $expectedBytes, UploadedFile $chunk): int
    {
        if ($total < 1 || $total > self::MAX_CHUNKS || $index < 0 || $index >= $total
            || $expectedBytes < 1 || $expectedBytes > self::MAX_BYTES
            || $chunk->getSize() < 1 || $chunk->getSize() > self::CHUNK_BYTES) {
            throw new \RuntimeException('Invalid SaaS upload chunk.');
        }
        $bytes = file_get_contents($chunk->getRealPath());
        if (! is_string($bytes) || strlen($bytes) !== $chunk->getSize()) {
            throw new \RuntimeException('Unreadable SaaS upload chunk.');
        }
        return $this->locked(function () use ($sessionId, $uploadId, $index, $total,
            $expectedBytes, $bytes): int {
            [$metadataPath, $partPath] = $this->paths($sessionId, $uploadId);
            $metadata = $this->readMetadata($metadataPath);
            if ($metadata === null) {
                if ($index !== 0 || file_exists($partPath) || is_link($partPath)) {
                    throw new \RuntimeException('SaaS upload must start at its first chunk.');
                }
                $metadata = ['version' => 1, 'total' => $total, 'expected_bytes' => $expectedBytes,
                    'next_index' => 0, 'received_bytes' => 0, 'last_sha256' => null,
                    'created_at' => time()];
            }
            if ($metadata['total'] !== $total || $metadata['expected_bytes'] !== $expectedBytes
                || $metadata['created_at'] < time() - 3600 || is_link($partPath)
                || ($index !== 0 && ! is_file($partPath))
                || (is_file($partPath) && filesize($partPath) !== $metadata['received_bytes'])) {
                throw new \RuntimeException('SaaS upload state changed.');
            }
            if ($index === $metadata['next_index'] - 1
                && hash_equals((string) $metadata['last_sha256'], hash('sha256', $bytes))) {
                return $metadata['next_index'];
            }
            if ($index !== $metadata['next_index']
                || $metadata['received_bytes'] + strlen($bytes) > $expectedBytes) {
                throw new \RuntimeException('SaaS upload chunks are out of order.');
            }
            $stream = fopen($partPath, $index === 0 ? 'xb' : 'ab');
            if ($stream === false) throw new \RuntimeException('Cannot store SaaS upload chunk.');
            try {
                $written = 0;
                while ($written < strlen($bytes)) {
                    $count = fwrite($stream, substr($bytes, $written));
                    if ($count === false || $count === 0) {
                        throw new \RuntimeException('Cannot store SaaS upload chunk.');
                    }
                    $written += $count;
                }
                if (! fflush($stream)) {
                    throw new \RuntimeException('Cannot store SaaS upload chunk.');
                }
            } finally {
                fclose($stream);
            }
            chmod($partPath, 0600);
            $metadata['next_index']++;
            $metadata['received_bytes'] += strlen($bytes);
            $metadata['last_sha256'] = hash('sha256', $bytes);
            $this->writeMetadata($metadataPath, $metadata);
            return $metadata['next_index'];
        });
    }

    public function consume(string $sessionId, string $uploadId): string
    {
        return $this->locked(function () use ($sessionId, $uploadId): string {
            [$metadataPath, $partPath, $finishedPath] = $this->paths($sessionId, $uploadId);
            $metadata = $this->readMetadata($metadataPath);
            if ($metadata === null || $metadata['next_index'] !== $metadata['total']
                || $metadata['received_bytes'] !== $metadata['expected_bytes']
                || $metadata['created_at'] < time() - 3600 || is_link($partPath)
                || ! is_file($partPath) || filesize($partPath) !== $metadata['expected_bytes']
                || file_exists($finishedPath) || is_link($finishedPath)
                || ! rename($partPath, $finishedPath)) {
                throw new \RuntimeException('SaaS upload is incomplete.');
            }
            unlink($metadataPath);
            return $finishedPath;
        });
    }

    private function paths(string $sessionId, string $uploadId): array
    {
        if (strlen($this->key) < 32 || $sessionId === ''
            || preg_match('/\A[a-f0-9]{32}\z/D', $uploadId) !== 1) {
            throw new \RuntimeException('Invalid SaaS upload identity.');
        }
        $digest = hash_hmac('sha256', $sessionId.':'.$uploadId, $this->key);
        $prefix = $this->directory.'/chunk-'.$digest;
        return [$prefix.'.json', $prefix.'.part', $prefix.'.zip'];
    }

    private function readMetadata(string $path): ?array
    {
        if (! is_file($path) && ! is_link($path)) return null;
        if (is_link($path) || filesize($path) > 2048) {
            throw new \RuntimeException('Unsafe SaaS upload state.');
        }
        $value = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_keys($value) !== ['version', 'total', 'expected_bytes',
            'next_index', 'received_bytes', 'last_sha256', 'created_at']
            || $value['version'] !== 1 || ! is_int($value['total'])
            || ! is_int($value['expected_bytes']) || ! is_int($value['next_index'])
            || ! is_int($value['received_bytes']) || ! is_int($value['created_at'])
            || ! (is_null($value['last_sha256']) || (is_string($value['last_sha256'])
                && preg_match('/\A[a-f0-9]{64}\z/D', $value['last_sha256']) === 1))) {
            throw new \RuntimeException('Invalid SaaS upload state.');
        }
        return $value;
    }

    private function writeMetadata(string $path, array $value): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $body = json_encode($value, JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $body, LOCK_EX) !== strlen($body)
            || ! chmod($temporary, 0600) || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Cannot save SaaS upload state.');
        }
    }

    private function locked(\Closure $action): mixed
    {
        $this->privateDirectory();
        $lockPath = $this->directory.'/.upload.lock';
        if (is_link($lockPath)) throw new \RuntimeException('Unsafe SaaS upload lock.');
        $lock = fopen($lockPath, 'c');
        if ($lock === false) throw new \RuntimeException('SaaS upload lock unavailable.');
        try {
            if (! flock($lock, LOCK_EX)) throw new \RuntimeException('SaaS upload lock unavailable.');
            chmod($lockPath, 0600);
            return $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function privateDirectory(): void
    {
        for ($cursor = $this->directory; ; $cursor = dirname($cursor)) {
            if (is_link($cursor)) throw new \RuntimeException('Linked SaaS upload path.');
            if ($cursor === dirname($cursor)) break;
        }
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true)) {
            throw new \RuntimeException('Private SaaS upload directory unavailable.');
        }
        if (! is_dir($this->directory) || (PHP_OS_FAMILY !== 'Windows'
            && (fileperms($this->directory) & 0002) !== 0)) {
            throw new \RuntimeException('Unsafe SaaS upload directory.');
        }
    }
}