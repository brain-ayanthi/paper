<?php

namespace App\Actions\Products;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Builds the next in-store product barcode — a valid EAN-13 — for
 * non-standard items that carry no manufacturer barcode
 * (docs/features/products-inventory.md §10).
 *
 * Layout: `[prefix][counter]` padded to 12 digits, then the EAN-13 check
 * digit appended → 13 digits total.
 *
 *   prefix 20, counter 42  →  200000000042  →  check 8  →  2000000000428
 *
 * The prefix (Company::barcodePrefix(), default `20`) lives in the GS1
 * 20–29 in-store range, which is reserved for restricted distribution —
 * so a generated code can never clash with a real product's GTIN.
 *
 * Only ever produced on demand (the form's "Generate" button), never
 * silently on save: a barcode is a *physical* identity, and inventing one
 * for an item that actually has a manufacturer code would make the
 * printed label disagree with the box. SKUs auto-fill when blank;
 * barcodes don't.
 *
 * ## Uniqueness
 *
 * `products.barcode` / `product_variants.barcode` are UNIQUE with no
 * `deleted_at` in the index, so a soft-deleted row still holds its
 * barcode. The scan uses `withTrashed()` across both tables and skips
 * anything taken. The counter also continues from the highest existing
 * generated barcode, so a store that already has some never restarts
 * from 1 and collides.
 *
 * Race-safety: two admins generating at the same instant can still land
 * on the same value. The DB unique index is the real backstop — the
 * caller catches the duplicate-key error and asks for the next one.
 *
 * Hooks:
 *   - filter `product.barcode.generated` → ($barcode, $seq)
 */
class GenerateProductBarcode
{
    /** EAN-13 is 13 digits: 12 of prefix+counter, then 1 check digit. */
    private const BODY_LENGTH = 12;

    /** Safety valve for the skip-taken loop — far above any real run. */
    private const MAX_PROBE = 1000;

    /**
     * @param array<int, string> $exclude Barcodes to treat as already taken
     *        on top of the catalogue — the values sitting UNSAVED in the
     *        form (parent + sibling variants). Without this, generating for
     *        two variants in a row would hand out the same code both times
     *        (the first isn't in the DB yet), and they'd collide on save.
     */
    public function __invoke(array $exclude = []): string
    {
        $prefix = (Company::current() ?? new Company())->barcodePrefix();
        $exclude = array_flip(array_map('strval', $exclude));

        // How many digits the counter gets once the prefix is placed. The
        // prefix accessor caps at 7 digits, so this is always ≥ 5.
        $counterWidth = self::BODY_LENGTH - strlen($prefix);
        $maxCounter   = (10 ** $counterWidth) - 1;

        $seq = $this->highestCounter($prefix, $counterWidth) + 1;

        for ($probe = 0; $probe < self::MAX_PROBE && $seq <= $maxCounter; $probe++, $seq++) {
            $body      = $prefix.str_pad((string) $seq, $counterWidth, '0', STR_PAD_LEFT);
            $candidate = $body.$this->checkDigit($body);

            if (! isset($exclude[$candidate]) && ! $this->isTaken($candidate)) {
                return (string) apply_filters('product.barcode.generated', $candidate, $seq);
            }
        }

        // Prefix range exhausted (millions of items) or a pathological
        // collision run — extremely unlikely. Surface it rather than
        // returning a duplicate or a malformed code.
        throw new \RuntimeException(__('products.barcode.errors.exhausted', ['prefix' => $prefix]));
    }

    /**
     * Highest counter already issued under this prefix, read from the
     * catalogue (products + variants) so numbering continues instead of
     * restarting. Trashed rows count — they still hold their barcode.
     *
     * Only well-formed 13-digit codes of the right prefix+width are
     * considered, so a hand-typed manufacturer barcode that happens to
     * start with the same digits can't skew the counter.
     */
    private function highestCounter(string $prefix, int $counterWidth): int
    {
        $like = $prefix.'%';

        $barcodes = Product::withTrashed()
            ->where('barcode', 'like', $like)
            ->pluck('barcode')
            ->merge(
                ProductVariant::withTrashed()
                    ->where('barcode', 'like', $like)
                    ->pluck('barcode')
            );

        $highest = 0;
        $plen    = strlen($prefix);

        foreach ($barcodes as $code) {
            $code = (string) $code;

            // Must be a full EAN-13 we could have generated: right length,
            // all digits, right prefix.
            if (strlen($code) !== 13 || ! ctype_digit($code) || substr($code, 0, $plen) !== $prefix) {
                continue;
            }

            $counter = (int) substr($code, $plen, $counterWidth);
            if ($counter > $highest) {
                $highest = $counter;
            }
        }

        return $highest;
    }

    private function isTaken(string $barcode): bool
    {
        return Product::withTrashed()->where('barcode', $barcode)->exists()
            || ProductVariant::withTrashed()->where('barcode', $barcode)->exists();
    }

    /**
     * EAN-13 check digit: from the right, digits alternate ×3 / ×1; the
     * check digit is what makes the weighted sum a multiple of 10.
     */
    private function checkDigit(string $body): string
    {
        $sum = 0;
        // $body is 12 digits. Position 0 (leftmost) is weighted ×1, and
        // weights alternate 1,3,1,3,… so odd 0-based positions are ×3.
        for ($i = 0; $i < strlen($body); $i++) {
            $digit = (int) $body[$i];
            $sum  += ($i % 2 === 0) ? $digit : $digit * 3;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }
}
