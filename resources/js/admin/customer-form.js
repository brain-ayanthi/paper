/**
 * Alpine factory for the Customer create / edit form.
 *
 * Responsibilities:
 *   - `addresses[]` repeater with stable `_uid` keys
 *   - `isBusiness` toggle showing/hiding the B2B section
 *   - `groupId` → auto-apply that group's `default_discount_percent`
 *     into the discount field (unless the user has already overridden it)
 *   - Default country for fresh address rows comes from the company /
 *     first store, computed server-side.
 */
export function customerForm(opts = {}) {
    let _uid = 0;
    const defaultCountry = opts.defaultCountry || '';

    const blankAddress = (raw = {}) => ({
        _uid:         ++_uid,
        label:        raw.label        ?? '',
        line1:        raw.line1        ?? '',
        line2:        raw.line2        ?? '',
        city:         raw.city         ?? '',
        state:        raw.state        ?? '',
        postal_code:  raw.postal_code  ?? '',
        country_code: raw.country_code ?? defaultCountry,
        landmark:     raw.landmark     ?? '',
        is_default:   !!raw.is_default,
    });

    // Map[groupId → defaultDiscountPercent | null] for instant lookup.
    const groupDiscounts = Object.fromEntries(
        (opts.groups ?? []).map((g) => [
            String(g.id),
            g.default_discount_percent !== null && g.default_discount_percent !== undefined
                ? String(g.default_discount_percent)
                : null,
        ]),
    );

    // Map[groupId → name] so the placeholder can say where the inherited
    // rate comes from ("15% — from Wholesale").
    const groupNames = Object.fromEntries(
        (opts.groups ?? []).map((g) => [String(g.id), String(g.name ?? '')]),
    );

    /** "15.0000" → "15%" — trailing zeros read as noise on a form hint. */
    const trimPercent = (v) => {
        const n = parseFloat(v);
        if (!Number.isFinite(n)) return '0%';
        return `${String(n)}%`;
    };

    return {
        isBusiness: !!opts.isBusiness,
        addresses:  (opts.addresses ?? []).map(blankAddress),

        // B2B fields, kept in state so they survive the x-if toggle and can
        // be wiped when the user unchecks "This is a business customer".
        business: {
            name:   opts.business?.name   ?? '',
            gstin:  opts.business?.gstin  ?? '',
            pan:    opts.business?.pan    ?? '',
            taxReg: opts.business?.taxReg ?? '',
        },

        groupId:           opts.initialGroupId  ? String(opts.initialGroupId)  : '',
        discountPercent:   opts.initialDiscount !== null && opts.initialDiscount !== undefined
                                ? String(opts.initialDiscount) : '',

        /**
         * The discount this customer gets when the field is left blank —
         * i.e. their group's rate. Shown as placeholder text, NEVER
         * written into the field: copying it would freeze the rate at
         * the moment of editing, so changing the group's percent later
         * would silently miss every existing member. The server resolves
         * `customer ?? group` at checkout instead.
         */
        get inheritedDiscount() {
            return groupDiscounts[this.groupId] ?? null;
        },

        get discountPlaceholder() {
            const inherited = this.inheritedDiscount;
            if (inherited === null) return '0';
            const name = groupNames[this.groupId] ?? '';
            return name
                ? `${trimPercent(inherited)} — from ${name}`
                : trimPercent(inherited);
        },

        /** True when this customer overrides their group's rate. */
        get discountIsOverride() {
            return String(this.discountPercent ?? '').trim() !== '';
        },

        /** Drop the override and go back to following the group. */
        clearDiscountOverride() {
            this.discountPercent = '';
        },

        init() {
            // Deliberately NO $watch on groupId writing into
            // discountPercent — see `inheritedDiscount` above. Switching
            // groups just changes what the placeholder advertises.

            // Unchecking "business" clears every B2B detail, so re-checking
            // starts clean and a save can't keep stale GSTIN/PAN/tax values.
            this.$watch('isBusiness', (on) => {
                if (!on) {
                    this.business.name = '';
                    this.business.gstin = '';
                    this.business.pan = '';
                    this.business.taxReg = '';
                }
            });
        },

        addAddress() {
            this.addresses.push(blankAddress({
                // First address auto-flags as default; subsequent ones
                // don't, so the user has to consciously choose.
                is_default: this.addresses.length === 0,
            }));
        },

        removeAddress(uid) {
            const wasDefault = this.addresses.find((a) => a._uid === uid)?.is_default;
            this.addresses = this.addresses.filter((a) => a._uid !== uid);
            // If we removed the default, promote the first remaining
            // row so there's always a fallback shipping address on a
            // saved customer with at least one row.
            if (wasDefault && this.addresses.length > 0) {
                this.addresses[0].is_default = true;
            }
        },

        /** Toggle a single row to default and clear it on the others. */
        makeDefault(uid) {
            this.addresses.forEach((a) => { a.is_default = a._uid === uid; });
        },
    };
}
