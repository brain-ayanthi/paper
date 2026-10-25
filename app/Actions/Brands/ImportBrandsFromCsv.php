<?php

namespace App\Actions\Brands;

use App\Models\Brand;
use App\Services\Excel\SpreadsheetReader;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Streaming CSV / XLSX → brands importer — the sibling of
 * {@see \App\Actions\Categories\ImportCategoriesFromCsv}, round-tripping
 * the columns {@see ExportBrands} writes.
 *
 * Two modes share one parsing engine:
 *
 *   - `preview(path)`  → headers + first N data rows for the mapping UI.
 *     Side-effect free.
 *   - `commit(path, mapping)` → re-reads the whole file, validates each
 *     row, and creates/updates brands. Returns a summary.
 *
 * Identity: rows match on NAME (case-insensitive) — a known name updates
 * that brand, a new name creates one. Re-importing the same file is
 * therefore an update, not a pile of duplicates.
 *
 * ## Logos
 *
 * The `logo` column carries a bare FILENAME, never the image itself — a
 * spreadsheet can't hold binary, and fetching remote URLs would mean
 * outbound HTTP from a shared host. The filename lands in
 * `brands.logo_ref`; the admin then drops the folder of files on
 * {@see \App\Http\Controllers\Admin\BulkBrandLogoController} and each one
 * fans out to the brand that declared it. Same two-step shape as product
 * images, so a customer only learns it once.
 *
 * Writes go through {@see CreateBrand}/{@see UpdateBrand} so brand hooks
 * + events fire exactly as they do from the editor. `is_default` is never
 * importable (system flag).
 *
 * Hook points:
 *   - filter `brands.import.row`    → adjust a normalized payload pre-validation
 *   - action `brands.before_import` → before the loop opens
 *   - action `brands.after_import`  → after the loop closes, with the summary
 */
class ImportBrandsFromCsv
{
    /** Importable target fields. Order drives the wizard's mapping dropdown. */
    public const TARGET_FIELDS = [
        // `logo` is a FILENAME the customer drops in later — or a link, in
        // which case buildPayload() records both. `logo_url` is the explicit
        // link column for sheets that carry a filename and a link side by side.
        'name', 'description', 'logo', 'logo_url', 'is_active',
    ];

    /** Maximum data rows surfaced in the preview step. */
    public const PREVIEW_ROWS = 10;

    public function __construct(
        private SpreadsheetReader $reader,
        private CreateBrand $createBrand,
        private UpdateBrand $updateBrand,
    ) {}

    /**
     * Side-effect-free preview of an uploaded spreadsheet.
     *
     * @return array{headers: array<int, string>, suggested_map: array<string, ?string>, rows: array<int, array<int, string>>, total_columns: int}
     */
    public function preview(string $path): array
    {
        $headers = [];
        $sample  = [];
        foreach ($this->reader->rows($path, self::PREVIEW_ROWS + 1) as $i => $row) {
            if ($i === 0) {
                $headers = $row;
                continue;
            }
            $sample[] = $row;
        }

        $suggested = [];
        foreach ($headers as $h) {
            $suggested[$h] = $this->suggestField($h);
        }

        return [
            'headers'       => $headers,
            'suggested_map' => $suggested,
            'rows'          => $sample,
            'total_columns' => count($headers),
        ];
    }

    /**
     * Apply the import.
     *
     * @param array<int|string, ?string> $mapping  column-index → target-field
     * @return array{created: int, updated: int, skipped: int, logos: int, errors: array<int, array{row: int, message: string}>}
     */
    public function commit(string $path, array $mapping, ?int $userId = null): array
    {
        do_action('brands.before_import', $path, $mapping);

        $mapping = $this->normalizeMapping($mapping);
        if (! in_array('name', $mapping, true)) {
            throw new \RuntimeException(__('brands.import.errors.name_required'));
        }

        // lower(name) → id, seeded with existing brands and extended as new
        // rows are created, so a name repeated later in the file updates the
        // row this run just made instead of colliding on the unique slug.
        $nameToId = Brand::query()->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [Str::lower((string) $name) => (int) $id])
            ->all();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $logos   = 0;
        $errors  = [];

        // 1-based for human-friendly error reporting (row 1 = header).
        $rowNum = 0;

        foreach ($this->reader->rows($path) as $i => $cells) {
            $rowNum++;
            if ($i === 0) continue; // header

            $payload = $this->buildPayload($cells, $mapping);
            if ($payload === null) {
                // Blank-name row — silently skipped (trailing blanks are common).
                continue;
            }

            $payload = apply_filters('brands.import.row', $payload, $cells, $mapping);

            $validator = $this->validateRow($payload);
            if ($validator->fails()) {
                $errors[] = ['row' => $rowNum, 'message' => $validator->errors()->first()];
                $skipped++;
                continue;
            }

            $key      = Str::lower($payload['name']);
            $existing = isset($nameToId[$key]) ? Brand::find($nameToId[$key]) : null;

            // An export → edit → re-import round trip carries every brand's
            // existing link back in unchanged. Only a CHANGED link is a new
            // job; an identical one keeps whatever state it already had, so
            // editing a description doesn't re-download the whole list.
            if ($existing
                && array_key_exists('logo_src_url', $payload)
                && $payload['logo_src_url'] !== null
                && $payload['logo_src_url'] === $existing->logo_src_url
            ) {
                $payload['logo_fetch_status'] = $existing->logo_fetch_status;
                $payload['logo_fetch_error']  = $existing->logo_fetch_error;
            }

            try {
                $brand = DB::transaction(function () use (&$created, &$updated, $existing, $payload) {
                    if ($existing) {
                        // Name is the identity key and slug is left alone so
                        // shared links keep working.
                        $this->updateBrand->__invoke($existing, $payload);
                        $updated++;
                        return $existing;
                    }

                    $payload['slug'] = Str::slug($payload['name']).'-'.Str::lower(Str::random(5));
                    $brand = $this->createBrand->__invoke($payload);
                    $created++;
                    return $brand;
                });
            } catch (QueryException $e) {
                // Surface the actual reason instead of a blank "couldn't
                // save". A whole file failing identically is almost always
                // structural (a missing column because migrations are
                // behind, a unique clash), and a generic message turns a
                // 10-second fix into a mystery.
                $errors[] = [
                    'row'     => $rowNum,
                    'message' => __('brands.import.errors.row_failed', [
                        'row'    => $rowNum,
                        'reason' => $this->dbReason($e),
                    ]),
                ];
                $skipped++;
                continue;
            }

            $nameToId[$key] = $brand->id;

            if (($payload['logo_ref'] ?? null) !== null) {
                $logos++;
            }
        }

        $summary = [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            // How many rows named a logo file — drives the "now upload them"
            // call to action on the done screen.
            'logos'   => $logos,
            'errors'  => $errors,
        ];

        do_action('brands.after_import', $summary);

        return $summary;
    }

    /* ── Internals ─────────────────────────────────────────────── */

    /**
     * Translate a raw spreadsheet row into a brand attributes array.
     * Returns null for a blank-name row (nothing to import).
     *
     * @param  array<int, string> $cells
     * @param  array<int, string> $mapping
     * @return array<string, mixed>|null
     */
    private function buildPayload(array $cells, array $mapping): ?array
    {
        $get = fn (string $field) => $this->valueFor($cells, $mapping, $field);

        $name = trim((string) $get('name'));
        if ($name === '') {
            return null;
        }

        $payload = ['name' => $name];

        // Only mapped columns participate in an update. Keeping unmapped
        // attributes out of the payload makes partial re-imports safe for
        // descriptions, status, and pending logo filenames.
        if (in_array('description', $mapping, true)) {
            $payload['description'] = $this->nullableString($get('description'));
        }
        if (in_array('is_active', $mapping, true)) {
            $payload['is_active'] = $this->boolish($get('is_active'), true);
        }
        if (in_array('logo', $mapping, true)) {
            // Bare filename only — a path or URL from a spreadsheet export
            // still matches, since the uploader compares basenames.
            $payload['logo_ref'] = $this->logoRef($get('logo'));
        }

        // A cell holding a link records BOTH: the link for the server to
        // download, and its basename so the same sheet still works for a
        // customer who'd rather drop the folder in themselves. Mirrors the
        // product importer exactly.
        //
        // These are written only when the column is mapped — otherwise a
        // later partial re-import would quietly wipe every pending download.
        if (in_array('logo', $mapping, true)) {
            $this->applyLogoUrl($payload, $this->logoSourceUrl($get('logo')));
        }

        // Applied second so an explicit `Logo URL` column wins the link half
        // while leaving the filename column's basename alone.
        if (in_array('logo_url', $mapping, true)) {
            $this->applyLogoUrl($payload, $this->logoSourceUrl($get('logo_url')));
        }

        return $payload;
    }

    /**
     * The fetchable link in a cell, or null when it holds a plain filename.
     * Only http(s) counts; the URL is stored as written and every safety
     * decision about it belongs to RemoteImageFetcher at download time.
     */
    private function logoSourceUrl(?string $v): ?string
    {
        $v = $this->nullableString($v);

        if ($v === null || preg_match('~^https?://~i', $v) !== 1) {
            return null;
        }

        return mb_strlen($v) > 2048 ? null : $v;
    }

    /**
     * Record a link and reset the fetch state with it — a new link is always
     * a new job, or a customer fixing a bad one would find their correction
     * silently ignored (the screen skips `failed` rows).
     *
     * @param array<string, mixed> $payload
     */
    private function applyLogoUrl(array &$payload, ?string $url): void
    {
        $payload['logo_src_url']      = $url;
        $payload['logo_fetch_status'] = $url === null ? null : 'pending';
        $payload['logo_fetch_error']  = null;
    }

    /**
     * Validate a row's normalized payload — the BrandRequest shape minus
     * the name-uniqueness rule (a repeated name is an update, not a
     * conflict).
     */
    private function validateRow(array $payload): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($payload, [
            'name'        => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'logo_ref'    => ['nullable', 'string', 'max:255'],
            'is_active'   => ['boolean'],
        ], [], [
            'logo_ref' => __('brands.import.fields.logo'),
        ]);
    }

    /**
     * A short, human-readable reason from a DB failure.
     *
     * Security register SEC #105: this used to fall through to
     * `trim($msg)` — the RAW driver message — which the `row_failed` string
     * showed to whoever ran the import. A duplicate name surfaced as
     * "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry
     * 'Acme' for key 'brands_name_unique'", handing out table and index names.
     * Known causes are now mapped to plain advice and anything unrecognised
     * gets a generic string, with the real message logged server-side so
     * support loses nothing.
     */
    private function dbReason(QueryException $e): string
    {
        $msg = $e->getMessage();

        // Drop the appended SQL statement — useless to a shopkeeper and
        // often longer than the screen.
        $msg = preg_replace('/\s*\(Connection:.*$/s', '', $msg) ?? $msg;
        $msg = preg_replace('/\s*\(SQL:.*$/s', '', $msg) ?? $msg;

        // The overwhelmingly common cause of a whole file failing: the
        // database is a migration behind, so a column the code writes
        // doesn't exist yet.
        // Wording varies by driver: MySQL "Unknown column", SQLite
        // "has no column named" / "no such column", Postgres "column …
        // does not exist".
        if (stripos($msg, 'Unknown column') !== false
            || stripos($msg, 'no such column') !== false
            || stripos($msg, 'has no column named') !== false
            || stripos($msg, 'does not exist') !== false) {
            return __('brands.import.errors.schema_outdated');
        }

        // A duplicate name is the other everyday cause and deserves advice
        // the shopkeeper can act on — without naming the index.
        if (str_contains($msg, '23000') || stripos($msg, 'Duplicate entry') !== false
            || stripos($msg, 'UNIQUE constraint failed') !== false) {
            return __('brands.import.errors.duplicate');
        }

        // Anything else: keep the detail where support can reach it, and tell
        // the importer only that the row failed.
        \Illuminate\Support\Facades\Log::warning('Brand import row failed.', [
            'exception' => $e->getMessage(),
        ]);

        return __('brands.import.errors.generic');
    }

    /** Reduce whatever the sheet holds to a bare filename, or null. */
    private function logoRef(?string $raw): ?string
    {
        $raw = $raw === null ? '' : trim($raw);
        if ($raw === '') {
            return null;
        }

        // Drop any query string / fragment, then take the last path segment
        // so `images/acme.png` and `https://x/acme.png?v=2` both land on
        // `acme.png` — exactly what the uploader will compare against.
        $raw = preg_replace('/[?#].*$/', '', $raw) ?? $raw;
        $name = basename(str_replace('\\', '/', $raw));

        return $name === '' ? null : $name;
    }

    /**
     * Best-guess mapping for a header string — seeds the UI only; the user
     * can override any suggestion before committing.
     */
    private function suggestField(string $header): ?string
    {
        $needle = $this->canonical($header);

        $aliases = [
            'name'        => ['name', 'brand', 'brandname', 'title', 'manufacturer'],
            'description' => ['description', 'desc', 'notes', 'summary'],
            'logo'        => ['logo', 'image', 'logofile', 'logofilename', 'imagefile', 'picture', 'photo'],
            'logo_url'    => ['logourl', 'logolink', 'imageurl', 'imagelink', 'logosrc', 'photourl'],
            'is_active'   => ['active', 'enabled', 'status', 'isactive'],
        ];

        foreach ($aliases as $field => $candidates) {
            if (in_array($needle, $candidates, true)) {
                return $field;
            }
        }

        return null;
    }

    /** Strip non-alphanumerics + lowercase. "Brand Name" ⇒ "brandname". */
    private function canonical(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($s)) ?: '';
    }

    /**
     * Coerce the wizard's mapping into a plain int-keyed array of target
     * fields, dropping null ("skip this column") entries.
     *
     * @param  array<int|string, ?string> $mapping
     * @return array<int, string>
     */
    private function normalizeMapping(array $mapping): array
    {
        $out = [];
        foreach ($mapping as $idx => $target) {
            if ($target === null || $target === '') continue;
            if (! in_array($target, self::TARGET_FIELDS, true)) continue;
            $out[(int) $idx] = $target;
        }
        return $out;
    }

    /** @param array<int, string> $cells @param array<int, string> $mapping */
    private function valueFor(array $cells, array $mapping, string $field): ?string
    {
        $index = array_search($field, $mapping, true);
        if ($index === false) return null;
        return $cells[$index] ?? null;
    }

    private function nullableString(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);
        return ($v === null || $v === '') ? null : $v;
    }

    private function boolish(?string $v, bool $default): bool
    {
        if ($v === null) return $default;
        $v = strtolower(trim($v));
        if ($v === '') return $default;
        return in_array($v, ['1', 'true', 'yes', 'y', 'on'], true);
    }
}
