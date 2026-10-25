<?php

namespace App\Services\Images;

use App\Support\SafeRemoteUrl;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloads an image the customer pointed us at, and hands back a file we have
 * proven is an image.
 *
 * This is the one place in the app where a URL a customer typed (or pasted into
 * a spreadsheet cell) turns into an outbound request from their own server, so
 * it is treated as a trust boundary rather than a download helper. Five rules,
 * each closing a specific hole:
 *
 *  1. SSRF. Every URL goes through {@see SafeRemoteUrl} — HTTPS, to an address
 *     that isn't loopback / RFC1918 / link-local (169.254.169.254 is cloud
 *     credentials). Same guard the updater uses.
 *
 *  2. Redirects. Guzzle follows redirects by default, which would make rule 1
 *     decorative: a public URL answers 302 and points the fetch at 127.0.0.1.
 *     So redirects are turned OFF and followed by hand, re-checking every hop.
 *     The updater does NOT do this today; it should, and this is the pattern.
 *
 *  3. Size. Capped three ways — the declared Content-Length (`on_headers`), the
 *     bytes actually arriving (`progress`, for a chunked server that declares
 *     nothing), and a filesize check once it's on disk. A server that lies at
 *     every layer is still bounded by the request timeout.
 *
 *  4. Type. Decided by MAGIC BYTES via getimagesize(), never by the URL's
 *     extension and never by Content-Type — both are attacker-chosen. JPEG,
 *     PNG and WebP only, matching the upload path. SVG has no image type at
 *     all, so it falls out here automatically, which is what we want: SVG on a
 *     public disk is stored XSS (same reason the brand logo screen refuses it).
 *
 *  5. Naming. The stored file gets a random name and the extension the magic
 *     bytes imply. Nothing from the URL reaches the filesystem.
 *
 * Failure is never an exception — every path returns an {@see ImageFetchResult}
 * with a stable error code, because a bulk run of 3,000 URLs will always have
 * some dead ones and one bad URL must not sink the batch.
 *
 * Usage is fetch-then-store, deliberately split: several products can share one
 * URL (a supplier's placeholder), and this way that downloads once and stores a
 * separate copy per product. Separate copies matter — the image-swap path
 * deletes a product's previous file, which would pull the rug out from under
 * every other product if they shared one.
 *
 *     $fetcher = app(RemoteImageFetcher::class);
 *     $result  = $fetcher->fetch($url);
 *     $path    = $result->ok ? $fetcher->store($result) : null;
 *     $fetcher->cleanup();   // or just let it fall out of scope
 */
class RemoteImageFetcher
{
    /** Image types we accept, mapped to the extension we store them under. */
    private const TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    /** A URL longer than this is refused unread — no legitimate image needs it. */
    private const MAX_URL_LENGTH = 2048;

    /**
     * Results already produced in this run, keyed by URL. Failures are cached
     * too: re-trying a URL that just timed out would cost another ten seconds
     * of the one request we get on shared hosting.
     *
     * @var array<string, ImageFetchResult>
     */
    private array $memo = [];

    /** @var array<int, string> Temp files to delete when the run ends. */
    private array $temps = [];

    /**
     * Download and validate one URL. Safe to call repeatedly with the same URL —
     * the second call is free.
     */
    public function fetch(string $url): ImageFetchResult
    {
        $url = trim($url);

        if ($url === '' || mb_strlen($url) > self::MAX_URL_LENGTH) {
            return ImageFetchResult::fail('blocked_url');
        }

        // Public filter so a plugin can rewrite the source (sign it, swap a CDN
        // host, strip tracking params). It runs BEFORE the safety checks, never
        // after — a filter must not be able to smuggle a URL past them.
        $url = trim((string) apply_filters('image.fetch_url', $url));

        if (array_key_exists($url, $this->memo)) {
            return $this->memo[$url];
        }

        do_action('image.before_fetch', $url);

        $result = $this->run($url);

        $this->memo[$url] = $result;

        do_action('image.after_fetch', $url, $result);

        return $result;
    }

    /**
     * Copy a fetched image onto the public disk under a random name.
     * Returns the stored path, or null if the write failed.
     */
    public function store(ImageFetchResult $result, string $dir = 'products'): ?string
    {
        if (! $result->ok || $result->tempPath === null || ! is_file($result->tempPath)) {
            return null;
        }

        try {
            $stored = Storage::disk('public')->putFileAs(
                $dir,
                new File($result->tempPath),
                Str::random(40).'.'.$result->extension,
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /** Remove every temp file this run downloaded. */
    public function cleanup(): void
    {
        foreach ($this->temps as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->temps = [];
        $this->memo  = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    // ── internals ──────────────────────────────────────────────────

    /** The actual download, minus memoisation and hooks. */
    private function run(string $url): ImageFetchResult
    {
        $maxBytes = max(1, (int) config('pos.images.max_kb', 4096)) * 1024;

        $tmp = tempnam(sys_get_temp_dir(), 'pos-img-');
        if ($tmp === false) {
            return ImageFetchResult::fail('store_failed');
        }
        $this->temps[] = $tmp;

        $maxHops = max(0, (int) config('pos.images.max_redirects', 3));
        $current = $url;

        for ($hop = 0; ; $hop++) {
            // Re-checked on EVERY hop, not just the first — that is the whole
            // point of following redirects by hand.
            if (SafeRemoteUrl::reject($current) !== null) {
                return ImageFetchResult::fail('blocked_url');
            }

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'on_headers'      => function ($psrResponse) use ($maxBytes) {
                        if ((int) $psrResponse->getHeaderLine('Content-Length') > $maxBytes) {
                            throw new ImageTooLargeException();
                        }
                    },
                    'progress' => function ($downloadTotal, $downloadedBytes) use ($maxBytes) {
                        if ($downloadTotal > $maxBytes || $downloadedBytes > $maxBytes) {
                            throw new ImageTooLargeException();
                        }
                    },
                ])
                    ->withHeaders([
                        'User-Agent' => (string) config('pos.images.user_agent'),
                        'Accept'     => 'image/*',
                    ])
                    ->connectTimeout((int) config('pos.images.connect_timeout', 5))
                    ->timeout((int) config('pos.images.timeout', 10))
                    ->sink($tmp)
                    ->get($current);
            } catch (\Throwable $e) {
                // Connection refused, DNS failure, timeout, or our own size
                // abort surfacing through Guzzle's wrapper.
                return ImageFetchResult::fail($this->isTooLarge($e) ? 'too_large' : 'unreachable');
            }

            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                $location = trim((string) $response->header('Location'));

                if ($location === '' || $hop >= $maxHops) {
                    return ImageFetchResult::fail('too_many_redirects');
                }

                $next = $this->absolutize($location, $current);
                if ($next === null) {
                    return ImageFetchResult::fail('blocked_url');
                }

                $current = $next;

                continue;
            }

            if ($status < 200 || $status >= 300) {
                return ImageFetchResult::fail('http_error');
            }

            break;
        }

        clearstatcache(true, $tmp);
        $bytes = (int) @filesize($tmp);

        if ($bytes <= 0) {
            return ImageFetchResult::fail('not_an_image');
        }

        // Belt and braces behind on_headers/progress: whatever the server said,
        // this is what actually landed.
        if ($bytes > $maxBytes) {
            return ImageFetchResult::fail('too_large');
        }

        $info = @getimagesize($tmp);
        $type = is_array($info) ? ($info[2] ?? 0) : 0;

        if (! isset(self::TYPES[$type])) {
            return ImageFetchResult::fail('not_an_image');
        }

        return ImageFetchResult::ok($tmp, self::TYPES[$type], $bytes, $current);
    }

    /** Did this exception (or anything it wraps) come from our size cap? */
    private function isTooLarge(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ImageTooLargeException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn a `Location` header into an absolute URL against the URL that
     * returned it. Returns null for anything we won't follow — a non-HTTP
     * scheme, or a base we can't make sense of.
     *
     * The result still goes through SafeRemoteUrl on the next pass, so this
     * only has to be honest, not safe.
     */
    private function absolutize(string $location, string $base): ?string
    {
        $parts = parse_url($base);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        // Protocol-relative: //cdn.example.com/a.jpg
        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }

        if (preg_match('~^https?://~i', $location) === 1) {
            return $location;
        }

        // Any other scheme (file:, gopher:, data:) is not something we follow.
        if (str_contains($location, ':') && preg_match('~^[a-z][a-z0-9+.-]*:~i', $location) === 1) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path  = $parts['path'] ?? '/';
        $slash = strrpos($path, '/');
        $dir   = $slash === false ? '' : substr($path, 0, $slash);

        return $origin.$dir.'/'.$location;
    }
}
