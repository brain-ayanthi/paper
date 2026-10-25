<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email:rfc'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Rate-limit attempts by email + IP. 5 failed attempts within 60 seconds
     * locks the email/IP combo for the rest of the minute.
     */
    public function ensureIsNotRateLimited(): void
    {
        // Two limiters: email+IP (tight) and email alone (looser). See
        // accountThrottleKey() for why the second exists and why it is looser.
        foreach ([[$this->throttleKey(), 5], [$this->accountThrottleKey(), 20]] as [$key, $max]) {
            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }
    }

    public function hitRateLimit(): void
    {
        RateLimiter::hit($this->throttleKey(), 60);
        RateLimiter::hit($this->accountThrottleKey(), 60);
    }

    public function clearRateLimit(): void
    {
        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->accountThrottleKey());
    }

    public function throttleKey(): string
    {
        return strtolower($this->string('email').'|'.$this->ip());
    }

    /**
     * A second limiter keyed on the EMAIL alone — security register SEC #9
     * (defect #9).
     *
     * The original key is email+IP, so an attacker spreading attempts across
     * many addresses (a botnet, or just a VPN rotation) never trips it: every
     * new IP starts a fresh count of five. Capping per-account attempts as
     * well means the account is protected however many sources the guessing
     * comes from.
     *
     * The per-account cap is deliberately looser than the per-IP one (20 vs 5
     * per minute) so a shared-IP office where several staff mistype at once
     * cannot be used to lock a colleague out — which would turn this control
     * into a denial-of-service against the merchant.
     */
    public function accountThrottleKey(): string
    {
        return 'login-account|'.strtolower((string) $this->string('email'));
    }
}
