<?php

namespace App\Services\Backup;

use App\Exceptions\InvalidBackupArchive;
use App\Support\SafeZipExtractor;
use Illuminate\Support\Str;

/**
 * Unzips a backup archive into a throwaway working directory under
 * `storage/app/restore-tmp/` and tears it down again afterwards.
 *
 * Encryption/decryption is intentionally out of scope for this slice — the
 * archives RunBackup produces today are unencrypted, and the manifest records
 * `encryption.encrypted = false`.
 *
 * Extraction goes through {@see SafeZipExtractor} (SEC #5 / defect #76). This
 * path was the more dangerous of the two zip-slip sites: it unzipped BEFORE
 * the archive was validated, so merely uploading a backup was enough to write
 * a file anywhere the web user could reach.
 */
class Extractor
{
    public function __construct(private readonly SafeZipExtractor $extractor) {}

    public function extract(string $zipPath): string
    {
        if (! is_file($zipPath)) {
            throw new InvalidBackupArchive(__('restore.errors.archive_missing'));
        }

        $dir = $this->makeWorkDir();

        try {
            $this->extractor->extract($zipPath, $dir);
        } catch (\RuntimeException $e) {
            $this->cleanup($dir);

            throw new InvalidBackupArchive(__('restore.errors.archive_unreadable'), 0, $e);
        }

        return $dir;
    }

    public function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    private function makeWorkDir(): string
    {
        $base = storage_path('app/restore-tmp');
        if (! is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        $dir = $base.'/'.Str::random(16);
        @mkdir($dir, 0775, true);

        return $dir;
    }
}
