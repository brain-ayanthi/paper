<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Images\RemoteImageFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk brand logos from URLs — the mirror of
 * {@see BulkProductImageUrlController}, and the second tab of the bulk-logos
 * screen. The file-upload half is untouched and neither disables the other.
 *
 * One deliberate difference from the product version: brands have no SKU, so a
 * pasted line is `Brand name, URL` and the lookup is by NAME, case-insensitively.
 * That is the same key the brand importer already treats as a brand's identity
 * (a repeated name is an update, not a new brand), so nothing new is invented
 * here — but it does mean the batch caps are the same and the wall-clock budget
 * matters just as much, because a shared host is a shared host.
 *
 * Every safety decision about a URL lives in {@see RemoteImageFetcher}.
 */
class BulkBrandLogoUrlController extends Controller
{
    /** Rows returned by one preview. */
    private const MAX_MATCH = 5000;

    /** URLs accepted in one fetch call. Public — the screen loops in this size. */
    public const MAX_BATCH = 5;

    /** Seconds of fetching before unfinished rows are handed back. */
    private const BUDGET_SECONDS = 20;

    /**
     * Preview which brands a set of URLs would attach to. No writes.
     *
     * Response:
     *   matched:   [{ name, brand_id, url, has_image }]
     *   unmatched: [{ line, reason: 'bad_format'|'no_match'|'duplicate'|'bad_url' }]
     */
    public function match(Request $request): JsonResponse
    {
        $this->authorize('create', Brand::class);

        $data = $request->validate([
            'source' => ['required', 'string', 'in:pending,paste'],
            'text'   => ['nullable', 'string', 'max:500000'],
        ]);

        return response()->json(
            $data['source'] === 'pending'
                ? $this->matchPending()
                : $this->matchPasted((string) ($data['text'] ?? '')),
        );
    }

    /** Download one batch and attach the results. */
    public function fetch(Request $request, RemoteImageFetcher $fetcher): JsonResponse
    {
        $this->authorize('create', Brand::class);

        $data = $request->validate([
            'rows'        => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.url'  => ['required', 'string', 'max:2048'],
        ]);

        $rows = $data['rows'];

        // Look every named brand up in one query, keyed by lowered name so the
        // match is case-insensitive the way the importer's is.
        //
        // LOWER() in the query, not just in the keyBy: MySQL's default
        // collation would compare case-insensitively on its own, but SQLite
        // (and any case-sensitive collation) would not, and a rule that
        // depends on the database's collation isn't a rule.
        $brands = Brand::query()
            ->whereIn(DB::raw('LOWER(name)'), array_map(
                fn (array $r) => mb_strtolower((string) $r['name']),
                $rows,
            ))
            ->get()
            ->keyBy(fn (Brand $b) => mb_strtolower((string) $b->name));

        $results   = [];
        $fetched   = 0;
        $startedAt = microtime(true);

        try {
            foreach ($rows as $row) {
                $name = (string) $row['name'];
                $url  = trim((string) $row['url']);

                // Out of time — hand the rest back. The first row is always
                // attempted so a batch can never make zero progress.
                if ($results !== [] && (microtime(true) - $startedAt) > self::BUDGET_SECONDS) {
                    $results[] = ['name' => $name, 'ok' => false, 'error' => 'deferred'];

                    continue;
                }

                $brand = $brands->get(mb_strtolower($name));

                if (! $brand) {
                    $results[] = ['name' => $name, 'ok' => false, 'error' => 'not_found'];

                    continue;
                }

                $result = $fetcher->fetch($url);

                if (! $result->ok) {
                    $this->recordFailure($brand, $url, (string) $result->error);
                    $results[] = ['name' => $name, 'ok' => false, 'error' => $result->error];

                    continue;
                }

                $path = $fetcher->store($result, 'brands');

                if ($path === null) {
                    $this->recordFailure($brand, $url, 'store_failed');
                    $results[] = ['name' => $name, 'ok' => false, 'error' => 'store_failed'];

                    continue;
                }

                $this->attach($brand, $url, $path);

                $fetched++;
                $results[] = ['name' => $name, 'ok' => true, 'image_url' => $brand->logo_url];
            }
        } finally {
            $fetcher->cleanup();
        }

        $attempted = array_filter($results, fn (array $r) => ($r['error'] ?? null) !== 'deferred');

        return response()->json([
            'fetched' => $fetched,
            'results' => $results,
            // A whole batch of connection failures usually means the host
            // blocks outbound HTTP. Said once, rather than discovered a
            // hundred more times at ten seconds each.
            'all_unreachable' => $attempted !== []
                && count(array_filter($attempted, fn (array $r) => ($r['error'] ?? null) === 'unreachable')) === count($attempted),
        ]);
    }

    // ── match sources ──────────────────────────────────────────────

    /**
     * @return array{matched: array<int, array<string, mixed>>, unmatched: array<int, array<string, string>>}
     */
    private function matchPending(): array
    {
        $rows = Brand::query()
            ->pendingLogoFetch()
            ->orderBy('id')
            ->limit(self::MAX_MATCH)
            ->get(['id', 'name', 'logo_src_url', 'logo_path']);

        return [
            'matched' => $rows->map(fn (Brand $b) => [
                'name'     => (string) $b->name,
                'brand_id' => (int) $b->id,
                'url'      => (string) $b->logo_src_url,
                'has_image' => $b->logo_path !== null,   // shared row shape with the product screen
            ])->all(),
            'unmatched' => [],
        ];
    }

    /**
     * `Brand name,URL` (or tab-separated) lines. Split on the FIRST separator
     * only — brand names contain commas ("Ben & Jerry's, Inc.") about as often
     * as signed CDN links do.
     *
     * @return array{matched: array<int, array<string, mixed>>, unmatched: array<int, array<string, string>>}
     */
    private function matchPasted(string $text): array
    {
        $wanted    = [];   // lowered name => ['name' => original, 'url' => url]
        $unmatched = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$name, $url] = $this->splitLine($line);

            if ($name === null || $url === null) {
                $unmatched[] = ['line' => $line, 'reason' => 'bad_format'];

                continue;
            }

            if (preg_match('~^https?://~i', $url) !== 1) {
                $unmatched[] = ['line' => $line, 'reason' => 'bad_url'];

                continue;
            }

            $key = mb_strtolower($name);

            if (isset($wanted[$key])) {
                $unmatched[] = ['line' => $line, 'reason' => 'duplicate'];

                continue;
            }

            $wanted[$key] = ['name' => $name, 'url' => $url];

            if (count($wanted) >= self::MAX_MATCH) {
                break;
            }
        }

        $brands = $wanted === []
            ? collect()
            : Brand::query()
                ->whereIn(DB::raw('LOWER(name)'), array_keys($wanted))
                ->get(['id', 'name', 'logo_path'])
                ->keyBy(fn (Brand $b) => mb_strtolower((string) $b->name));

        $matched = [];
        foreach ($wanted as $key => $row) {
            $brand = $brands->get($key);

            if (! $brand) {
                $unmatched[] = ['line' => $row['name'], 'reason' => 'no_match'];

                continue;
            }

            $matched[] = [
                // The brand's own spelling, not the paste's — that's what the
                // fetch step will look up.
                'name'     => (string) $brand->name,
                'brand_id' => (int) $brand->id,
                'url'      => $row['url'],
                'has_image' => $brand->logo_path !== null,
            ];
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * Split one pasted line into name and URL.
     *
     * A TAB anywhere on the line wins over any comma, because a tab only gets
     * there by pasting two spreadsheet columns — and in that case every comma
     * on the line is data, not a separator. "Ben & Jerry's, Inc." is a real
     * brand name and must survive. Only when there is no tab do we fall back
     * to the first comma.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitLine(string $line): array
    {
        $at = strpos($line, "\t");

        if ($at === false) {
            $at = strpos($line, ',');
        }

        if ($at === false || $at === 0) {
            return [null, null];
        }

        $name = trim(substr($line, 0, $at), " \t\"'");
        $url  = trim(substr($line, $at + 1), " \t\"'");

        return [$name === '' ? null : $name, $url === '' ? null : $url];
    }

    // ── writes ─────────────────────────────────────────────────────

    private function attach(Brand $brand, string $url, string $path): void
    {
        $old = $brand->logo_path;

        $brand->forceFill([
            'logo_path'         => $path,
            'logo_src_url'      => $url,
            'logo_fetch_status' => 'done',
            'logo_fetch_error'  => null,
        ])->save();

        if ($old && $old !== $path && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);
        }
    }

    /** Record why a link didn't work, and drop it out of the pending queue. */
    private function recordFailure(Brand $brand, string $url, string $error): void
    {
        $brand->forceFill([
            'logo_src_url'      => $url,
            'logo_fetch_status' => 'failed',
            'logo_fetch_error'  => mb_substr($error, 0, 255),
        ])->save();
    }
}
