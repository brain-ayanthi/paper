<?php

namespace App\Services\Hardware;

use App\Models\Company;
use App\Models\Sale;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a completed {@see Sale} into an ESC/POS byte stream for a
 * thermal printer (docs/features/hardware.md §5.2). The layout mirrors
 * the HTML receipt (`resources/views/sales/receipt.blade.php`) — same
 * store header, item list, totals, and payments — so a customer gets
 * the same receipt whether it's printed on thermal or browser-print.
 *
 * Reads the Sale model directly (like the blade does) rather than going
 * through an intermediate DTO; honours the same `company.receipt_*`
 * toggles. Prints the logo as an ESC/POS raster (falling back to a text
 * header), the sale number as a native Code 128 barcode, and the digital
 * receipt link as a native QR — the printer rasterises both, so no image
 * bytes travel over the wire.
 */
class EscPosFormatter
{
    public function __construct(private RasterToEscPos $raster = new RasterToEscPos()) {}

    /**
     * Build the raw byte stream. The caller base64-encodes it for the
     * JSON transport to the client print bridge.
     */
    public function format(Sale $sale, PrinterConfig $config, ?Company $company = null): string
    {
        $company ??= Company::current() ?? new Company();
        $w = $config->charWidth;

        $E = EscPosCommands::class;
        $out = $E::INIT;

        /* ── Store header (centered) ───────────────────────────── */
        $out .= $E::ALIGN_CENTER;

        if ($company->receipt_show_logo) {
            // Raster the logo image when present; fall back to the app/brand
            // name in bold if there's no logo or conversion fails.
            $logo = $this->logoRaster($company, $config);
            $out .= $logo !== ''
                ? $logo."\n"
                : $E::BOLD_ON.$this->clean($company->display_app_name)."\n".$E::BOLD_OFF;
        }

        $out .= $E::BOLD_ON.$E::SIZE_DOUBLE_HEIGHT;
        $out .= $this->clean($sale->store?->name)."\n";
        $out .= $E::SIZE_NORMAL.$E::BOLD_OFF;

        foreach ($this->storeAddressLines($sale) as $line) {
            $out .= $this->clean($line)."\n";
        }
        if ($company->tax_registration_number) {
            $out .= __('sales.receipt.gstin').': '.$this->clean($company->tax_registration_number)."\n";
        }
        if (trim((string) $company->receipt_header) !== '') {
            $out .= "\n".$this->clean($company->receipt_header)."\n";
        }

        /* ── Meta (left) ───────────────────────────────────────── */
        $out .= $E::ALIGN_LEFT;
        $out .= $this->rule($w);
        $out .= $E::BOLD_ON.$this->clean($sale->number)."\n".$E::BOLD_OFF;
        $out .= format_datetime($sale->sale_datetime ?? $sale->created_at)."\n";

        if ($company->receipt_show_customer && $sale->customer) {
            $line = __('sales.receipt.customer').': '.$sale->customer->name
                .($sale->customer->phone ? ' · '.$sale->customer->phone : '');
            $out .= $this->wrap($this->clean($line), $w);
        }
        if ($company->receipt_show_cashier && $sale->cashier) {
            $out .= $this->wrap(__('sales.receipt.cashier').': '.$this->clean($sale->cashier->name), $w);
        }

        /* ── Items ─────────────────────────────────────────────── */
        $out .= $this->rule($w);
        foreach ($sale->items as $item) {
            $name = $item->product_name_snapshot ?: $item->product?->name;
            if ($item->variant?->label) {
                $name .= ' · '.$item->variant->label;
            }
            $out .= $this->wrap($this->clean($name), $w);

            if ($company->receipt_show_sku && ($sku = $item->sku_snapshot ?: $item->product?->sku)) {
                $out .= $this->wrap('  '.__('sales.receipt.sku').': '.$this->clean($sku), $w);
            }
            if ($company->receipt_show_hsn && ($hsn = $item->hsn_snapshot ?: $item->product?->hsn_code)) {
                $out .= $this->wrap('  '.__('sales.receipt.hsn').': '.$this->clean($hsn), $w);
            }

            $qty = rtrim(rtrim((string) $item->quantity, '0'), '.') ?: '0';
            $out .= $this->row(
                '  '.$qty.' x '.format_money($item->unit_price),
                format_money($item->line_total),
                $w,
            );

            if ($item->batch) {
                $note = 'Batch '.$item->batch->batch_number
                    .($item->batch->expiry_date ? ' · Exp '.$item->batch->expiry_date->toDateString() : '');
                $out .= $this->wrap('  '.$this->clean($note), $w);
            }
        }

        /* ── Totals ────────────────────────────────────────────── */
        $out .= $this->rule($w);

        // Pre-discount gross subtotal — mirror the blade's reconstruction
        // (sale stores POST-discount net + tax).
        $grossSubtotal = bcadd(
            bcadd((string) $sale->subtotal, (string) $sale->tax_total, 4),
            (string) $sale->discount_total,
            4,
        );

        $out .= $this->row(__('sales.receipt.subtotal'), format_money($grossSubtotal), $w);

        // Loyalty redemption rides inside discount_total (§9.3) — split it
        // back out so the shopper sees what their points did. Mirrors the
        // blade exactly; the two receipts must never disagree.
        $loyaltyValue    = (string) ($sale->loyalty_value_redeemed ?? '0');
        $loyaltyPoints   = (int) ($sale->loyalty_points_redeemed ?? 0);
        $cashierDiscount = bcsub((string) $sale->discount_total, $loyaltyValue, 4);
        if (bccomp($cashierDiscount, '0', 4) < 0) {
            $cashierDiscount = '0';
        }

        if (bccomp($cashierDiscount, '0', 4) > 0) {
            $out .= $this->row(__('sales.receipt.discount'), '-'.format_money($cashierDiscount), $w);
        }
        if ($loyaltyPoints > 0) {
            $out .= $this->row(
                __('sales.receipt.points_redeemed', ['points' => number_format($loyaltyPoints)]),
                '-'.format_money($loyaltyValue),
                $w,
            );
        }
        if ((float) $sale->tax_total > 0) {
            $out .= $this->row(__('sales.receipt.tax_already_included'), format_money($sale->tax_total), $w);
        }
        if ((float) ($sale->additional_charges_total ?? 0) > 0) {
            $out .= $this->row(__('sales.receipt.charges'), format_money($sale->additional_charges_total), $w);
        }
        if ((float) ($sale->rounding_adjustment ?? 0) !== 0.0) {
            $out .= $this->row(__('sales.receipt.rounding'), format_money($sale->rounding_adjustment), $w);
        }

        $out .= $E::BOLD_ON;
        $out .= $this->row(__('sales.receipt.grand_total'), format_money($sale->grand_total), $w);
        $out .= $E::BOLD_OFF;

        /* ── Payments ──────────────────────────────────────────── */
        foreach ($sale->payments as $payment) {
            $label = $payment->paymentMethod?->name ?? $payment->method_code;
            $out .= $this->row($this->clean($label), format_money($payment->amount), $w);

            if ((float) ($payment->tendered_amount ?? 0) > (float) $payment->amount) {
                $out .= $this->row('  '.__('sales.receipt.tendered'), format_money($payment->tendered_amount), $w);
            }
        }
        if ((float) $sale->change_returned > 0) {
            $out .= $this->row(__('sales.receipt.change'), format_money($sale->change_returned), $w);
        }

        /* ── Loyalty stamp ─────────────────────────────────────── */
        // Points earned + the resulting balance — the payoff the programme
        // is selling. Reads the ledger rows this sale wrote; the last one
        // carries the balance (redeem is written before earn).
        if ($sale->customer_id) {
            $loyaltyRows = $sale->loyaltyLedger;
            $earned      = (int) ($loyaltyRows->firstWhere('type', \App\Models\CustomerCreditTransaction::TYPE_POINTS_EARN)?->points ?? 0);
            $after       = $loyaltyRows->last()?->balance_after_points;

            if ($earned > 0 || $loyaltyPoints > 0) {
                $out .= $this->rule($w);
                if ($earned > 0) {
                    $out .= $this->row(__('sales.receipt.points_earned'), number_format($earned), $w);
                }
                if ($after !== null) {
                    $out .= $this->row(__('sales.receipt.points_balance'), number_format((int) $after), $w);
                }
            }
        }

        /* ── HSN-wise tax summary (GST invoices) ───────────────── */
        // One block per HSN code — taxable value, tax, then the
        // per-component split (CGST / SGST / …) indented beneath. Laid
        // out label-per-line rather than as columns since thermal paper
        // is too narrow for a readable HSN | taxable | tax grid. Uses
        // the same helper as the HTML receipt so the figures match.
        if ($company->receipt_show_hsn_summary) {
            $summary = \App\Support\ReceiptHsnSummary::build($sale);
            if ($summary !== []) {
                $out .= $this->rule($w);
                $out .= $E::BOLD_ON.$this->clean(__('sales.receipt.hsn_summary'))."\n".$E::BOLD_OFF;
                foreach ($summary as $row) {
                    $out .= $this->wrap($this->clean(__('sales.receipt.hsn').' '.$row['hsn']), $w);
                    $out .= $this->row('  '.__('sales.receipt.hsn_col_taxable'), format_money($row['taxable']), $w);
                    $out .= $this->row('  '.__('sales.receipt.hsn_col_tax'), format_money($row['tax']), $w);
                    foreach ($row['components'] as $c) {
                        $out .= $this->row('    '.$this->clean($c['name']), format_money($c['amount']), $w);
                    }
                }
            }
        }

        /* ── Footer (centered) ─────────────────────────────────── */
        $out .= $this->rule($w);
        $out .= $E::ALIGN_CENTER;

        if (trim((string) $company->receipt_footer) !== '') {
            $out .= $this->clean($company->receipt_footer)."\n";
        }
        if ($company->receipt_show_barcode) {
            // Native Code 128 of the sale number (digits print below it).
            // Falls back to plain text if the value is somehow too long.
            $bc = EscPosCommands::barcode128($this->clean($sale->number));
            $out .= $bc !== '' ? $bc : ($this->clean($sale->number)."\n");
        }
        if ($company->receipt_show_qr) {
            // Native QR of the sale's no-login public-receipt URL — the
            // customer scans it for the digital receipt. Reuses the same
            // link the HTML receipt encodes (minted once, stable on reprint).
            $qr = EscPosCommands::qr($sale->publicReceiptUrl());
            if ($qr !== '') {
                $out .= "\n".$qr.$this->clean(__('sales.receipt.qr_caption'))."\n";
            }
        }
        if (trim((string) $company->receipt_return_policy) !== '') {
            $out .= "\n".$this->clean($company->receipt_return_policy)."\n";
        }

        /* ── Feed, cut, drawer ─────────────────────────────────── */
        $out .= $E::feed(3);
        if ($config->cutPaper) {
            $out .= $E::PARTIAL_CUT;
        }
        if ($config->openDrawerOnCash && $this->hasCashPayment($sale)) {
            $out .= $E::kickDrawer($config->drawerPin);
        }

        return $out;
    }

    /* ── Layout helpers ─────────────────────────────────────────── */

    /**
     * ESC/POS raster of the company logo, or '' when there's no logo or
     * GD conversion fails (the caller then prints a text header). Width is
     * capped to a modest fraction of the paper so the logo doesn't dominate.
     */
    private function logoRaster(Company $company, PrinterConfig $config): string
    {
        $path = $company->logo_path;
        if (! $path) {
            return '';
        }

        $maxWidth = $config->paperWidth === '58mm' ? 384 : 512;

        return $this->raster->encode(Storage::disk('public')->path($path), $maxWidth);
    }

    /** A full-width horizontal rule of dashes. */
    private function rule(int $width): string
    {
        return str_repeat('-', max(1, $width))."\n";
    }

    /**
     * Left + right text on one line, right-aligned to `$width`. The left
     * side is truncated if the two would collide.
     */
    private function row(string $left, string $right, int $width): string
    {
        $left  = trim($left);
        $right = trim($right);

        $maxLeft = max(0, $width - $this->displayWidth($right) - 1);
        $left    = $this->truncate($left, $maxLeft);

        $gap = $width - $this->displayWidth($left) - $this->displayWidth($right);
        $gap = max(1, $gap);

        return $left.str_repeat(' ', $gap).$right."\n";
    }

    /** Word-wrap a string to the paper width, one printed line per row. */
    private function wrap(string $text, int $width): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if ($this->displayWidth($text) <= $width) {
            return $text."\n";
        }

        return rtrim(wordwrap($text, $width, "\n", true))."\n";
    }

    private function truncate(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }

        return $this->displayWidth($text) <= $max
            ? $text
            : mb_strimwidth($text, 0, $max, '');
    }

    private function displayWidth(string $text): int
    {
        return function_exists('mb_strwidth') ? mb_strwidth($text) : strlen($text);
    }

    /** Strip control bytes / newlines from interpolated content. */
    private function clean(?string $text): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $text) ?? '');
    }

    /** @return list<string> */
    private function storeAddressLines(Sale $sale): array
    {
        $store = $sale->store;
        if (! $store) {
            return [];
        }

        $lines = array_filter([
            trim((string) ($store->address_line1 ?? '')),
            trim((string) ($store->address_line2 ?? '')),
            trim(implode(', ', array_filter([
                $store->city ?? null,
                $store->state ?? null,
                $store->postal_code ?? null,
            ]))),
            trim((string) ($store->phone ?? '')),
        ]);

        return array_values($lines);
    }

    private function hasCashPayment(Sale $sale): bool
    {
        return $sale->payments->contains(
            fn ($p) => ($p->paymentMethod?->type ?? null) === 'cash'
        );
    }
}
