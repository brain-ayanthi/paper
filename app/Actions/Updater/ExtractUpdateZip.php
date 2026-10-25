<?php

namespace App\Actions\Updater;

use App\Support\SafeZipExtractor;
use RuntimeException;

/**
 * Extracts a verified update zip into a sibling `extracted/` dir and returns
 * the application source root inside it. If the archive wraps everything in a
 * single top-level folder (e.g. `pos-1.0.4/`), that folder is the source root.
 *
 * Only runs after {@see \App\Services\Updater\SignatureVerifier} has passed —
 * we never extract an unverified zip.
 *
 * Extraction goes through {@see SafeZipExtractor} rather than
 * `ZipArchive::extractTo()`, which honoured `../` entries and let a crafted
 * package write anywhere the web user could reach (SEC #4 / defect #75). The
 * signature gate upstream is the primary control; this is the second line, so
 * a package that somehow passes verification still cannot escape the folder.
 */
class ExtractUpdateZip
{
    public function __construct(private readonly SafeZipExtractor $extractor) {}

    public function __invoke(string $zipPath): string
    {
        if (! is_file($zipPath)) {
            throw new RuntimeException(__('updates.errors.archive_missing'));
        }

        $dir = dirname($zipPath).'/extracted';
        $this->clean($dir);
        @mkdir($dir, 0775, true);

        try {
            $this->extractor->extract($zipPath, $dir);
        } catch (RuntimeException $e) {
            // Leave nothing half-written behind for a rejected package.
            $this->clean($dir);

            throw new RuntimeException(__('updates.errors.archive_unreadable'), 0, $e);
        }

        return $this->sourceRoot($dir);
    }

    /** Descend into a lone wrapper directory if that's all the zip contained. */
    private function sourceRoot(string $dir): string
    {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir($dir.'/'.$entries[0])) {
            return $dir.'/'.$entries[0];
        }

        return $dir;
    }

    private function clean(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
