<?php

namespace App\Support;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTransfer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Gate;

/**
 * Turns a stock movement's `reference_type` + `reference_id` morph into a
 * link back to the document that caused the change, so the Stock Activity
 * ledger is navigable: click a `purchase` row and land on that purchase.
 *
 * Every writer of the ledger names one of the seven documents below (see
 * {@see \App\Actions\Inventory\RecordStockMovement} callers). A sale return
 * has no page of its own — it renders inside its parent sale — so it links
 * to the sale instead.
 *
 * The link is only offered when the viewer may actually open the target;
 * otherwise the reference stays plain text rather than sending them into a
 * 403. The gate is evaluated against the loaded record, so store scoping is
 * respected.
 */
final class StockMovementReference
{
    /**
     * Eager-load spec for the `reference` morph. `SaleReturn` needs its parent
     * sale — that is where its link points, and where the gate is checked.
     *
     * @return \Closure(MorphTo): void
     */
    public static function eagerLoad(): \Closure
    {
        return fn (MorphTo $morphTo) => $morphTo->morphWith([
            SaleReturn::class => ['sale'],
        ]);
    }

    /**
     * Resolve the reference cell for one ledger row.
     *
     * Returns `null` when the movement has no reference at all (opening
     * balances). `url` is null when the document was deleted, is of an
     * unknown type, or the viewer may not open it — the label still renders
     * so the audit trail stays complete.
     *
     * @return array{label: string, url: string|null}|null
     */
    public static function for(StockMovement $movement): ?array
    {
        if (! $movement->reference_type || ! $movement->reference_id) {
            return null;
        }

        $reference = $movement->reference;

        return [
            'label' => self::label($movement, $reference),
            'url'   => $reference ? self::url($reference) : null,
        ];
    }

    /**
     * Prefer the document's own human number (PO-000047) over the internal
     * morph pair, falling back to `Purchase#47` when the row is gone.
     */
    private static function label(StockMovement $movement, ?Model $reference): string
    {
        $number = $reference?->getAttribute('number');

        if (is_string($number) && $number !== '') {
            return $number;
        }

        return class_basename($movement->reference_type).'#'.$movement->reference_id;
    }

    /** The document's detail page, or null when it is not viewable. */
    private static function url(Model $reference): ?string
    {
        return match (true) {
            $reference instanceof Purchase => self::gated($reference, 'view',
                fn () => route('admin.purchases.show', $reference)),

            // Purchase returns have no policy — the controller gates them on
            // the class-level Purchase ability plus a store check.
            $reference instanceof PurchaseReturn => Gate::allows('viewAny', Purchase::class)
                ? route('admin.purchase-returns.show', $reference)
                : null,

            $reference instanceof Sale => self::gated($reference, 'view',
                fn () => route('admin.sales.show', $reference)),

            // Returns are listed on the sale they belong to.
            $reference instanceof SaleReturn => $reference->sale
                ? self::gated($reference->sale, 'view',
                    fn () => route('admin.sales.show', $reference->sale))
                : null,

            $reference instanceof StockAdjustment => self::gated($reference, 'view',
                fn () => route('admin.inventory.adjustments.show', $reference)),

            $reference instanceof StockTake => self::gated($reference, 'view',
                fn () => route('admin.inventory.stock-takes.show', $reference)),

            $reference instanceof StockTransfer => self::gated($reference, 'view',
                fn () => route('admin.inventory.transfers.show', $reference)),

            default => null,
        };
    }

    /** @param  \Closure(): string  $url */
    private static function gated(Model $reference, string $ability, \Closure $url): ?string
    {
        return Gate::allows($ability, $reference) ? $url() : null;
    }
}
