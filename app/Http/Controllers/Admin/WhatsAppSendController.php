<?php

namespace App\Http\Controllers\Admin;

use App\Actions\WhatsApp\SendWhatsAppDocument;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PurchasePayment;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\WhatsAppConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class WhatsAppSendController extends Controller
{
    public function sale(Request $request, Sale $sale, SendWhatsAppDocument $send): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $sale);
        $this->authorize('whatsapp.send_receipt');

        return $this->send($request, $sale, $send);
    }

    public function quotation(Request $request, Quotation $quotation, SendWhatsAppDocument $send): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $quotation);
        $this->authorize('whatsapp.send_receipt');

        return $this->send($request, $quotation, $send);
    }

    public function saleReturn(Request $request, SaleReturn $saleReturn, SendWhatsAppDocument $send): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $saleReturn->sale);
        $this->authorize('whatsapp.send_receipt');

        return $this->send($request, $saleReturn, $send);
    }

    public function statement(Request $request, Customer $customer, SendWhatsAppDocument $send): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $customer);
        $this->authorize('whatsapp.send_statement');
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'phone' => ['nullable', 'string', 'max:32'], 'force_click' => ['sometimes', 'boolean']]);

        return $this->send($request, $customer, $send, [
            'store_id' => current_store_id(),
            'from' => ! empty($data['from']) ? CarbonImmutable::parse($data['from']) : now()->subMonths(3),
            'to' => ! empty($data['to']) ? CarbonImmutable::parse($data['to']) : now(),
        ]);
    }

    public function customerPayment(Request $request, SalePayment $customerPayment, SendWhatsAppDocument $send): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $customerPayment);
        $this->authorize('whatsapp.send_receipt');

        return $this->send($request, $customerPayment, $send, ['store_id' => current_store_id()]);
    }

    public function supplierPayment(Request $request, PurchasePayment $supplierPayment, SendWhatsAppDocument $send): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $supplierPayment);
        $this->authorize('whatsapp.send_supplier_voucher');

        return $this->send($request, $supplierPayment, $send, ['store_id' => $supplierPayment->store_id]);
    }

    public function bulkStatements(Request $request, SendWhatsAppDocument $send): JsonResponse|RedirectResponse
    {
        $this->authorize('whatsapp.send_bulk');
        $data = $request->validate(['minimum_outstanding' => ['required', 'numeric', 'min:0'], 'limit' => ['nullable', 'integer', 'between:1,500']]);
        $storeId = (int) current_store_id();
        $connection = WhatsAppConnection::forStore($storeId);
        if (! $connection || $connection->setting('mode') !== 'automatic') {
            $message = __('whatsapp.errors.bulk_requires_automatic');

            return $request->expectsJson() ? response()->json(['message' => $message], 422) : back()->with('error', $message);
        }
        if (! $connection->setting('templates.customer_statement.name')) {
            $message = __('whatsapp.errors.bulk_requires_template');

            return $request->expectsJson() ? response()->json(['message' => $message], 422) : back()->with('error', $message);
        }

        $customers = Customer::query()->where('is_active', true)->where('whatsapp_opt_out', false)
            ->whereRaw('outstanding_balance > ?', [(string) $data['minimum_outstanding']])
            ->where(fn ($q) => $q->whereNotNull('whatsapp_phone')->orWhereNotNull('phone'))
            ->orderByDesc('outstanding_balance')->limit((int) ($data['limit'] ?? 500))->get();
        $queued = 0;
        foreach ($customers as $customer) {
            try {
                $send->execute($customer, null, $request->user(), false, ['store_id' => $storeId, 'from' => now()->subMonths(3), 'to' => now()]);
                $queued++;
            } catch (InvalidArgumentException) {
                // One malformed number must not prevent all valid recipients.
            }
        }
        $message = __('whatsapp.flash.bulk_queued', ['count' => $queued]);

        return $request->expectsJson() ? response()->json(['message' => $message, 'data' => ['queued' => $queued]]) : back()->with('success', $message);
    }

    private function send(Request $request, Model $reference, SendWhatsAppDocument $send, array $context = []): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:32'], 'force_click' => ['sometimes', 'boolean']]);
        try {
            $result = $send->execute($reference, $data['phone'] ?? null, $request->user(), $request->boolean('force_click'), $context);
        } catch (InvalidArgumentException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage(), 'errors' => ['phone' => [$e->getMessage()]]], 422)
                : back()->withInput()->with('error', $e->getMessage());
        }

        $message = $result['click_url'] ? __('whatsapp.flash.prepared') : __('whatsapp.flash.queued');
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'data' => ['log_id' => $result['log']->id, 'click_url' => $result['click_url']]]);
        }

        return $result['click_url'] ? redirect()->away($result['click_url']) : back()->with('success', $message);
    }
}
