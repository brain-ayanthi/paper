<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * "When did the current run-window open?"
 *
 * Answers, for a recurring schedule, the most recent occurrence of its
 * configured time that has already passed — returned in UTC so it can be
 * compared directly against stored timestamps.
 *
 * Two problems this solves, both of which bit a real customer install:
 *
 * 1. A window is a range, not an instant. `dailyAt('02:00')` fires only if the
 *    cron happens to run during minute 02:00; if the host pauses cron overnight
 *    the task is skipped forever with no error anywhere. Comparing against the
 *    window's *start* instead lets the task catch up on any later tick.
 *
 * 2. Console runs have no HTTP request, so `ApplyCompanySettings` never runs and
 *    `config('app.timezone')` is still the hardcoded UTC from config/app.php.
 *    "2 AM" therefore meant 2 AM UTC — 7:30 AM for an Indian store — while the
 *    settings screen promised the store's own 2 AM. These helpers resolve the
 *    company zone explicitly (`app_timezone()` reads it from the database), so
 *    the promise holds in CLI as well as in the browser.
 */
final class DailyWindow
{
    /** Most recent `$time` (H:i) that has passed today or yesterday. */
    public static function daily(string $time, ?string $timezone = null): CarbonImmutable
    {
        [$now, $hour, $minute] = self::anchor($time, $timezone);

        $window = $now->setTime($hour, $minute);

        return self::utc($window->greaterThan($now) ? $window->subDay() : $window);
    }

    /**
     * Most recent `$time` on `$dayOfWeek` (0 = Sunday, matching Laravel's
     * `weeklyOn()` and Carbon's `dayOfWeek`).
     */
    public static function weekly(int $dayOfWeek, string $time, ?string $timezone = null): CarbonImmutable
    {
        [$now, $hour, $minute] = self::anchor($time, $timezone);

        $window = $now->setTime($hour, $minute);
        if ($window->greaterThan($now)) {
            $window = $window->subDay();
        }

        // At most six steps back to land on the target weekday.
        while ($window->dayOfWeek !== $dayOfWeek) {
            $window = $window->subDay();
        }

        return self::utc($window);
    }

    /** Most recent `$time` on day `$dayOfMonth` of the month. */
    public static function monthly(int $dayOfMonth, string $time, ?string $timezone = null): CarbonImmutable
    {
        [$now, $hour, $minute] = self::anchor($time, $timezone);

        // Clamped to 28 so a schedule set to the 29th–31st still fires every
        // month rather than skipping February entirely.
        $day = max(1, min(28, $dayOfMonth));

        $window = $now->startOfMonth()->addDays($day - 1)->setTime($hour, $minute);

        if ($window->greaterThan($now)) {
            $window = $now->subMonthNoOverflow()->startOfMonth()->addDays($day - 1)->setTime($hour, $minute);
        }

        return self::utc($window);
    }

    /**
     * Current time in the company's zone, plus the parsed target time.
     *
     * @return array{0: CarbonImmutable, 1: int, 2: int}
     */
    private static function anchor(string $time, ?string $timezone): array
    {
        $zone = $timezone ?: app_timezone();

        $parts  = explode(':', $time);
        $hour   = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);

        return [CarbonImmutable::now($zone), $hour, $minute];
    }

    private static function utc(CarbonImmutable $window): CarbonImmutable
    {
        return $window->startOfMinute()->utc();
    }
}
