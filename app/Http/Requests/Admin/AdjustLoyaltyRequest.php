<?php

namespace App\Http\Requests\Admin;

use App\Actions\Customers\AdjustLoyaltyPoints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /admin/customers/{customer}/loyalty.
 *
 * `points` is always positive — direction carries the sign, so no input
 * ever has to accept a `-` (system-wide negative-numbers convention).
 * The reason is required: an unexplained balance change is the thing an
 * owner will later want to audit.
 */
class AdjustLoyaltyRequest extends FormRequest
{
    public const DIRECTIONS = [
        AdjustLoyaltyPoints::DIRECTION_ADD,
        AdjustLoyaltyPoints::DIRECTION_REMOVE,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(self::DIRECTIONS)],
            'points'    => ['required', 'integer', 'min:1', 'max:10000000'],
            'reason'    => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'points' => __('customers.loyalty.fields.points'),
            'reason' => __('customers.loyalty.fields.reason'),
        ];
    }
}
