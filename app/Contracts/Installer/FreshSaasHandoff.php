<?php

namespace App\Contracts\Installer;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Supplied by an approved pre-business SaaS host integration. The first
 * destination must verify the separate add-on purchase before platform setup.
 * The core installer never creates platform identities or host resources.
 */
interface FreshSaasHandoff
{
    public function available(): bool;

    public function beginAddonPurchase(Request $request): RedirectResponse;

    /**
     * Verify the separate SaaS item against an approved package and persist
     * the adapter's bound receipt before returning the next destination.
     * Return null for a safe validation failure. Never retain raw code here.
     */
    public function submitAddonPurchase(Request $request, #[\SensitiveParameter] string $code): ?RedirectResponse;

    /** Recheck the durable add-on receipt and approved package, not browser input. */
    public function addonPurchaseVerified(Request $request): bool;

    /** The adapter owns private owner proof and email delivery. */
    public function requestOwnerVerification(Request $request, string $email): bool;

    public function confirmOwnerVerification(Request $request, #[\SensitiveParameter] string $token): bool;

    public function ownerVerified(Request $request): bool;

    /** Issue the fixed-target, one-time host reference after owner proof. */
    public function beginHostHandoff(Request $request): ?RedirectResponse;

    /** Return an allowlisted, non-secret progress projection. This never advances host work. */
    public function platformProgress(Request $request): ?array;
}
