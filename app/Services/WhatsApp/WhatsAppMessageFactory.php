<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchasePayment;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\Store;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessageTemplate;
use App\Services\WhatsApp\Data\PreparedMessage;
use App\Support\PhoneNormalizer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class WhatsAppMessageFactory
{
    public function make(Model $reference, ?string $phone = null, ?WhatsAppConnection $connection = null, array $context = []): PreparedMessage
    {
        [$type, $store, $customer, $variables, $parameters] = $this->context($reference, $context);
        if ($customer?->whatsapp_opt_out) {
            throw new InvalidArgumentException(__('whatsapp.errors.opted_out'));
        }

        $rawPhone = $phone ?: $customer?->whatsapp_phone ?: $customer?->phone;
        $e164 = PhoneNormalizer::toE164($rawPhone, $store->country_code ?: Company::current()?->country_code);
        if ($e164 === null) {
            throw new InvalidArgumentException($rawPhone ? __('whatsapp.errors.invalid_phone') : __('whatsapp.errors.no_phone'));
        }

        $locale = (string) ($store->locale ?: app()->getLocale() ?: 'en');
        $body = $this->templateBody($type, (int) $store->id, $locale);
        foreach ($variables as $key => $value) {
            $body = str_replace('{{ '.$key.' }}', (string) $value, $body);
        }
        $body = (string) apply_filters('whatsapp.message.body', $body, $type, $reference, $variables);

        $templateName = $connection
            ? trim((string) $connection->setting("templates.{$type}.name", '')) ?: null
            : null;
        $templateLocale = $connection
            ? trim((string) $connection->setting("templates.{$type}.language", $locale)) ?: $locale
            : $locale;

        return new PreparedMessage($reference, $store, $type, $e164, trim($body), $templateLocale, $templateName, $parameters);
    }

    private function context(Model $reference, array $context): array
    {
        if ($reference instanceof Sale) {
            $reference->loadMissing(['store', 'customer']);
            $store = $reference->store;
            $customer = $reference->customer;

            return ['sale_receipt', $store, $customer, [
                'customer.name' => $customer?->name ?: __('whatsapp.values.customer'),
                'store.name' => $store->name,
                'sale.number' => $reference->number,
                'sale.grand_total' => format_money($reference->grand_total),
                'sale.sale_date' => format_date($reference->sale_date),
                'public_url' => $reference->publicReceiptUrl(),
            ], [$customer?->name ?: __('whatsapp.values.customer'), $store->name, $reference->number, format_money($reference->grand_total), $reference->publicReceiptUrl()]];
        }

        if ($reference instanceof Quotation) {
            $reference->loadMissing(['store', 'customer']);
            $customer = $reference->customer;
            $snapshot = $reference->customer_snapshot ?? [];

            return ['quotation', $reference->store, $customer, [
                'customer.name' => $customer?->name ?: ($snapshot['name'] ?? __('whatsapp.values.customer')),
                'store.name' => $reference->store->name,
                'quotation.number' => $reference->displayNumber(),
                'quotation.grand_total' => format_money($reference->grand_total),
                'public_url' => $reference->publicUrl(),
            ], [$customer?->name ?: ($snapshot['name'] ?? __('whatsapp.values.customer')), $reference->store->name, $reference->displayNumber(), format_money($reference->grand_total), $reference->publicUrl()]];
        }

        if ($reference instanceof SaleReturn) {
            $reference->loadMissing(['store', 'sale.customer']);
            $customer = $reference->sale?->customer;

            return ['credit_note', $reference->store, $customer, [
                'customer.name' => $customer?->name ?: __('whatsapp.values.customer'),
                'store.name' => $reference->store->name,
                'return.number' => $reference->number,
                'return.grand_total' => format_money($reference->grand_total),
            ], [$customer?->name ?: __('whatsapp.values.customer'), $reference->store->name, $reference->number, format_money($reference->grand_total)]];
        }

        if ($reference instanceof SalePayment) {
            $reference->loadMissing(['sale.store', 'customer']);
            $customer = $reference->customer ?: $reference->sale?->customer;
            $store = $reference->sale?->store ?: Store::query()->findOrFail((int) ($context['store_id'] ?? current_store_id()));

            return ['customer_payment', $store, $customer, [
                'customer.name' => $customer?->name ?: __('whatsapp.values.customer'),
                'store.name' => $store->name,
                'payment.number' => '#'.$reference->id,
                'payment.amount' => format_money($reference->amount, null, $reference->currency_code),
                'payment.date' => format_date($reference->paid_at),
                'customer.outstanding_balance' => format_money($customer?->outstanding_balance ?? '0'),
            ], [$customer?->name ?: __('whatsapp.values.customer'), $store->name, '#'.$reference->id, format_money($reference->amount, null, $reference->currency_code), format_money($customer?->outstanding_balance ?? '0')]];
        }

        if ($reference instanceof PurchasePayment) {
            $reference->loadMissing(['store', 'supplier']);
            $supplier = $reference->supplier;

            return ['supplier_payment', $reference->store, null, [
                'supplier.name' => $supplier?->contact_person ?: $supplier?->name ?: __('whatsapp.values.supplier'),
                'store.name' => $reference->store->name,
                'payment.number' => '#'.$reference->id,
                'payment.amount' => format_money($reference->amount, null, $reference->currency_code),
                'payment.date' => format_date($reference->payment_date),
                'payment.reference' => $reference->reference ?: '—',
            ], [$supplier?->contact_person ?: $supplier?->name ?: __('whatsapp.values.supplier'), $reference->store->name, '#'.$reference->id, format_money($reference->amount, null, $reference->currency_code), $reference->reference ?: '—']];
        }

        if ($reference instanceof Customer) {
            $storeId = (int) ($context['store_id'] ?? current_store_id());
            $store = Store::query()->findOrFail($storeId);
            $from = $context['from'] ?? now()->subMonths(3);
            $to = $context['to'] ?? now();
            $period = $this->period($from, $to);

            return ['customer_statement', $store, $reference, [
                'customer.name' => $reference->name,
                'store.name' => $store->name,
                'customer.outstanding_balance' => format_money($reference->outstanding_balance),
                'period_label' => $period,
            ], [$reference->name, $store->name, $period, format_money($reference->outstanding_balance)]];
        }

        throw new InvalidArgumentException(__('whatsapp.errors.unsupported_document'));
    }

    private function templateBody(string $type, int $storeId, string $locale): string
    {
        $custom = WhatsAppMessageTemplate::query()
            ->where('document_type', $type)
            ->whereIn('locale', [$locale, 'en'])
            ->where(fn ($query) => $query->where('store_id', $storeId)->orWhereNull('store_id'))
            ->orderByRaw('CASE WHEN store_id = ? THEN 0 ELSE 1 END', [$storeId])
            ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$locale])
            ->latest('id')->value('body');

        return $custom ?: (string) __("whatsapp.templates.{$type}");
    }

    private function period(mixed $from, mixed $to): string
    {
        $format = fn (mixed $value) => $value instanceof CarbonInterface ? format_date($value) : format_date((string) $value);

        return $format($from).' - '.$format($to);
    }
}
