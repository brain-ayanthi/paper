<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Bulk brand logos — the second half of the brand import, and the exact
 * shape of {@see BulkProductImageController} so a customer only learns
 * the pattern once.
 *
 * A spreadsheet can't carry binary and fetching remote URLs would mean
 * outbound HTTP from a shared host, so the import's `Logo` column records
 * a FILENAME (`brands.logo_ref`). Here the admin drops the folder of
 * files and each one fans out to the brand that declared it.
 *
 * Two calls, deliberately thin so it survives shared hosting:
 *
 *   1. POST /match  — send just the filenames (JSON, no bytes). Returns
 *      which map to a brand, so the screen can show "48 matched · 2
 *      unmatched" BEFORE any upload. One indexed `WHERE logo_ref IN (…)`.
 *
 *   2. POST /store  — the browser uploads matched files in SMALL BATCHES,
 *      each request well under `post_max_size`, nothing long-running
 *      server-side. No queue needed.
 *
 * Match is case-insensitive on the basename. A filename claimed by more
 * than one brand is a conflict — reported rather than guessed at.
 */
class BulkBrandLogoController extends Controller
{
    /**
     * Upload constraints — mirror the single-logo editor, a touch larger.
     *
     * SVG is deliberately absent (SEC #104). These files land on the PUBLIC
     * disk and are served from the app's own origin as image/svg+xml, so a
     * crafted SVG at /storage/... executes its embedded <script> as whoever
     * opens it — stored XSS that anyone holding Brand-create could plant and
     * that fires when a higher-privileged user views the logo. The rule was
     * also mimes-only with no image guard. Sanitising SVG needs a dedicated
     * library (CLAUDE.md §4: no new dependencies without approval), so the
     * format is refused.
     */
    private const ALLOWED_MIMES = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_KB        = 2048;   // 2 MB per logo — artwork, not photos
    private const MAX_BATCH     = 50;     // files accepted in one /store call
    private const MAX_MATCH     = 5000;   // filenames accepted in one /match call

    public function index(): View
    {
        $this->authorize('create', Brand::class);

        // How many brands are still waiting for artwork they've declared —
        // a ref on record but no stored file. Drives the screen's hint.
        $pending = Brand::query()
            ->whereNotNull('logo_ref')
            ->whereNull('logo_path')
            ->count();

        // The other kind of waiting: a link on record that nobody has
        // downloaded. The two counts overlap by design — an import cell
        // holding a link records both a filename and a URL.
        $pendingUrls = Brand::query()->pendingLogoFetch()->count();

        return view('admin.brands.bulk-logos.index', [
            'pendingCount'    => $pending,
            'pendingUrlCount' => $pendingUrls,
            'maxKb'           => self::MAX_KB,
            'maxBatch'        => self::MAX_BATCH,
            'acceptMimes'     => self::ALLOWED_MIMES,
            'urlBatchSize'    => BulkBrandLogoUrlController::MAX_BATCH,
            // Opened straight onto the URL tab from the import's done screen.
            'initialTab'      => request()->query('tab') === 'urls' ? 'urls' : 'files',
        ]);
    }

    /**
     * Match a list of filenames to brands by logo_ref. No files, no writes —
     * this powers the pre-upload "matched / unmatched" preview.
     *
     * Response:
     *   matched:   [{ filename, brand_id, name, sku, has_image }]
     *   unmatched: [{ filename, reason: 'no_match' | 'duplicate' }]
     *
     * `sku` / `has_image` keep the payload shape identical to the product
     * matcher so both screens can share one Alpine component.
     */
    public function match(Request $request): JsonResponse
    {
        $this->authorize('create', Brand::class);

        $data = $request->validate([
            'filenames'   => ['required', 'array', 'min:1', 'max:'.self::MAX_MATCH],
            'filenames.*' => ['required', 'string', 'max:255'],
        ]);

        // De-dupe + index by lowercased basename. Two different folder paths
        // that end in the same filename are the same match target.
        $wanted = [];
        foreach ($data['filenames'] as $raw) {
            $name = $this->basename($raw);
            if ($name === '') {
                continue;
            }
            $wanted[mb_strtolower($name)] = $name; // keep first original casing for display
        }

        if ($wanted === []) {
            return response()->json(['matched' => [], 'unmatched' => []]);
        }

        $rows = Brand::query()
            ->whereIn(DB::raw('LOWER(logo_ref)'), array_keys($wanted))
            ->get(['id', 'name', 'logo_ref', 'logo_path']);

        $byRef = [];
        foreach ($rows as $b) {
            $byRef[mb_strtolower((string) $b->logo_ref)][] = $b;
        }

        $matched   = [];
        $unmatched = [];
        foreach ($wanted as $lower => $display) {
            $hits = $byRef[$lower] ?? [];
            if (count($hits) === 0) {
                $unmatched[] = ['filename' => $display, 'reason' => 'no_match'];
            } elseif (count($hits) > 1) {
                $unmatched[] = ['filename' => $display, 'reason' => 'duplicate'];
            } else {
                $b = $hits[0];
                $matched[] = [
                    'filename'  => $display,
                    'brand_id'  => (int) $b->id,
                    'name'      => (string) $b->name,
                    'sku'       => '',
                    'has_image' => $b->logo_path !== null,
                ];
            }
        }

        return response()->json([
            'matched'   => $matched,
            'unmatched' => $unmatched,
        ]);
    }

    /**
     * Store one batch of logos. Files arrive keyed by brand id
     * (`files[<brand_id>]`), which the client took from a prior /match call.
     *
     * Per-file results come back so the UI can mark each row done or failed
     * without one bad file sinking the whole batch.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Brand::class);

        $request->validate([
            'files'   => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'files.*' => ['required', 'file', 'mimes:'.implode(',', self::ALLOWED_MIMES), 'max:'.self::MAX_KB],
        ]);

        /** @var array<int|string, \Illuminate\Http\UploadedFile> $files */
        $files = $request->file('files', []);

        $ids    = array_map('intval', array_keys($files));
        $brands = Brand::query()->whereIn('id', $ids)->get()->keyBy('id');

        $results = [];
        $stored  = 0;

        foreach ($files as $key => $file) {
            $brandId = (int) $key;
            $brand   = $brands->get($brandId);

            if (! $brand) {
                $results[] = ['brand_id' => $brandId, 'ok' => false, 'error' => 'not_found'];
                continue;
            }

            // Re-derive the target from the FILE, not from the posted key —
            // the same guard BulkProductImageController::store() carries
            // (SEC #66). The match step runs in the browser and its result is
            // never re-checked, so the id in the multipart field name is only
            // ever whatever the client chose to send; without this, a crafted
            // request could replace ANY brand's logo (and delete its current
            // file) by naming a different id.
            //
            // The rule is the one the match step already uses: a brand claims
            // a file by recording its filename in `logo_ref`.
            $uploadedName = mb_strtolower((string) $file->getClientOriginalName());
            $claimedRef   = mb_strtolower((string) $brand->logo_ref);

            if ($claimedRef === '' || $claimedRef !== $uploadedName) {
                $results[] = ['brand_id' => $brandId, 'ok' => false, 'error' => 'ref_mismatch'];
                continue;
            }

            try {
                $path = $file->store('brands', 'public');
            } catch (\Throwable) {
                $results[] = ['brand_id' => $brandId, 'ok' => false, 'error' => 'store_failed'];
                continue;
            }

            // Swap in the new logo, delete the old file (best-effort).
            $old = $brand->logo_path;
            $brand->forceFill(['logo_path' => $path])->save();
            if ($old && $old !== $path && Storage::disk('public')->exists($old)) {
                Storage::disk('public')->delete($old);
            }

            $stored++;
            $results[] = [
                'brand_id'  => $brandId,
                'ok'        => true,
                'image_url' => Storage::disk('public')->url($path),
            ];
        }

        return response()->json([
            'stored'  => $stored,
            'results' => $results,
        ]);
    }

    /** Bare, lower-safe filename from a raw path or URL fragment. */
    private function basename(string $raw): string
    {
        $raw = preg_replace('/[?#].*$/', '', trim($raw)) ?? $raw;

        return basename(str_replace('\\', '/', $raw));
    }
}
