<?php

namespace App\Actions\Hardware;

use App\Models\Company;
use App\Models\Sale;
use App\Models\Terminal;
use App\Services\Hardware\EscPosFormatter;
use App\Services\Hardware\PrinterConfig;
use Illuminate\Support\Facades\View;

/**
 * Builds the dual-format print payload for a sale receipt
 * (docs/features/hardware.md §5.1): HTML for the browser-print path and
 * an ESC/POS byte stream (base64) for the WebUSB path. The client print
 * bridge picks which one to use based on the terminal's capabilities;
 * the server hands it both so either path is one call away.
 *
 * Extension points:
 *   - filter `print.html`  → ($html, $sale)            augment browser-print HTML
 *   - filter `print.bytes` → ($bytes, $sale, $config)  augment ESC/POS bytes
 */
class PreparePrintPayload
{
    public function __construct(private EscPosFormatter $formatter) {}

    /**
     * @return array{mode:string, paper:string, html:string, escpos_bytes:string}
     */
    public function __invoke(Sale $sale, ?Terminal $terminal = null): array
    {
        $this->loadReceiptRelations($sale);

        $company  = Company::current() ?? new Company();
        $fallback = $company->receipt_paper_size ?: '80mm';
        $config   = PrinterConfig::fromTerminal($terminal, $fallback);

        $html = View::make('sales.receipt', [
            'sale'    => $sale,
            'company' => $company,
            'paper'   => $config->paperWidth,
            'auto'    => false,
            // QR of the public-receipt URL, matching the ESC/POS half. The
            // formatter reuses the same link (minted once), so both formats
            // print the same code. Null when the QR toggle is off.
            'receiptUrl' => $company->receipt_show_qr ? $sale->publicReceiptUrl() : null,
        ])->render();
        $html = apply_filters('print.html', $html, $sale);

        $bytes = $this->formatter->format($sale, $config, $company);
        $bytes = apply_filters('print.bytes', $bytes, $sale, $config);

        return [
            'mode'         => $config->mode,
            'paper'        => $config->paperWidth,
            'html'         => $html,
            'escpos_bytes' => base64_encode($bytes),
        ];
    }

    /**
     * Eager-load exactly what the receipt blade + ESC/POS formatter read.
     * Mirrors SaleController::receipt() so both renderers see the same
     * data. Safe to call repeatedly — Eloquent skips already-loaded
     * relations.
     */
    private function loadReceiptRelations(Sale $sale): void
    {
        $sale->loadMissing([
            'store:id,name,code,address_line1,address_line2,city,state,postal_code,phone',
            'customer:id,name,phone,email',
            'cashier:id,name',
            'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'items.product:id,sku,name,hsn_code,unit_id,type',
            'items.product.unit:id,code',
            'items.product.kitItems.component:id,sku,name,unit_id',
            'items.product.kitItems.component.unit:id,code',
            'items.product.kitItems.variant:id,sku,attributes',
            'items.variant:id,sku,attributes',
            'items.batch:id,batch_number,expiry_date',
            'payments.paymentMethod:id,name,type',
        ]);
    }
}
