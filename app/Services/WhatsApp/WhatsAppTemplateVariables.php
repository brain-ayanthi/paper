<?php

namespace App\Services\WhatsApp;

final class WhatsAppTemplateVariables
{
    /** @return array<string, list<string>> */
    public static function all(): array
    {
        return [
            'sale_receipt' => ['customer.name', 'store.name', 'sale.number', 'sale.grand_total', 'sale.sale_date', 'public_url'],
            'quotation' => ['customer.name', 'store.name', 'quotation.number', 'quotation.grand_total', 'public_url'],
            'credit_note' => ['customer.name', 'store.name', 'return.number', 'return.grand_total'],
            'customer_statement' => ['customer.name', 'store.name', 'customer.outstanding_balance', 'period_label'],
            'customer_payment' => ['customer.name', 'store.name', 'payment.number', 'payment.amount', 'payment.date', 'customer.outstanding_balance'],
            'supplier_payment' => ['supplier.name', 'store.name', 'payment.number', 'payment.amount', 'payment.date', 'payment.reference'],
        ];
    }
}
