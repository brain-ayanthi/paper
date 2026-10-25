<?php

namespace App\Http\Controllers\Admin;

use App\Actions\System\ResolveSchedulerStatus;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Settings → Scheduler (Cron Job).
 *
 * Teaches the customer to install the one cron job the whole app depends on,
 * and — more importantly — tells them the truth about whether it's running.
 *
 * The "Test" flow here is deliberately a *real* test. It does not call
 * `schedule:run` from PHP and declare success; that would prove only that PHP
 * works. Instead the page records the current heartbeat and polls {@see status}
 * waiting for the server's own cron to advance it. Green means the customer's
 * crontab genuinely fired. Nothing else can produce that result.
 *
 * @see ResolveSchedulerStatus
 * @see \App\Http\Controllers\CronController — the URL-cron fallback this page hands out.
 */
class SchedulerSettingsController extends Controller
{
    use RespondsJsonOrRedirect;

    /**
     * Selectable token lifetimes, in days. `0` is "never expires" — kept for
     * the external-pinger case (SEC #101).
     */
    private const TOKEN_LIFETIMES = [0, 90, 180, 365];

    public function index(Request $request, ResolveSchedulerStatus $resolve): View
    {
        $this->authorize('settings.view');

        return view('admin.settings.scheduler', [
            'scheduler' => ($resolve)(),
        ]);
    }

    /**
     * Poll target for the "Test my cron job" button.
     *
     * Returns the heartbeat as it stands right now. The page compares
     * `seconds_since` / `last_run_at` against the baseline it captured when the
     * test started; an advance means the cron fired while they watched.
     */
    public function status(Request $request, ResolveSchedulerStatus $resolve): JsonResponse
    {
        $this->authorize('settings.view');

        $status = ($resolve)();

        return response()->json([
            'state'         => $status['state'],
            'seconds_since' => $status['seconds_since'],
            'last_run_at'   => $status['last_run_at']?->toIso8601String(),
            'human'         => $status['human'],
            'display'       => $status['last_run_at'] ? format_datetime($status['last_run_at']) : null,
        ]);
    }

    /**
     * Mint (or re-mint) the URL-cron token.
     *
     * Only for hosts with no shell cron — the customer opts in by clicking, so
     * no install ever carries a live public endpoint it didn't ask for.
     * Re-minting immediately invalidates the previous URL, which doubles as the
     * "my URL leaked" remedy.
     *
     * The token now carries a lifetime (SEC #101). A URL-borne secret leaks
     * through crontab, external pingers, proxy logs and browser history, and a
     * token that never expires stays worth stealing forever. One year is the
     * default; `never` remains selectable because an install whose only
     * scheduling option is an external pinger must not silently lose its
     * backups to an expiry nobody remembered.
     */
    public function generateToken(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.update');

        if (pos_is_demo()) {
            return $this->demoBlocked($request, route('admin.settings.scheduler.index'));
        }

        $company = Company::current();
        abort_unless($company !== null, 404);

        $validated = $request->validate([
            'expires_days' => ['nullable', 'integer', Rule::in(self::TOKEN_LIFETIMES)],
        ]);

        $days = (int) ($validated['expires_days'] ?? 365);

        $company->update([
            'scheduler_cron_token'            => Str::random(48),
            'scheduler_cron_token_created_at' => now(),
            'scheduler_cron_token_expires_at' => $days > 0 ? now()->addDays($days) : null,
        ]);

        return $this->jsonOrRedirect(
            $request,
            __('settings.scheduler.flash.token_generated'),
            route('admin.settings.scheduler.index'),
        );
    }

    /** Turn the URL-cron endpoint off again — the URL 404s immediately after. */
    public function revokeToken(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorize('settings.update');

        if (pos_is_demo()) {
            return $this->demoBlocked($request, route('admin.settings.scheduler.index'));
        }

        $company = Company::current();
        abort_unless($company !== null, 404);

        $company->update([
            'scheduler_cron_token'            => null,
            'scheduler_cron_token_created_at' => null,
            'scheduler_cron_token_expires_at' => null,
        ]);

        return $this->jsonOrRedirect(
            $request,
            __('settings.scheduler.flash.token_revoked'),
            route('admin.settings.scheduler.index'),
        );
    }
}
