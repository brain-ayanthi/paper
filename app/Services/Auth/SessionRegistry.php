<?php

namespace App\Services\Auth;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The signed-in devices for a user — security register SEC #40 (defect #12).
 *
 * `docs/features/auth-users.md` §"Account security" specifies an active-session
 * list, a "sign out other devices" button, an admin force-logout, and a
 * one-session-at-a-time policy. None of it existed: there was no way to see
 * where an account was signed in, no way to end a session you didn't have the
 * device for, and the concurrent-login setting was never read. A cashier who
 * left themselves logged in on the shop-floor tablet could only be evicted by
 * changing their password.
 *
 * Everything here reads Laravel's own `sessions` table, so it needs
 * `SESSION_DRIVER=database`. That is the config default and what the installer
 * writes; a customer who switched to `file` gets an explanatory notice rather
 * than a broken screen, because the file driver stores no user id and there is
 * genuinely nothing to list.
 */
class SessionRegistry
{
    /** Is session inspection possible on this install? */
    public function supported(): bool
    {
        if (config('session.driver') !== 'database') {
            return false;
        }

        try {
            return Schema::hasTable(config('session.table', 'sessions'));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every live session for a user, newest activity first.
     *
     * @return Collection<int, object{id: string, ip_address: ?string, user_agent: ?string, last_activity: \Illuminate\Support\Carbon, is_current: bool, device: string}>
     */
    public function forUser(int $userId, ?string $currentId = null): Collection
    {
        if (! $this->supported()) {
            return collect();
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($row) => (object) [
                'id'            => $row->id,
                'ip_address'    => $row->ip_address,
                'user_agent'    => $row->user_agent,
                'last_activity' => \Illuminate\Support\Carbon::createFromTimestamp($row->last_activity),
                'is_current'    => $currentId !== null && $row->id === $currentId,
                'device'        => $this->describe($row->user_agent),
            ]);
    }

    /**
     * End every session for a user except `$keepId`. Pass null to end all of
     * them, which is what an admin force-logout does.
     *
     * Deleting the row IS the logout: Laravel's database session handler reads
     * the row on the next request, finds nothing, and starts a fresh guest
     * session.
     */
    public function revokeOthers(int $userId, ?string $keepId = null): int
    {
        if (! $this->supported()) {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->when($keepId !== null, fn ($q) => $q->where('id', '!=', $keepId))
            ->delete();
    }

    /**
     * A short, human label for a user agent — "Chrome on Windows", not 180
     * characters of tokens. Deliberately crude: this is for a shop owner
     * recognising their own tablet, not for analytics.
     */
    public function describe(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        if (trim($ua) === '') {
            return __('account.sessions.unknown_device');
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/')                              => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome/')                           => 'Chrome',
            str_contains($ua, 'Firefox/')                          => 'Firefox',
            str_contains($ua, 'Safari/')                           => 'Safari',
            default                                                 => __('account.sessions.unknown_browser'),
        };

        $platform = match (true) {
            str_contains($ua, 'Android')                            => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows')                            => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux')                              => 'Linux',
            default                                                  => null,
        };

        return $platform ? __('account.sessions.device', ['browser' => $browser, 'platform' => $platform]) : $browser;
    }
}
