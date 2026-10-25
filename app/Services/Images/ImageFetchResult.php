<?php

namespace App\Services\Images;

/**
 * The outcome of one remote image fetch.
 *
 * On success it carries a TEMP file that has already been proven to be a real
 * image, plus the extension that proof implies — never the extension from the
 * URL. The caller turns that into a stored file via
 * {@see RemoteImageFetcher::store()}; the temp file survives until the fetcher
 * cleans up, so several products sharing one URL each get their own copy from a
 * single download.
 *
 * On failure `error` is a short stable CODE, not a sentence — the screen turns
 * it into a translated string, and the importer writes it to
 * `products.image_fetch_error`. The codes are:
 *
 *   blocked_url         refused before any request (not HTTPS, private address,
 *                       non-http scheme, empty, absurdly long) — includes a
 *                       redirect that tried to land somewhere private
 *   too_many_redirects  hop limit reached, or a redirect with no Location
 *   unreachable         DNS/connection/timeout — no HTTP response at all
 *   http_error          the server answered, but not with 2xx
 *   too_large           bigger than pos.images.max_kb
 *   not_an_image        empty, or not a JPEG/PNG/WebP by its magic bytes
 *   store_failed        downloaded fine, but writing to the disk failed
 */
final class ImageFetchResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
        public readonly ?string $tempPath = null,
        public readonly ?string $extension = null,
        public readonly int $bytes = 0,
        public readonly ?string $finalUrl = null,
    ) {}

    public static function ok(string $tempPath, string $extension, int $bytes, string $finalUrl): self
    {
        return new self(true, null, $tempPath, $extension, $bytes, $finalUrl);
    }

    public static function fail(string $code): self
    {
        return new self(false, $code);
    }

    /**
     * True when nothing answered at all. The screen watches for a whole batch of
     * these: it usually means the shared host blocks outbound connections, which
     * is worth saying once rather than letting 900 URLs fail one at a time.
     */
    public function isUnreachable(): bool
    {
        return $this->error === 'unreachable';
    }
}
