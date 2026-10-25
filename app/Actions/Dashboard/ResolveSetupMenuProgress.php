<?php

namespace App\Actions\Dashboard;

use App\Models\Company;

/**
 * The compact setup-progress reading shown in the topbar account menu.
 *
 * This is the ONLY way back to the checklist once it's been dismissed on the
 * dashboard, so — unlike ResolveSetupCard — it deliberately ignores
 * `dashboard_setup_dismissed`. It disappears only when there's genuinely
 * nothing left to nudge about.
 *
 * @see ResolveSetupCard for the dashboard card's own (dismissable) state.
 */
class ResolveSetupMenuProgress
{
    public function __construct(
        private readonly BuildSetupChecklist $buildChecklist,
    ) {}

    /** @return array{percent:int,done:int,total:int}|null */
    public function __invoke(): ?array
    {
        $company = Company::current();

        // `dashboard_setup_celebrated` is only ever set once every essential
        // step is done, so it doubles as a free short-circuit for established
        // installs — no existence checks on every single page load.
        if (! $company || $company->dashboard_setup_celebrated) {
            return null;
        }

        $checklist = ($this->buildChecklist)();

        // Complete but not yet celebrated: the dashboard still owes them the
        // one-time congratulations, and there's nothing actionable to link to.
        if ($checklist['complete']) {
            return null;
        }

        return [
            'percent' => $checklist['percent'],
            'done'    => $checklist['done'],
            'total'   => $checklist['total'],
        ];
    }
}
