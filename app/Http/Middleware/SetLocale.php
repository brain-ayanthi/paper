<?php

namespace App\Http\Middleware;

use App\Models\Language;
use App\Support\InstallState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active locale per request and shares the current
 * {@see Language} (for the layout's `dir`/switcher) to all views
 * (docs/features/multi-language.md §4).
 *
 * Order: session('locale') → user → active store → default language.
 * Only ACTIVE languages are honored; otherwise we keep the app default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $languages = $this->activeLanguages();

        if ($languages->isNotEmpty()) {
            $candidates = array_filter([
                $request->session()->get('locale'),
                $request->user()?->locale,
                current_store()?->locale,
                $languages->firstWhere('is_default', true)?->code,
            ]);

            foreach ($candidates as $code) {
                if ($languages->firstWhere('code', $code)) {
                    app()->setLocale($code);
                    break;
                }
            }
        }

        view()->share('currentLanguage', $languages->firstWhere('code', app()->getLocale()));

        return $next($request);
    }

    /**
     * The active languages, cached (code-review #12). This runs on EVERY
     * request — including high-frequency cashier polls — but languages
     * change almost never, so a cache read replaces a SELECT plus a
     * `Schema::hasTable` metadata roundtrip on the hot path. The cache is
     * busted whenever a Language is saved or deleted (see Language::booted).
     *
     * @return \Illuminate\Support\Collection<int, Language>
     */
    private function activeLanguages(): \Illuminate\Support\Collection
    {
        $cached = Cache::get(Language::ACTIVE_CACHE_KEY);
        if (is_array($cached)) {
            return Language::hydrate($cached); // hot path: one cache read, no DB
        }

        if ($cached !== null) {
            // Older releases cached an Eloquent Collection. Cache stores that
            // disable class unserialization restore it as
            // __PHP_Incomplete_Class, so discard it and rebuild safely.
            Cache::forget(Language::ACTIVE_CACHE_KEY);
        }

        // Cold path only. Before the install finishes the languages table
        // may not exist yet; `isLocked()` is a cheap file check that stands
        // in for "the database is ready". Return empty WITHOUT caching so
        // the seeded languages appear on the first request after install.
        if (! InstallState::isLocked()) {
            return collect();
        }

        try {
            $languages = Language::query()->active()->ordered()->get();
        } catch (\Throwable $e) {
            return collect();
        }

        // English is seeded non-deletable, so an installed site always has
        // at least one row — a cached value is therefore never empty, and
        // Cache::get()'s null return unambiguously means "not cached yet".
        Cache::forever(
            Language::ACTIVE_CACHE_KEY,
            $languages->map(fn (Language $language): array => $language->getAttributes())->all(),
        );

        return $languages;
    }
}
