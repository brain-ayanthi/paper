/**
 * Alpine factory for Settings → Loyalty.
 *
 * Pure preview logic — the form posts normally through `data-ajax-form`.
 * Its whole job is to answer the two questions an owner actually has
 * while typing rates: "what does a sale of X earn?" and "what is a point
 * worth?". Mirrors the server-side LoyaltyCalculator: points always floor,
 * money always formats through the company currency.
 */
export function loyaltySettingsPage(initial = {}) {
    return {
        cfg: {
            enabled:            initial.enabled !== false,
            earn_points:        Number(initial.earn_points ?? 1),
            earn_per_amount:    String(initial.earn_per_amount ?? '10'),
            earn_on:            String(initial.earn_on ?? 'grand_total'),
            redeem_points:      Number(initial.redeem_points ?? 100),
            redeem_value:       String(initial.redeem_value ?? '10'),
            min_redeem_points:  Number(initial.min_redeem_points ?? 0),
            max_redeem_percent: String(initial.max_redeem_percent ?? '100'),
            expiry_months:      Number(initial.expiry_months ?? 0),
        },

        /** Sample basket the preview is computed against. */
        sample: String(initial.sample ?? '1000'),

        get sampleAmount() {
            const n = parseFloat(this.sample);
            return Number.isFinite(n) && n > 0 ? n : 0;
        },

        /** Points a sample sale of `sample` earns, at 1× (no tier bonus). */
        get earnedPoints() {
            const per = parseFloat(this.cfg.earn_per_amount);
            const pts = Number(this.cfg.earn_points);
            if (!Number.isFinite(per) || per <= 0 || !Number.isFinite(pts) || pts <= 0) return 0;
            return Math.floor((this.sampleAmount / per) * pts);
        },

        /** What those points are worth back, as formatted money. */
        get earnedValue() {
            return this.$formatMoney(this.valueOf(this.earnedPoints));
        },

        /** Money value of one point — the headline "a point is worth" figure. */
        get pointValue() {
            return this.$formatMoney(this.valueOf(1));
        },

        /** Effective return rate as a percentage of the sample basket. */
        get effectiveRate() {
            if (this.sampleAmount <= 0) return '0';
            const pct = (this.valueOf(this.earnedPoints) / this.sampleAmount) * 100;
            return (Math.round(pct * 100) / 100).toString();
        },

        valueOf(points) {
            const per = Number(this.cfg.redeem_points);
            const val = parseFloat(this.cfg.redeem_value);
            if (!Number.isFinite(per) || per <= 0 || !Number.isFinite(val) || val <= 0) return 0;
            return (points * val) / per;
        },
    };
}
