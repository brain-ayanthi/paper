<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Extracts a zip entry-by-entry, refusing anything that would write outside
 * the destination directory.
 *
 * Security register SEC #4 (update extract) and SEC #5 (restore extract),
 * linked defects #75 and #76. Both called `ZipArchive::extractTo()`, which
 * happily honours an entry named `../../public/shell.php` — so a crafted
 * update or backup archive could drop a file anywhere the web user can write,
 * including into the document root. That is remote code execution on a
 * self-hosted install, and on the restore path it ran BEFORE the archive was
 * validated, so simply uploading a backup was enough.
 *
 * What is rejected, and why each matters:
 *
 *  - **`..` traversal** — the classic zip-slip. Checked on the normalised path
 *    rather than by string search, so `a/../../b` and `.\..\b` are caught too.
 *  - **Absolute paths** — `/etc/cron.d/x` or `C:\...`; `extractTo()` strips
 *    these on some platforms and not others, so we never rely on that.
 *  - **Windows drive letters and backslashes** — a zip written on Windows can
 *    carry `..\..\` which a POSIX-only check would miss.
 *  - **Symlinks** — an entry can be a symlink pointing outside the tree; a
 *    later entry then writes *through* it. Path checks alone do not catch
 *    this, which is why extraction is done manually rather than by extractTo.
 *  - **Null bytes** — truncate a path in C-level filesystem calls.
 *  - **Zip bombs** (code-review #6) — an entry-count cap and a total
 *    inflated-size cap. The size is enforced twice: on the DECLARED sizes
 *    before anything is written, and again on the ACTUAL bytes streamed out
 *    during extraction, because a crafted archive can lie in its headers.
 *    Without this, a small upload could fill the disk on a shared host.
 *
 * The whole archive is validated BEFORE anything is written, so a rejected
 * archive leaves no partial extraction behind.
 */
class SafeZipExtractor
{
    /** Far above any real update or backup, low enough to stop a bomb. */
    public const MAX_ENTRIES = 50_000;

    /** Total inflated bytes allowed — 20 GiB. */
    public const MAX_TOTAL_BYTES = 20 * 1024 * 1024 * 1024;

    /** Overridable so the caps are testable; production callers keep the defaults. */
    public function __construct(
        private readonly int $maxEntries = self::MAX_ENTRIES,
        private readonly int $maxTotalBytes = self::MAX_TOTAL_BYTES,
    ) {}

    /**
     * Validate every entry, then extract into `$destination`.
     *
     * @throws RuntimeException with the offending entry name when unsafe, or
     *                          if the archive cannot be read.
     */
    public function extract(string $zipPath, string $destination): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The archive could not be opened.');
        }

        try {
            $this->assertEveryEntryIsSafe($zip);
            $this->writeEntries($zip, $destination);
        } finally {
            $zip->close();
        }
    }

    /**
     * First pass — nothing is written until every entry has been cleared.
     *
     * @throws RuntimeException
     */
    private function assertEveryEntryIsSafe(ZipArchive $zip): void
    {
        if ($zip->numFiles > $this->maxEntries) {
            throw new RuntimeException("Archive holds {$zip->numFiles} entries — more than the {$this->maxEntries} allowed.");
        }

        $declaredTotal = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false) {
                throw new RuntimeException("Unreadable entry at index {$i}.");
            }

            if ($this->isUnsafeName($name)) {
                throw new RuntimeException("Unsafe path in archive: {$name}");
            }

            if ($this->isSymlink($zip, $i)) {
                throw new RuntimeException("Archive contains a symlink: {$name}");
            }

            $stat = $zip->statIndex($i);
            $declaredTotal += $stat === false ? 0 : (int) $stat['size'];

            if ($declaredTotal > $this->maxTotalBytes) {
                throw new RuntimeException('Archive inflates beyond the allowed total size.');
            }
        }
    }

    private function isUnsafeName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0")) {
            return true;
        }

        // Treat both separators alike: a zip built on Windows can carry `..\`.
        $normalised = str_replace('\\', '/', $name);

        // Absolute POSIX path, or a Windows drive/UNC path.
        if (str_starts_with($normalised, '/') || preg_match('#^[a-zA-Z]:#', $normalised) === 1) {
            return true;
        }

        // Any `..` SEGMENT — segment-wise rather than substring, so a
        // legitimate file called `..thing` is allowed while `a/../../b` is not.
        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * A symlink is recorded in the unix mode bits of the entry's external
     * attributes. Extracting one lets a later entry write through it to any
     * path on disk, which no amount of name checking would catch.
     */
    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = null;
        $attr  = null;

        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        return (($attr >> 16) & 0xF000) === 0xA000;
    }

    /**
     * Second pass — write. Paths are re-checked against the resolved
     * destination as a belt-and-braces guard.
     *
     * @throws RuntimeException
     */
    private function writeEntries(ZipArchive $zip, string $destination): void
    {
        if (! is_dir($destination) && ! @mkdir($destination, 0775, true) && ! is_dir($destination)) {
            throw new RuntimeException("Could not create {$destination}.");
        }

        $root = realpath($destination);

        if ($root === false) {
            throw new RuntimeException("Could not resolve {$destination}.");
        }

        $writtenTotal = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name   = (string) $zip->getNameIndex($i);
            $target = $root.DIRECTORY_SEPARATOR.str_replace('\\', '/', $name);

            // Directory entry.
            if (str_ends_with($name, '/')) {
                if (! is_dir($target) && ! @mkdir($target, 0775, true) && ! is_dir($target)) {
                    throw new RuntimeException("Could not create directory for {$name}.");
                }

                continue;
            }

            $parent = dirname($target);
            if (! is_dir($parent) && ! @mkdir($parent, 0775, true) && ! is_dir($parent)) {
                throw new RuntimeException("Could not create directory for {$name}.");
            }

            // The parent now exists, so it resolves — confirm it really is
            // inside the destination before opening a handle on it.
            $resolvedParent = realpath($parent);
            if ($resolvedParent === false || ! $this->isInside($resolvedParent, $root)) {
                throw new RuntimeException("Entry escapes the destination: {$name}");
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                throw new RuntimeException("Could not read entry: {$name}");
            }

            $out = @fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                throw new RuntimeException("Could not write entry: {$name}");
            }

            // Copy in chunks, counting REAL bytes — the declared sizes were
            // checked up front, but a bomb's headers lie, so the cap has to
            // hold against what actually comes out of the stream.
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    fclose($out);
                    fclose($stream);
                    throw new RuntimeException("Could not read entry: {$name}");
                }

                $writtenTotal += strlen($chunk);
                if ($writtenTotal > $this->maxTotalBytes) {
                    fclose($out);
                    fclose($stream);
                    throw new RuntimeException('Archive inflates beyond the allowed total size.');
                }

                fwrite($out, $chunk);
            }

            fclose($out);
            fclose($stream);
        }
    }

    private function isInside(string $path, string $root): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR);
    }
}
