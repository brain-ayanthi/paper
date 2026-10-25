<?php

namespace App\Services\Updater;

use App\Support\SafeRemoteUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the update feed — the single static JSON endpoint we control
 * (see docs/features/updater-hooks.md §2). This is the only place that knows
 * the feed's URL and shape.
 *
 * A slow or unreachable feed must NEVER break the app: every failure resolves
 * to null (logged), and the caller treats that as "couldn't check" rather than
 * an error. The feed URL passes through the `updater.feed_url` filter so a fork
 * can point it elsewhere.
 */
class UpdateClient
{
    /**
     * Fetch and decode the feed, or null if it can't be reached / parsed.
     *
     * @return array<string,mixed>|null
     */
    public function fetchFeed(): ?array
    {
        $url = (string) apply_filters('updater.feed_url', (string) config('pos.updater.feed_url'));
        if ($url === '') {
            return null;
        }

        // The URL passes through a public filter, so a plugin can move it —
        // check it like anything else remote (SEC #29).
        if ($reason = SafeRemoteUrl::reject($url)) {
            Log::warning('Updater feed URL refused: '.$reason, ['url' => $url]);

            return null;
        }

        try {
            $response = Http::timeout((int) config('pos.updater.feed_timeout', 15))
                ->acceptJson()
                ->get($url);

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Stream a remote file (a release zip or its `.sig`) straight to disk.
     * Returns false on any failure — the caller decides whether that's fatal.
     */
    public function download(string $url, string $destPath): bool
    {
        if ($url === '') {
            return false;
        }

        // This URL comes out of the FEED — remote, attacker-influenceable
        // data — and is fetched by the server with no user in the loop, which
        // is textbook SSRF (SEC #29). Refuse anything that isn't plain HTTPS
        // to a public address.
        if ($reason = SafeRemoteUrl::reject($url)) {
            Log::warning('Updater download URL refused: '.$reason, ['url' => $url]);

            return false;
        }

        try {
            $dir = dirname($destPath);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $response = Http::timeout((int) config('pos.updater.download_timeout', 300))
                ->sink($destPath)
                ->get($url);

            return $response->successful();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
