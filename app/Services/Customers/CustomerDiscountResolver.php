<?php

namespace App\Services\Customers;

use App\Models\Customer;

/**
 * Resolves the discount a customer automatically gets on every sale
 * (docs/features/customers.md §4.3).
 *
 * ## The rule
 *
 *   customer.default_discount_percent   → an explicit per-customer OVERRIDE
 *   ?? group.default_discount_percent   → otherwise inherit the tier
 *   ?? 0
 *
 * **Override, not additive.** A customer in Wholesale (15%) carrying 5%
 * of their own gets 5%, not 20%. Stacking compounds into surprises that
 * are hard to explain to a shopkeeper, and impossible to reason about
 * once a third source of discount lands.
 *
 * NULL on the customer means "inherit", which is why the column must
 * stay nullable and the customer form must not write the group's value
 * into it — copying would freeze the tier's rate at the moment of
 * editing, so changing the group later would silently do nothing.
 *
 * This class is the ONLY place that rule lives. The cashier, the
 * back-office sale screen, the kiosk, and CompleteSale's governance
 * exemption all call it, so they cannot drift apart.
 *
 * Hooks:
 *   - filter `customer.default_discount` → ($percent, $customer)
 */
class CustomerDiscountResolver
{
    public const SOURCE_NONE     = 'none';
    public const SOURCE_CUSTOMER = 'customer';
    public const SOURCE_GROUP    = 'group';

    /**
     * Effective percent as a bcmath-safe decimal-string ('0' when none).
     */
    public function percentFor(?Customer $customer): string
    {
        return $this->resolve($customer)['percent'];
    }

    /**
     * The percent plus where it came from — the UI labels the discount
     * with its tier ("Wholesale 15%"), which needs the source.
     *
     * @return array{percent: string, source: string, group_name: ?string}
     */
    public function resolve(?Customer $customer): array
    {
        $none = ['percent' => '0', 'source' => self::SOURCE_NONE, 'group_name' => null];

        if (! $customer) {
            return $none;
        }

        $customer->loadMissing('group');
        $groupName = $customer->group?->name;

        // An explicit value on the customer wins — including an explicit
        // 0, which is how you say "this customer gets no discount even
        // though their tier does". NULL means "inherit".
        $own = $customer->default_discount_percent;

        if ($own !== null && $own !== '') {
            return [
                'percent'    => $this->clamp((string) $own, $customer),
                'source'     => self::SOURCE_CUSTOMER,
                'group_name' => $groupName,
            ];
        }

        $groupPercent = $customer->group?->default_discount_percent;

        if ($groupPercent !== null && $groupPercent !== '') {
            return [
                'percent'    => $this->clamp((string) $groupPercent, $customer),
                'source'     => self::SOURCE_GROUP,
                'group_name' => $groupName,
            ];
        }

        return [
            'percent'    => $this->clamp('0', $customer),
            'source'     => self::SOURCE_NONE,
            'group_name' => $groupName,
        ];
    }

    /**
     * Same rule, but from an id — for the paths that only carry a
     * customer_id and shouldn't hydrate a model just to read one number.
     */
    public function percentForId(?int $customerId): string
    {
        if (! $customerId) {
            return '0';
        }

        $customer = Customer::query()->with('group:id,name,default_discount_percent')->find($customerId);

        return $this->percentFor($customer);
    }

    /** Keep a stray stored value inside 0-100 before it reaches the money math. */
    private function clamp(string $percent, ?Customer $customer = null): string
    {
        $percent = (string) apply_filters('customer.default_discount', $percent, $customer);

        if ($percent === '' || bccomp($percent, '0', 4) < 0) {
            return '0';
        }
        if (bccomp($percent, '100', 4) > 0) {
            return '100';
        }

        return $percent;
    }
}
