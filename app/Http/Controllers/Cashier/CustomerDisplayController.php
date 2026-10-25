<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\PosPaymentSession;
use App\Models\Sale;
use App\Models\Terminal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Customer-Facing Display (CFD) — Slice 1.
 *
 * Renders the second screen the shopper looks at while the cashier rings
 * up. It is display-only: it holds no cart of its own. The live cart is
 * pushed from the cashier window over a same-machine BroadcastChannel
 * (see resources/js/cashier/cfd-broadcast.js); this page just paints the
 * last snapshot it received. Idle attract copy + store branding come from
 * this server bootstrap so the idle screen has something to show before any
 * sale starts. See docs/features/customer-display.md §5 (Tier 1).
 */
class CustomerDisplayController extends Controller
{
    /** Upper bound on a relayed snapshot (SEC #48). A 200-line cart with kits
     *  is a few tens of KB; 256 KB leaves generous headroom. */
    private const MAX_SNAPSHOT_BYTES = 262144;

    public function show(): View
    {
        // Only staff who can ring up a sale may open the customer display —
        // it's launched from an authenticated cashier session and shows
        // customer-safe data only (never cost/margin).
        $this->authorize('create', Sale::class);

        $store    = current_store();
        $company  = Company::current();
        $terminal = current_terminal();
        $cfd      = $terminal?->cfd_config ?? [];

        // Same channel string the cashier computes — scoped per terminal so
        // two POS on one machine can't cross-talk; 'default' when unbound.
        $channel = 'pos-cfd:'.(current_terminal_id() ?: 'default');

        // Cashier-specific theme default (falls back to the company default),
        // mirroring how <x-cashier-layout> resolves it — so the display opens
        // in the same look as the till it's paired with.
        $cashierTheme = $company?->cashier()['theme_default'] ?? 'auto';
        $themeDefault = $cashierTheme !== 'auto'
            ? $cashierTheme
            : ($company?->theme_default ?: 'light');

        $currency = app_currency();

        // Attract-media slideshow (Slice 3) — resolve the stored image paths
        // to URLs for the idle screen. Empty → the idle screen just shows the
        // logo + welcome.
        $attractMedia = collect($cfd['attract_media'] ?? [])
            ->filter(fn ($p) => is_string($p) && $p !== '')
            ->map(fn ($p) => \Illuminate\Support\Facades\Storage::disk('public')->url($p))
            ->values()
            ->all();

        $bootstrap = [
            'channel'  => $channel,
            // Transport (Slice 4): 'same_machine' → BroadcastChannel;
            // 'separate_device' → poll the per-terminal state endpoint.
            'transport' => $cfd['transport'] ?? 'same_machine',
            'poll_url'  => route('cashier.display.state', ['terminal' => current_terminal_id() ?: 0]),
            'currency' => [
                'symbol'       => $currency['symbol'] ?? '',
                'symbol_first' => (bool) ($currency['symbol_first'] ?? true),
            ],
            'store'    => [
                'name' => $store?->name ?? (string) config('app.name'),
            ],
            'attract'  => [
                'media'   => $attractMedia,
                'seconds' => (int) ($cfd['attract_seconds'] ?? 8),
            ],
            // Idle-screen copy: terminal override → translated default.
            'welcome'  => ($cfd['welcome_text'] ?? null)
                ?: __('customer-display.idle.welcome', ['store' => $store?->name ?? config('app.name')]),
            'tagline'  => __('customer-display.idle.tagline'),
            'thankyou' => ($cfd['thankyou_text'] ?? null) ?: __('customer-display.thankyou.title'),
            // Mirror the cashier's UPI QR onto the payment screen when this
            // terminal opts in — the shopper then scans it from their own
            // display instead of leaning over the till.
            'show_upi_qr' => (bool) ($cfd['show_upi_qr'] ?? false),
            'upi_url' => route('cashier.display.upi'),
            // Pre-translated labels so the display honours locale + RTL
            // without an __() equivalent in JS.
            'labels'   => [
                'your_order'  => __('customer-display.sale.your_order'),
                'item'        => __('customer-display.sale.item'),
                'items'       => __('customer-display.sale.items'),
                'col_item'    => __('customer-display.sale.col_item'),
                'col_qty'     => __('customer-display.sale.col_qty'),
                'col_amount'  => __('customer-display.sale.col_amount'),
                'subtotal'    => __('customer-display.sale.subtotal'),
                'discount'    => __('customer-display.sale.discount'),
                'tax'         => __('customer-display.sale.tax'),
                'total'       => __('customer-display.sale.total'),
                'added'       => __('customer-display.sale.added'),
                'scanning'    => __('customer-display.sale.scanning'),
                'loyalty'     => __('customer-display.sale.loyalty'),
                'points'      => __('customer-display.sale.points'),
                'each'        => __('customer-display.sale.each'),
                // Payment + thank-you (Slice 2).
                'amount_due'  => __('customer-display.payment.amount_due'),
                'tendered'    => __('customer-display.payment.tendered'),
                'change_due'  => __('customer-display.payment.change_due'),
                'scan_to_pay' => __('customer-display.payment.scan_to_pay'),
                'scan_hint'   => __('customer-display.payment.scan_hint'),
                'scan_upi'      => __('customer-display.payment.scan_upi'),
                'scan_upi_hint' => __('customer-display.payment.scan_upi_hint'),
                'change'      => __('customer-display.thankyou.change'),
                'receipt_scan' => __('customer-display.thankyou.receipt_scan'),
                'receipt_hint' => __('customer-display.thankyou.receipt_hint'),
            ],
        ];

        return view('cashier.display', [
            'bootstrap'      => $bootstrap,
            'appName'        => $company?->display_app_name ?: (string) config('app.name'),
            'appLogoUrl'     => $company?->app_logo_url,
            'appLogoDarkUrl' => $company?->app_logo_dark_url,
            'brandColor'     => $company?->brand_color,
            'brandTextColor' => $company?->brand_text_color,
            'faviconUrl'     => $company?->favicon_url,
            'themeDefault'   => $themeDefault,
            'storeName'      => $store?->name ?? (string) config('app.name'),
        ]);
    }

    /**
     * Tier 2 relay — the cashier posts its latest snapshot; we cache it per
     * terminal. A separate tablet reads it back via {@see state()}. The
     * snapshot is the cashier's own customer-safe data (names/prices only),
     * so we just bound its shape and stash the whole thing. Debounced on the
     * client, so this is hit at most ~1–2×/sec per till.
     */
    public function push(Request $request): JsonResponse
    {
        $this->authorize('create', Sale::class);

        $data = $request->validate([
            'snapshot' => ['required', 'array'],
        ]);

        $terminalId = current_terminal_id() ?: 0;

        // An unbound station has no terminal, and every one of them used to
        // share the key `cfd:state:0` — so two tills with no terminal selected
        // wrote over each other, and a display in one store could show a cart
        // from another (SEC #47). There is no correct shared bucket here:
        // without a terminal there is nothing to scope the snapshot to, so
        // refuse rather than guess.
        if ($terminalId <= 0) {
            return response()->json(['message' => __('cashier.display.no_terminal')], 422);
        }

        // Bound (SEC #48). The relay stores whatever it is handed for five
        // minutes, so an oversized payload is free cache bloat, and a runaway
        // client could push megabytes per second.
        if (strlen((string) json_encode($data['snapshot'])) > self::MAX_SNAPSHOT_BYTES) {
            return response()->json(['message' => __('cashier.display.snapshot_too_large')], 422);
        }

        $snapshot = $this->pinUpiPayee($data['snapshot'], $terminalId);

        Cache::put($this->stateKey($terminalId), $snapshot, now()->addMinutes(5));

        return response()->json(['ok' => true]);
    }

    /**
     * Rebuild the UPI deep link from server-held data — security register
     * SEC #78 (with #79 as its opt-in half).
     *
     * The whole `upi://pay?pa=<VPA>&am=<amount>` string was assembled in the
     * cashier's browser and relayed to the customer display untouched, with
     * the push endpoint validating only `required|array`. Static UPI is
     * claim-only — the cashier confirms receipt by eye — so a tampered
     * snapshot carrying `pa=attacker@bank` had the shopper pay a third party
     * while the sale still recorded as paid. Unlike the gateway `pay_url`
     * (a server-minted session bound to a real amount and merchant account),
     * this link had no server binding at all.
     *
     * The payee is now taken ONLY from the terminal's configured UPI method,
     * read server-side; a client-supplied `pa` can never survive. The amount
     * is carried over from the snapshot but forced to a sane positive number,
     * and the link is dropped entirely when the terminal has no UPI method
     * configured or the opt-in is off (which is SEC #79 — the flag was
     * previously honoured only by the display, so the VPA was broadcast and
     * cached even when switched off).
     *
     * The AMOUNT is now pinned too (the second half of #78). The cashier mints
     * a server-priced intent — a {@see PosPaymentSession} whose total came from
     * `PriceCart`, the same action `CompleteSale` uses — and relays only its
     * uuid. We look the amount up from that row. A snapshot that carries no
     * intent, or one naming a session belonging to another store or already
     * finished, mirrors no QR at all: showing the shopper a number we cannot
     * stand behind is the thing this row is about.
     *
     * Residual, and unavoidable: a till that is OFFLINE cannot mint an intent,
     * so it falls back to its own locally-built QR on the cashier screen and
     * the display mirrors nothing. There is no server to pin against in that
     * state by definition.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function pinUpiPayee(array $snapshot, int $terminalId): array
    {
        if (! isset($snapshot['payment']) || ! is_array($snapshot['payment'])) {
            return $snapshot;
        }

        // Never trust what arrived — both the link and any amount beside it.
        $intentUuid = $snapshot['payment']['upi_intent'] ?? null;
        unset($snapshot['payment']['upi_pay_url']);

        $terminal = $terminalId > 0 ? Terminal::query()->find($terminalId) : null;

        if (! $terminal) {
            return $snapshot;
        }

        $cfd = (array) ($terminal->cfd_config ?? []);

        if (! ($cfd['show_upi_qr'] ?? false)) {
            return $snapshot;   // opt-in off → nothing to mirror (SEC #79)
        }

        if (! is_string($intentUuid) || $intentUuid === '') {
            return $snapshot;   // no server-minted amount → no QR
        }

        $intent = PosPaymentSession::query()
            ->where('uuid', $intentUuid)
            ->where('store_id', $terminal->store_id)
            ->first();

        // Live means "still awaiting payment". A paid, cancelled or expired
        // intent must not keep painting a scannable QR on a public screen.
        if (! $intent || ! $intent->isLive() || $intent->payment_method_id === null) {
            return $snapshot;
        }

        $method = PaymentMethod::query()->find((int) $intent->payment_method_id);
        $vpa    = $method?->upiVpa();

        // The terminal's configured method still wins as the payee. The intent
        // says how much; the terminal says who gets it — neither comes from
        // the relayed snapshot.
        $configuredId = $cfd['upi_method_id'] ?? ($terminal->kiosk_config['upi_method_id'] ?? null);
        if ($configuredId && (int) $configuredId !== (int) $intent->payment_method_id) {
            return $snapshot;
        }

        if (! $vpa || bccomp((string) $intent->amount, '0', 4) <= 0) {
            return $snapshot;
        }

        $snapshot['payment']['upi_pay_url'] = PaymentSessionController::buildUpiUrl(
            $vpa,
            $method,
            (string) $intent->amount,
            (string) ($intent->currency ?: Company::current()?->base_currency_code ?: 'INR'),
            $intent->uuid,
        );

        return $snapshot;
    }

    /** Resolve a same-machine display's intent using the same guards as the relay. */
    public function upi(Request $request): JsonResponse
    {
        $this->authorize('create', Sale::class);
        $data = $request->validate(['intent' => ['required', 'string', 'max:64']]);
        $terminal = current_terminal();
        abort_unless_store_access($terminal?->store_id);

        $snapshot = $this->pinUpiPayee([
            'payment' => ['upi_intent' => $data['intent']],
        ], (int) ($terminal?->id ?? 0));

        return response()->json([
            'upi_pay_url' => $snapshot['payment']['upi_pay_url'] ?? null,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Return the last snapshot pushed for a terminal (or an empty object).
     * Polled ~every 1.5s by a separate-device display. Auth-gated the same
     * way the display page is — the tablet runs in a signed-in session.
     *
     * The terminal id comes straight off the URL, and `create` is a
     * class-level ability, so this used to return ANY terminal's live cart —
     * customer name, lines, totals — to any signed-in cashier, across stores
     * (SEC #45/#96). Scope it to the terminal's own store.
     */
    public function state(int $terminal): JsonResponse
    {
        $this->authorize('create', Sale::class);

        $storeId = Terminal::query()->whereKey($terminal)->value('store_id');
        abort_unless_store_access($storeId !== null ? (int) $storeId : null);

        return response()->json(Cache::get($this->stateKey($terminal)) ?: (object) []);
    }

    private function stateKey(int $terminalId): string
    {
        return "cfd:state:{$terminalId}";
    }
}
