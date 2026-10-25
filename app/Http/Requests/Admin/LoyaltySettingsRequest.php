<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /admin/settings/loyalty.
 *
 * Shape mirrors {@see \App\Models\Company::loyalty()}. Adding a knob:
 * extend the rules + the `loyaltyData()` exporter, then bump the default
 * in the model accessor and add the field to the form.
 */
class LoyaltySettingsRequest extends FormRequest
{
    public const EARN_BASES = ['grand_total', 'subtotal'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled'            => ['sometimes', 'boolean'],
            'earn_points'        => ['required', 'integer', 'min:0', 'max:1000000'],
            // Guarded against 0 — it's the divisor in the earn rate.
            'earn_per_amount'    => ['required', 'numeric', 'gt:0'],
            'earn_on'            => ['required', Rule::in(self::EARN_BASES)],
            'redeem_points'      => ['required', 'integer', 'min:1', 'max:1000000'],
            'redeem_value'       => ['required', 'numeric', 'min:0'],
            'min_redeem_points'  => ['required', 'integer', 'min:0', 'max:1000000'],
            'max_redeem_percent' => ['required', 'numeric', 'between:0,100'],
            'expiry_months'      => ['required', 'integer', 'min:0', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'earn_points'        => __('settings.loyalty.fields.earn_points'),
            'earn_per_amount'    => __('settings.loyalty.fields.earn_per_amount'),
            'earn_on'            => __('settings.loyalty.fields.earn_on'),
            'redeem_points'      => __('settings.loyalty.fields.redeem_points'),
            'redeem_value'       => __('settings.loyalty.fields.redeem_value'),
            'min_redeem_points'  => __('settings.loyalty.fields.min_redeem_points'),
            'max_redeem_percent' => __('settings.loyalty.fields.max_redeem_percent'),
            'expiry_months'      => __('settings.loyalty.fields.expiry_months'),
        ];
    }

    /**
     * The exact shape we persist into `company.loyalty_settings`. Money
     * values are stored as strings so the bcmath paths never see a float.
     *
     * @return array<string, mixed>
     */
    public function loyaltyData(): array
    {
        return [
            'enabled'            => $this->boolean('enabled'),
            'earn_points'        => (int) $this->input('earn_points', 1),
            'earn_per_amount'    => (string) $this->input('earn_per_amount', '10'),
            'earn_on'            => $this->input('earn_on') === 'subtotal' ? 'subtotal' : 'grand_total',
            'redeem_points'      => (int) $this->input('redeem_points', 100),
            'redeem_value'       => (string) $this->input('redeem_value', '10'),
            'min_redeem_points'  => (int) $this->input('min_redeem_points', 0),
            'max_redeem_percent' => (string) $this->input('max_redeem_percent', '100'),
            'expiry_months'      => (int) $this->input('expiry_months', 0),
        ];
    }
}
