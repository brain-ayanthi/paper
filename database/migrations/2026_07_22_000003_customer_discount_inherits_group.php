<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Customer discounts now INHERIT from their group instead of being
 * copied onto the customer at edit time (docs/features/customers.md §4.3).
 *
 * Before this change, picking a group in the customer form copied that
 * group's percent into `customers.default_discount_percent`. The value
 * then froze: raising a group's rate from 15% to 20% left every existing
 * member on 15%, silently.
 *
 * From now on, NULL on the customer means "follow my group" and the rate
 * is resolved at checkout. This migration converts the accidental copies
 * back into inheritance: where a customer's percent is *exactly* their
 * group's current percent, we NULL it.
 *
 * **Nothing changes for anyone the day this runs** — those customers
 * resolve to the same number either way. What changes is the future: they
 * now follow the group when it's edited.
 *
 * Deliberately conservative:
 *   - a customer with NO group is never touched;
 *   - a percent that DIFFERS from the group's is a real override and is
 *     kept (that's the whole point of the override);
 *   - a customer whose group has no percent is left alone.
 *
 * Irreversible by design: `down()` can't tell a pre-existing NULL from
 * one we created, and re-copying group rates onto customers would
 * reintroduce the frozen-rate bug. Rolling back the code is enough —
 * the old code reads the customer column, and NULL there simply means
 * "no discount", which is the safe direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('customer_groups')
            ->whereNotNull('default_discount_percent')
            ->pluck('default_discount_percent', 'id');

        foreach ($groups as $groupId => $groupPercent) {
            DB::table('customers')
                ->where('customer_group_id', $groupId)
                ->whereNotNull('default_discount_percent')
                // Exact match only — a customer on a different rate meant it.
                ->whereRaw('CAST(default_discount_percent AS DECIMAL(7,4)) = ?', [$groupPercent])
                ->update(['default_discount_percent' => null]);
        }
    }

    public function down(): void
    {
        // Intentionally empty — see the class docblock.
    }
};
