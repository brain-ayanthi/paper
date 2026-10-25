<?php

namespace App\Support\Installer;

/**
 * Verifies the entire uploaded delivery against the fingerprint shipped by core
 * before any PHP from the archive is extracted or loaded.
 */
final class SignedSaasDelivery
{
    public function verify(string $archive, string $fingerprint, string $coreVersion): array
    {
        $this->safePath($archive);
        if (! is_file($archive) || filesize($archive) > 1073741824
            || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new \RuntimeException('Invalid SaaS delivery.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($archive, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Unreadable SaaS delivery.');
        }
        try {
            if ($zip->numFiles < 10 || $zip->numFiles > 10000) {
                throw new \RuntimeException('Invalid delivery size.');
            }
            $names = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $name = $entry['name'] ?? '';
                $this->safeName($name);
                if (isset($names[$name]) || ($entry['size'] ?? PHP_INT_MAX) > 268435456
                    || ($total += $entry['size']) > 1073741824
                    || ($entry['encryption_method'] ?? 0) !== 0
                    || ! $zip->getExternalAttributesIndex($index, $system, $attributes)
                    || $system !== \ZipArchive::OPSYS_UNIX
                    || (($attributes >> 16) & 0177777) !== 0100644) {
                    throw new \RuntimeException('Unsafe SaaS delivery entry.');
                }
                $names[$name] = true;
            }
            $read = static function (string $name, int $limit) use ($zip): string {
                $entry = $zip->statName($name);
                if ($entry === false || $entry['size'] > $limit) {
                    throw new \RuntimeException('Missing SaaS delivery metadata.');
                }
                $bytes = $zip->getFromName($name);
                if (! is_string($bytes) || strlen($bytes) !== $entry['size']) {
                    throw new \RuntimeException('Unreadable SaaS delivery metadata.');
                }
                return $bytes;
            };
            $manifest = json_decode($read('delivery-manifest.json', 2097152), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || array_keys($manifest) !== ['version', 'role', 'protocol', 'files']
                || $manifest['version'] !== 1 || $manifest['role'] !== 'customer_delivery'
                || $manifest['protocol'] !== 1 || ! is_array($manifest['files'])
                || count($names) !== count($manifest['files']) + 3) {
                throw new \RuntimeException('Unsupported SaaS delivery manifest.');
            }
            $key = openssl_pkey_get_public($read('publisher-public-key.pem', 16384));
            $details = $key === false ? false : openssl_pkey_get_details($key);
            $canonical = static function (array $items) use (&$canonical): array {
                if (! array_is_list($items)) ksort($items);
                foreach ($items as &$item) {
                    if (is_array($item)) $item = $canonical($item);
                }
                return $items;
            };
            $manifestDigest = hash('sha256', json_encode($canonical($manifest), JSON_THROW_ON_ERROR));
            if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA
                || $details['bits'] < 2048
                || ! hash_equals($fingerprint, hash('sha256', $details['key']))
                || openssl_verify("hyper-pos-customer-delivery-v1\n".$manifestDigest."\n",
                    $read('delivery-signature.bin', 16384), $key, OPENSSL_ALGO_SHA256) !== 1) {
                throw new \RuntimeException('Untrusted SaaS delivery signature.');
            }
            foreach ($manifest['files'] as $name => $digest) {
                $this->safeName($name);
                if (! isset($names[$name]) || ! is_string($digest)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                    || in_array($name, ['delivery-manifest.json', 'delivery-signature.bin',
                        'publisher-public-key.pem'], true)) {
                    throw new \RuntimeException('Invalid signed SaaS file list.');
                }
                $entry = $zip->statName($name);
                $stream = $zip->getStream($name);
                if ($entry === false || $stream === false) {
                    throw new \RuntimeException('Unreadable signed SaaS file.');
                }
                $hash = hash_init('sha256');
                $length = 0;
                try {
                    while (! feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false || ($length += strlen($chunk)) > $entry['size']) {
                            throw new \RuntimeException('Invalid signed SaaS file.');
                        }
                        hash_update($hash, $chunk);
                    }
                } finally {
                    fclose($stream);
                }
                if ($length !== $entry['size'] || ! hash_equals($digest, hash_final($hash))) {
                    throw new \RuntimeException('SaaS delivery content changed.');
                }
            }
            foreach (['delivery.json', 'addon/hyper-pos-saas.zip',
                'host-tools/src/Packaging/PrepareFreshInstallerDelivery.php',
                'host-tools/src/Packaging/FreshInstallerPackageApproval.php',
                'host-tools/src/Packaging/VerifyCustomerRelease.php',
                'host-tools/src/Packaging/StageFreshInstallerSource.php',
                'host-tools/src/Packaging/ReleaseFiles.php',
                'host-tools/src/Infrastructure/Platform/BuildPlatformPlan.php'] as $required) {
                if (! isset($manifest['files'][$required])) {
                    throw new \RuntimeException('Incomplete SaaS delivery.');
                }
            }
            $status = json_decode($read('delivery.json', 8192), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($status) || ($status['requires_core'] ?? null) !== '='.$coreVersion
                || ($status['test_signature_only'] ?? null) !== false
                || ! in_array($status['channel'] ?? null, ['production', 'acceptance-preview'], true)
                || PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 90000) {
                throw new \RuntimeException('Incompatible SaaS delivery.');
            }
            return ['archive_sha256' => hash_file('sha256', $archive),
                'manifest_sha256' => $manifestDigest, 'files' => $manifest['files'],
                'status' => $status];
        } finally {
            $zip->close();
        }
    }

    public function extractSignedHostCode(string $archive, array $files, string $target): void
    {
        $this->safePath($target);
        if (file_exists($target) || ! is_dir(dirname($target)) || ! mkdir($target, 0700)) {
            throw new \RuntimeException('Private SaaS preparation directory unavailable.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($archive, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('SaaS delivery changed.');
        }
        try {
            foreach ($files as $name => $digest) {
                if (! str_starts_with($name, 'host-tools/src/') || ! str_ends_with($name, '.php')) continue;
                $this->safeName($name);
                $bytes = $zip->getFromName($name);
                if (! is_string($bytes) || strlen($bytes) > 16777216
                    || ! hash_equals($digest, hash('sha256', $bytes))) {
                    throw new \RuntimeException('Signed SaaS source changed.');
                }
                $path = $target.'/'.substr($name, strlen('host-tools/'));
                $parent = dirname($path);
                if (! is_dir($parent) && ! mkdir($parent, 0700, true)) {
                    throw new \RuntimeException('Private SaaS source directory unavailable.');
                }
                $handle = fopen($path, 'xb');
                if ($handle === false) throw new \RuntimeException('Cannot stage signed SaaS source.');
                try {
                    if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                        throw new \RuntimeException('Cannot stage signed SaaS source.');
                    }
                } finally {
                    fclose($handle);
                }
                chmod($path, 0600);
            }
        } finally {
            $zip->close();
        }
    }

    private function safePath(string $path): void
    {
        for ($cursor = $path; ; $cursor = dirname($cursor)) {
            if (is_link($cursor)) throw new \RuntimeException('Linked SaaS paths are forbidden.');
            if ($cursor === dirname($cursor)) break;
        }
    }

    private function safeName(string $name): void
    {
        if (preg_match('~\A[A-Za-z0-9][A-Za-z0-9_./-]*\z~D', $name) !== 1
            || array_intersect(explode('/', $name), ['', '.', '..'])) {
            throw new \RuntimeException('Unsafe SaaS delivery path.');
        }
    }
}
