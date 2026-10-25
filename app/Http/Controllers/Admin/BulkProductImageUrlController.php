<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Images\RemoteImageFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk product images, the other way round: instead of the customer uploading
 * files, they give us links and the SERVER downloads them.
 *
 * This is the second half of the bulk-images screen, not a replacement for the
 * first. A customer with a folder of photos keeps dropping the folder in; a
 * customer whose supplier gave them a spreadsheet full of image links uses this
 * tab; a customer with both uses both. Nothing here changes the file-upload
 * path, and a product may have declared a filename AND a URL.
 *
 * Two calls, same shape as the upload flow:
 *
 *   1. POST /url-match — read-only preview. Either "show me what the import
 *      already declared" (source=pending, the server picks its own rows) or
 *      "here are SKU,URL lines I pasted" (source=paste). Nothing is written.
 *
 *   2. POST /url-fetch — does the work, FIVE URLS AT A TIME. Each call is one
 *      short-lived request; the browser loops. That is the whole reason this
 *      needs no queue and no cron: a shared host gives us one PHP process with
 *      a 30-second ceiling, and five 10-second timeouts must fit inside it —
 *      hence the wall-clock budget that hands unfinished rows back rather than
 *      risking a 504 that loses the batch.
 *
 * Every safety decision about a URL lives in {@see RemoteImageFetcher}: HTTPS
 * to a public address, redirects re-checked per hop, size capped, file type
 * decided by magic bytes. This controller only decides WHICH product a URL
 * belongs to — and it resolves that from the SKU the user typed, server-side,
 * so unlike the upload path there is no client-supplied product id to distrust.
 */
class BulkProductImageUrlController extends Controller
{
    /** Rows returned by one preview. Matches the upload path's match cap. */
    private const MAX_MATCH = 5000;

    /**
     * URLs accepted in one fetch call. Small on purpose — see the class doc.
     * Public because the screen has to loop in the same size we accept.
     */
    public const MAX_BATCH = 5;

    /**
     * Seconds of fetching before we stop and hand the rest back. Deliberately
     * well under a typical 30s max_execution_time: a batch of dead URLs is the
     * slow case, and returning "not done yet" is always better than a timeout
     * the browser can't interpret.
     */
    private const BUDGET_SECONDS = 20;

    /**
     * Preview which products a set of URLs would attach to. No writes.
     *
     * Response:
     *   matched:   [{ sku, product_id, name, url, has_image }]
     *   unmatched: [{ line, reason: 'bad_format'|'no_match'|'duplicate'|'bad_url' }]
     */
    public function match(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);

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

    /**
     * Download one batch and attach the results.
     *
     * Rows come back individually so a dead link doesn't sink the batch, and a
     * row the budget ran out on comes back as `deferred` — not an error, just
     * "send this one again".
     */
    public function fetch(Request $request, RemoteImageFetcher $fetcher): JsonResponse
    {
        $this->authorize('create', Product::class);

        $data = $request->validate([
            'rows'       => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'rows.*.sku' => ['required', 'string', 'max:255'],
            'rows.*.url' => ['required', 'string', 'max:2048'],
        ]);

        $rows = $data['rows'];

        $products = Product::query()
            ->whereIn('sku', array_column($rows, 'sku'))
            ->get()
            ->keyBy('sku');

        $results   = [];
        $fetched   = 0;
        $startedAt = microtime(true);

        try {
            foreach ($rows as $row) {
                $sku = (string) $row['sku'];
                $url = trim((string) $row['url']);

                // Out of time — hand the rest back untouched. The first row is
                // always attempted so a batch can never make zero progress and
                // loop forever.
                if ($results !== [] && (microtime(true) - $startedAt) > self::BUDGET_SECONDS) {
                    $results[] = ['sku' => $sku, 'ok' => false, 'error' => 'deferred'];

                    continue;
                }

                $product = $products->get($sku);

                if (! $product) {
                    $results[] = ['sku' => $sku, 'ok' => false, 'error' => 'not_found'];

                    continue;
                }

                $result = $fetcher->fetch($url);

                if (! $result->ok) {
                    $this->recordFailure($product, $url, (string) $result->error);
                    $results[] = ['sku' => $sku, 'ok' => false, 'error' => $result->error];

                    continue;
                }

                $path = $fetcher->store($result);

                if ($path === null) {
                    $this->recordFailure($product, $url, 'store_failed');
                    $results[] = ['sku' => $sku, 'ok' => false, 'error' => 'store_failed'];

                    continue;
                }

                $this->attach($product, $url, $path);

                $fetched++;
                $results[] = ['sku' => $sku, 'ok' => true, 'image_url' => $product->image_url];
            }
        } finally {
            // Temp files are held for the whole batch so several products
            // sharing one URL download it once. Nothing outlives the request.
            $fetcher->cleanup();
        }

        $attempted = array_filter($results, fn (array $r) => ($r['error'] ?? null) !== 'deferred');

        return response()->json([
            'fetched' => $fetched,
            'results' => $results,
            // A whole batch of connection failures usually means the shared
            // host blocks outbound HTTP entirely. The screen stops and says so
            // once, rather than spending ten seconds per URL discovering it
            // three thousand more times.
            'all_unreachable' => $attempted !== []
                && count(array_filter($attempted, fn (array $r) => ($r['error'] ?? null) === 'unreachable')) === count($attempted),
        ]);
    }

    // ── match sources ──────────────────────────────────────────────

    /**
     * Everything the import already declared — products carrying a URL we
     * haven't fetched yet. The server picks these itself; the client sends no
     * ids at all.
     *
     * @return array{matched: array<int, array<string, mixed>>, unmatched: array<int, array<string, string>>}
     */
    private function matchPending(): array
    {
        $rows = Product::query()
            ->pendingImageFetch()
            ->orderBy('id')
            ->limit(self::MAX_MATCH)
            ->get(['id', 'sku', 'name', 'image_src_url', 'image_path']);

        return [
            'matched' => $rows->map(fn (Product $p) => [
                'sku'        => (string) $p->sku,
                'product_id' => (int) $p->id,
                'name'       => (string) $p->name,
                'url'        => (string) $p->image_src_url,
                'has_image'  => $p->image_path !== null,
            ])->all(),
            'unmatched' => [],
        ];
    }

    /**
     * `SKU,URL` (or `SKU<tab>URL`) lines pasted straight out of a spreadsheet.
     *
     * Split on the FIRST separator only — a signed CDN link is full of commas
     * and would be shredded by a naive explode.
     *
     * @return array{matched: array<int, array<string, mixed>>, unmatched: array<int, array<string, string>>}
     */
    private function matchPasted(string $text): array
    {
        $wanted    = [];   // sku => url
        $unmatched = [];
        $seen      = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$sku, $url] = $this->splitLine($line);

            if ($sku === null || $url === null) {
                $unmatched[] = ['line' => $line, 'reason' => 'bad_format'];

                continue;
            }

            if (preg_match('~^https?://~i', $url) !== 1) {
                $unmatched[] = ['line' => $line, 'reason' => 'bad_url'];

                continue;
            }

            // The same SKU listed twice with different links is a decision we
            // can't make for them — reported, never guessed.
            if (isset($seen[$sku])) {
                $unmatched[] = ['line' => $line, 'reason' => 'duplicate'];

                continue;
            }

            $seen[$sku]   = true;
            $wanted[$sku] = $url;

            if (count($wanted) >= self::MAX_MATCH) {
                break;
            }
        }

        $products = $wanted === []
            ? collect()
            : Product::query()->whereIn('sku', array_keys($wanted))->get(['id', 'sku', 'name', 'image_path'])->keyBy('sku');

        $matched = [];
        foreach ($wanted as $sku => $url) {
            $product = $products->get($sku);

            if (! $product) {
                $unmatched[] = ['line' => $sku, 'reason' => 'no_match'];

                continue;
            }

            $matched[] = [
                'sku'        => (string) $product->sku,
                'product_id' => (int) $product->id,
                'name'       => (string) $product->name,
                'url'        => $url,
                'has_image'  => $product->image_path !== null,
            ];
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * Split one pasted line into SKU and URL.
     *
     * A TAB anywhere on the line wins over any comma: a tab only gets there by
     * pasting two spreadsheet columns, and in that case every comma on the
     * line is data rather than a separator. Only when there is no tab do we
     * fall back to the first comma.
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

        $sku = trim(substr($line, 0, $at), " \t\"'");
        $url = trim(substr($line, $at + 1), " \t\"'");

        return [$sku === '' ? null : $sku, $url === '' ? null : $url];
    }

    // ── writes ─────────────────────────────────────────────────────

    /** Swap in the fetched image and drop the file it replaced. */
    private function attach(Product $product, string $url, string $path): void
    {
        $old = $product->image_path;

        $product->forceFill([
            'image_path'         => $path,
            'image_src_url'      => $url,
            'image_fetch_status' => 'done',
            'image_fetch_error'  => null,
        ])->save();
        do_action('product.after_update', $product, ['image_path' => $old]);

        if ($old && $old !== $path && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);
        }
    }

    /**
     * Record why a URL didn't work. The row drops out of `pendingImageFetch`
     * so the next run doesn't spend another timeout on it — re-queueing is a
     * deliberate act (fix the link and re-import, or paste it again).
     */
    private function recordFailure(Product $product, string $url, string $error): void
    {
        $product->forceFill([
            'image_src_url'      => $url,
            'image_fetch_status' => 'failed',
            'image_fetch_error'  => mb_substr($error, 0, 255),
        ])->save();
    }
}
