<?php

namespace App\Services;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** Session-length reservations also cover stores that do not enforce shifts. */
class TerminalReservation
{
    public function available(int $terminalId, Request $request): bool
    {
        $userId = (int) $request->user()->id;

        if ($this->hasOtherShift($terminalId, $userId)
            || $this->hasDifferentOpenShiftForUser($terminalId, $userId)) {
            return false;
        }

        $owner = Cache::get($this->key($terminalId));
        if ($owner !== null && $owner !== $this->owner($request)) {
            return false;
        }

        // A user reservation closes the gap before a shift is opened: the
        // same account in another browser/device cannot reserve a second cash
        // counter. The owning session may still switch terminals while it has
        // no open shift.
        $userReservation = Cache::get($this->userKey($userId));
        if (is_array($userReservation)
            && ($userReservation['owner'] ?? null) !== $this->owner($request)) {
            $reservedTerminal = (int) ($userReservation['terminal_id'] ?? 0);
            $reservedOwner = $reservedTerminal ? Cache::get($this->key($reservedTerminal)) : null;

            if ($reservedOwner === ($userReservation['owner'] ?? null)) {
                return false;
            }

            // Its paired terminal reservation has expired/been cleared.
            Cache::forget($this->userKey($userId));
        }

        return true;
    }

    public function claim(int $terminalId, Request $request): void
    {
        $userId = (int) $request->user()->id;

        // Always lock user first, then terminal. This makes simultaneous
        // requests from the same account serialize even when they target two
        // different terminals.
        Cache::lock($this->userKey($userId).':lock', 10)->block(5, function () use ($terminalId, $request, $userId) {
            Cache::lock($this->key($terminalId).':lock', 10)->block(5, function () use ($terminalId, $request) {
                if (! $this->available($terminalId, $request)) {
                    throw ValidationException::withMessages(['terminal_id' => __('terminals.select.occupied')]);
                }

                Cache::put($this->key($terminalId), $this->owner($request), $this->ttl());
            });

            $previous = (int) $request->session()->get('reserved_terminal_id');

            Cache::put($this->userKey($userId), [
                'terminal_id' => $terminalId,
                'owner' => $this->owner($request),
            ], $this->ttl());

            if ($previous && $previous !== $terminalId) {
                $this->releaseOwnedTerminal($previous, $request);
            }
            $request->session()->put('reserved_terminal_id', $terminalId);
        });
    }

    public function release(Request $request): void
    {
        $userId = (int) $request->user()->id;

        Cache::lock($this->userKey($userId).':lock', 10)->block(5, function () use ($request, $userId) {
            $terminalId = (int) $request->session()->get('reserved_terminal_id');
            if ($terminalId) {
                $this->releaseOwnedTerminal($terminalId, $request);
            }

            $reservation = Cache::get($this->userKey($userId));
            if (is_array($reservation) && ($reservation['owner'] ?? null) === $this->owner($request)) {
                Cache::forget($this->userKey($userId));
            }
            $request->session()->forget('reserved_terminal_id');
        });
    }

    /** Release a terminal after its shift closes, regardless of session owner. */
    public function releaseTerminal(int $terminalId, ?int $userId = null): void
    {
        $release = function () use ($terminalId, $userId): void {
            Cache::lock($this->key($terminalId).':lock', 10)->block(5, function () use ($terminalId) {
                Cache::forget($this->key($terminalId));
            });

            if ($userId) {
                $reservation = Cache::get($this->userKey($userId));
                if (is_array($reservation) && (int) ($reservation['terminal_id'] ?? 0) === $terminalId) {
                    Cache::forget($this->userKey($userId));
                }
            }
        };

        if ($userId) {
            Cache::lock($this->userKey($userId).':lock', 10)->block(5, $release);
            return;
        }

        $release();
    }

    protected function hasOtherShift(int $terminalId, int $userId): bool
    {
        return Shift::query()->where('terminal_id', $terminalId)
            ->where('status', Shift::STATUS_OPEN)->where('user_id', '!=', $userId)->exists();
    }

    protected function hasDifferentOpenShiftForUser(int $terminalId, int $userId): bool
    {
        return Shift::query()
            ->where('user_id', $userId)
            ->where('status', Shift::STATUS_OPEN)
            ->where(function ($query) use ($terminalId) {
                $query->whereNull('terminal_id')->orWhere('terminal_id', '!=', $terminalId);
            })
            ->exists();
    }

    private function releaseOwnedTerminal(int $terminalId, Request $request): void
    {
        Cache::lock($this->key($terminalId).':lock', 10)->block(5, function () use ($terminalId, $request) {
            if (Cache::get($this->key($terminalId)) === $this->owner($request)) {
                Cache::forget($this->key($terminalId));
            }
        });
    }

    private function owner(Request $request): string
    {
        return hash('sha256', $request->user()->id.':'.$request->session()->getId());
    }

    private function key(int $terminalId): string
    {
        return 'pos:terminal-reservation:'.$terminalId;
    }

    private function userKey(int $userId): string
    {
        return 'pos:user-terminal-reservation:'.$userId;
    }

    private function ttl(): int
    {
        return max(1, (int) config('session.lifetime', 120)) * 60;
    }
}
