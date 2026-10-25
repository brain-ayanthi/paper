<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Sale;

/**
 * The loyalty program's arithmetic — the single place that turns money
 * into points and points back into money.
 *
 * Kept as a Service (not an Action) because several Actions consume it:
 * EarnLoyaltyPoints, ReverseLoyaltyPoints, and — next slice — the
 * cashier's redemption path. All money is bcmath decimal-strings; points
 * are whole numbers, always rounded DOWN so the program can never hand
 * out a point it didn't fully earn.
 *
 * Config lives on `company.loyalty_settings` ({@see Company::loyalty()}).
 *
 * Hooks:
 *   - filter `loyalty.earn_rate`   → ($pointsPerAmountRatio, $customer, $sale)
 *   - filter `loyalty.redeem_rate` → ($moneyPerPointRatio, $customer)
 *
 * See `docs/features/customers.md` §9.
 */
class LoyaltyCalculator
{
    /** Working scale for intermediate ratio math, rounded down at the end. */
    private const SCALE = 8;

    private ?array $config = null;

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config ??= (Company::current() ?? new Company())->loyalty();
    }

    /** Forget the memoised config — for tests and after a settings save. */
    public function flush(): void
    {
        $this->config = null;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config()['enabled'];
    }

    /**
     * Points a completed sale earns for its customer.
     *
     * Returns 0 for walk-in sales, a disabled program, a zero/absent earn
     * rate, or a tier whose multiplier is 0 (the "no loyalty earn"
     * wholesale case).
     */
    public function pointsForSale(Sale $sale, ?Customer $customer): int
    {
        if (! $this->isEnabled() || ! $customer) {
            return 0;
        }

        $cfg = $this->config();

        // What the points are earned on. `subtotal` is net of discount and
        // pre-tax — stores that don't want to pay points on the tax they
        // merely collect pick this.
        $base = $cfg['earn_on'] === 'subtotal'
            ? (string) $sale->subtotal
            : (string) $sale->grand_total;

        return $this->pointsForAmount($base, $customer, $sale);
    }

    /**
     * Points earned on an arbitrary money amount — the shared core, also
     * used for proportional clawbacks on partial refunds.
     */
    public function pointsForAmount(string $amount, ?Customer $customer, ?Sale $sale = null): int
    {
        if (! $this->isEnabled() || bccomp($amount, '0', 4) <= 0) {
            return 0;
        }

        $cfg = $this->config();

        $perAmount = (string) $cfg['earn_per_amount'];
        if ($cfg['earn_points'] <= 0 || bccomp($perAmount, '0', 4) <= 0) {
            return 0;
        }

        // Points per one currency unit, before the tier multiplier.
        $rate = bcdiv((string) $cfg['earn_points'], $perAmount, self::SCALE);

        $rate = (string) apply_filters('loyalty.earn_rate', $rate, $customer, $sale);
        if (bccomp($rate, '0', self::SCALE) <= 0) {
            return 0;
        }

        $multiplier = $customer?->group?->earnMultiplier() ?? '1';
        if (bccomp($multiplier, '0', 4) <= 0) {
            return 0;
        }

        $points = bcmul(bcmul($amount, $rate, self::SCALE), $multiplier, self::SCALE);

        // Floor — a part-earned point is not a point.
        return (int) bcadd($points, '0', 0);
    }

    /**
     * Money value of a whole number of points, as a 4dp decimal-string.
     * Used by the redemption path and by "worth ₹123" balance displays.
     */
    public function valueOfPoints(int $points, ?Customer $customer = null): string
    {
        if ($points <= 0) {
            return '0.0000';
        }

        $cfg = $this->config();

        $per = max(1, (int) $cfg['redeem_points']);
        // Money per single point.
        $rate = bcdiv((string) $cfg['redeem_value'], (string) $per, self::SCALE);

        $rate = (string) apply_filters('loyalty.redeem_rate', $rate, $customer);
        if (bccomp($rate, '0', self::SCALE) <= 0) {
            return '0.0000';
        }

        return bcadd(bcmul((string) $points, $rate, self::SCALE), '0', 4);
    }

    /**
     * Points needed to be worth at least the given money amount — the
     * inverse of {@see valueOfPoints}, rounded UP so the redeemed value
     * always covers what the cashier asked for.
     */
    public function pointsForValue(string $amount, ?Customer $customer = null): int
    {
        if (bccomp($amount, '0', 4) <= 0) {
            return 0;
        }

        $unit = $this->valueOfPoints(1, $customer);
        if (bccomp($unit, '0', self::SCALE) <= 0) {
            return 0;
        }

        $exact  = bcdiv($amount, $unit, self::SCALE);
        $floor  = bcadd($exact, '0', 0);

        return (int) (bccomp($exact, $floor, self::SCALE) > 0 ? bcadd($floor, '1', 0) : $floor);
    }

    /**
     * Resolve a redemption request into the points that will actually be
     * spent and the money they take off the sale.
     *
     * This is the authority. The cashier mirrors the same rules so the
     * on-screen total matches, but the server NEVER trusts the client's
     * figure — it re-runs this against the customer's real balance and
     * the stored rates, then checks the cart's discount carries it.
     *
     * Clamps, in order:
     *   1. to the customer's balance,
     *   2. to the per-sale cap (`max_redeem_percent` of the redeemable base),
     *   3. down to a whole number of points whose value fits that cap.
     *
     * @param string $base Redeemable amount — the sale total the points
     *                     are being applied against, AFTER any other discount.
     * @return array{points: int, value: string}
     */
    public function resolveRedemption(int $requestedPoints, ?Customer $customer, string $base): array
    {
        $none = ['points' => 0, 'value' => '0.0000'];

        if (! $this->isEnabled() || ! $customer || $requestedPoints <= 0) {
            return $none;
        }

        $cfg = $this->config();

        $balance = (int) ($customer->loyalty_points ?? 0);
        $points  = min($requestedPoints, $balance);

        // The minimum threshold is about the customer's BALANCE, not the
        // amount they choose to spend: "you need 100 points before points
        // become usable" is what a shopper understands.
        if ($balance < (int) $cfg['min_redeem_points'] || $points <= 0) {
            return $none;
        }

        // Per-sale cap: a percentage of what's redeemable, and never more
        // than the sale itself.
        $cap = bccomp($base, '0', 4) > 0 ? $base : '0';
        $pct = (string) $cfg['max_redeem_percent'];
        if (bccomp($pct, '100', 4) < 0) {
            $cap = bcdiv(bcmul($base, $pct, self::SCALE), '100', 4);
        }

        if (bccomp($cap, '0', 4) <= 0) {
            return $none;
        }

        $value = $this->valueOfPoints($points, $customer);

        // Over the cap → hand back only the points the cap can absorb.
        // Rounded DOWN so the resulting value never exceeds the cap.
        if (bccomp($value, $cap, 4) > 0) {
            $unit = $this->valueOfPoints(1, $customer);
            if (bccomp($unit, '0', self::SCALE) <= 0) {
                return $none;
            }
            $points = (int) bcadd(bcdiv($cap, $unit, self::SCALE), '0', 0);
            if ($points <= 0) {
                return $none;
            }
            $value = $this->valueOfPoints($points, $customer);
        }

        return ['points' => $points, 'value' => $value];
    }

    /**
     * When points earned right now should expire, or null when the
     * program has no expiry (`expiry_months` = 0).
     */
    public function expiresAt(): ?\Illuminate\Support\Carbon
    {
        $months = (int) $this->config()['expiry_months'];

        // Expiry is a real instant. Calculate from the company's local clock,
        // then persist it in UTC so HTTP and console processes agree even
        // when their ambient PHP timezones differ.
        return $months > 0 ? now(app_timezone())->addMonths($months)->utc() : null;
    }
}
