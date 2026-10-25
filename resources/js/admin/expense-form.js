/**
 * Expense create/edit form.
 *
 * Owns the two things the form needs to know about the till: whether this
 * expense will move a drawer at all, and — when it will — whether the amount
 * fits in it.
 *
 * A CASH expense saved while the user has a shift open for the store is paid
 * out of that drawer (`App\Actions\Expenses\SyncExpenseTillPayOut` writes the
 * pay-out), and a drawer can't go negative — `RecordCashDrawerEntry` refuses
 * the write and the controller rolls the expense back with it. Saying so here
 * turns a rejected save into a disabled button and a sentence.
 *
 * `tillCeiling` comes from the server already adjusted for an edit: the sync
 * reconciles to a target rather than writing a second pay-out, so an expense
 * that already took 100 out of the drawer gets that 100 back in its ceiling.
 */
export function expenseForm({
    payMethod     = '',
    hasOpenShift  = false,
    cashMethodIds = [],
    tillCeiling          = null, // decimal-string; null = no open shift to bound
    tillCeilingFormatted = '',
} = {}) {
    const cashMethods = (cashMethodIds || []).map(String);

    return {
        payMethod: String(payMethod ?? ''),
        amount:    '',
        hasOpenShift: Boolean(hasOpenShift),
        tillCeiling: tillCeiling === null || tillCeiling === ''
            ? null
            : Math.max(0, Number(tillCeiling)),
        tillCeilingFormatted,

        init() {
            // Seed from the rendered value so an edit starts with its own
            // amount without the input needing a server-side x-model default.
            this.amount = this.$root.querySelector('input[name="amount"]')?.value ?? '';
        },

        /** Cash + an open shift = this expense moves a real drawer. */
        get affectsTill() {
            return this.hasOpenShift && cashMethods.includes(String(this.payMethod));
        },

        get overTill() {
            if (!this.affectsTill || this.tillCeiling === null) return false;
            const amt = Number(this.amount);
            if (!Number.isFinite(amt) || amt <= 0) return false;
            return amt - this.tillCeiling > 0.00005;
        },
    };
}
