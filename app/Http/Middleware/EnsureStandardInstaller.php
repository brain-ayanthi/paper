<?php

namespace App\Http\Middleware;

use App\Support\InstallerSaasEntry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keep a selected SaaS flow out of the POS business database installer. */
class EnsureStandardInstaller
{
    public function handle(Request $request, Closure $next, string $boundary = 'selected'): Response
    {
        $saas = app(InstallerSaasEntry::class);
        $blocked = $saas->recoveryRequired()
            || ($boundary === 'handoff' ? $saas->handoffStarted($request) : $saas->selected($request));
        if ($blocked) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('installer.saas.business_database_blocked'),
                ], 409);
            }

            return redirect()->route('install.mode')
                ->with('error', __('installer.saas.business_database_blocked'));
        }

        return $next($request);
    }
}
