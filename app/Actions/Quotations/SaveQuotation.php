<?php

namespace App\Actions\Quotations;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveQuotation
{
    public function __construct(
        private readonly GenerateQuotationNumber $generateNumber,
        private readonly BuildQuotationPricing $pricing,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Quotation
    {
        return DB::transaction(function () use ($data, $actor) {
            $store = Store::query()->where('is_active', true)->findOrFail((int) $data['store_id']);
            $priced = ($this->pricing)($data['items'], $store, $data['customer_id'] ?? null, $actor);
            [$customerSnapshot, $billing, $shipping] = $this->snapshots($data);

            $quotation = Quotation::query()->create(array_merge($this->header($data, $priced['totals'], $store), [
                'number' => ($this->generateNumber)($store),
                'revision' => 1,
                'status' => Quotation::STATUS_DRAFT,
                'customer_snapshot' => $customerSnapshot,
                'billing_address_snapshot' => $billing,
                'shipping_address_snapshot' => $shipping,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]));

            $quotation->items()->createMany($priced['items']);
            $quotation->activities()->create(['actor_id' => $actor->id, 'type' => 'created']);

            return $quotation->load('items');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Quotation $quotation, array $data, User $actor): Quotation
    {
        return DB::transaction(function () use ($quotation, $data, $actor) {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            abort_unless($quotation->isEditable(), 409, __('quotations.errors.not_editable'));
            abort_unless(
                (int) $data['store_id'] === (int) $quotation->store_id,
                409,
                __('quotations.errors.store_immutable'),
            );

            $store = Store::query()->where('is_active', true)->findOrFail((int) $data['store_id']);
            $priced = ($this->pricing)($data['items'], $store, $data['customer_id'] ?? null, $actor);
            [$customerSnapshot, $billing, $shipping] = $this->snapshots($data);

            $quotation->forceFill(array_merge($this->header($data, $priced['totals'], $store), [
                'customer_snapshot' => $customerSnapshot,
                'billing_address_snapshot' => $billing,
                'shipping_address_snapshot' => $shipping,
                'updated_by' => $actor->id,
            ]))->save();

            $quotation->items()->delete();
            $quotation->items()->createMany($priced['items']);
            $quotation->activities()->create(['actor_id' => $actor->id, 'type' => 'updated']);

            return $quotation->load('items');
        });
    }

    /** @param array<string, mixed> $data @param array<string, string> $totals */
    private function header(array $data, array $totals, Store $store): array
    {
        return array_merge($totals, [
            'store_id' => (int) $data['store_id'],
            'customer_id' => $data['customer_id'] ?? null,
            'issue_date' => $data['issue_date'],
            'valid_until' => $data['valid_until'] ?? null,
            'currency_code' => $store->currency_code ?: app_currency()['code'],
            'exchange_rate_to_base' => '1',
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'terms' => $data['terms'] ?? null,
            'notes' => $data['notes'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $data @return array{0: array<string, mixed>, 1: ?array<string, mixed>, 2: ?array<string, mixed>} */
    private function snapshots(array $data): array
    {
        $customer = filled($data['customer_id'] ?? null)
            ? Customer::query()->find((int) $data['customer_id'])
            : null;

        $customerSnapshot = $customer ? [
            'code' => $customer->code,
            'name' => $customer->name,
            'business_name' => $customer->business_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'whatsapp_phone' => $customer->whatsapp_phone,
            'whatsapp_opt_out' => (bool) $customer->whatsapp_opt_out,
            'gstin' => $customer->gstin,
            'pan' => $customer->pan,
            'tax_registration_number' => $customer->tax_registration_number,
        ] : [
            'name' => trim((string) ($data['contact_name'] ?? '')),
            'email' => filled($data['contact_email'] ?? null) ? trim((string) $data['contact_email']) : null,
            'phone' => filled($data['contact_phone'] ?? null) ? trim((string) $data['contact_phone']) : null,
        ];

        $defaultAddress = $customer?->defaultAddress();
        $billing = $this->addressSnapshot($data['billing_address'] ?? null, $defaultAddress);
        $shipping = $this->addressSnapshot($data['shipping_address'] ?? null, $defaultAddress);

        return [$customerSnapshot, $billing, $shipping];
    }

    private function addressSnapshot(mixed $formatted, ?CustomerAddress $fallback): ?array
    {
        if (filled($formatted)) {
            return ['formatted' => trim((string) $formatted)];
        }
        if (! $fallback) {
            return null;
        }

        return [
            'label' => $fallback->label,
            'line1' => $fallback->line1,
            'line2' => $fallback->line2,
            'city' => $fallback->city,
            'state' => $fallback->state,
            'postal_code' => $fallback->postal_code,
            'country_code' => $fallback->country_code,
            'landmark' => $fallback->landmark,
        ];
    }
}
