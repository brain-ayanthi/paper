<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Licensing\ActivateLicense;
use App\Actions\Licensing\DeregisterLicense;
use App\Actions\Licensing\RecheckLicense;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Services\Licensing\LicenseClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** License status, recovery and current-domain release. */
class LicenseController extends Controller
{
    use RespondsJsonOrRedirect;

    public function index(): View
    {
        abort_unless(config('pos.license.recheck'), 404);
        $this->authorize('settings.view');

        $license = app_license();

        return view('admin.settings.license', [
            'license' => $license,
            'keyLast4' => $this->maskedKey(),
        ]);
    }

    /** Force a re-verification now (the same action the scheduler runs nightly). */
    public function recheck(Request $request, RecheckLicense $recheck): JsonResponse|RedirectResponse
    {
        abort_unless(config('pos.license.recheck'), 404);
        $this->authorize('settings.update');
        $this->guardDemo();

        $result = ($recheck)(force: true);

        $message = match (true) {
            ($result['reason'] ?? null) === 'busy' => __('settings.license.busy'),
            ! empty($result['skipped']) => __('settings.license.recheck.skipped'),
            ! ($result['ok'] ?? false) => __('settings.license.recheck.unreachable'),
            ($result['status'] ?? null) === 'invalid' => __('settings.license.recheck.invalid'),
            default => __('settings.license.recheck.ok'),
        };

        return $this->jsonOrRedirect($request, $message, route('admin.settings.license.index'));
    }

    /** Last 4 chars of the configured license key, or null if none. */
    private function maskedKey(): ?string
    {
        $key = (string) config('pos.license.key', '');

        return $key === '' ? null : substr($key, -4);
    }

    public function deregister(Request $request, DeregisterLicense $deregister): JsonResponse|RedirectResponse
    {
        abort_unless(config('pos.license.recheck'), 404);
        $this->authorize('settings.update');
        abort_unless($request->user()?->is_super_admin, 403);
        $this->guardDemo();
        $request->validate(['confirm' => ['accepted']]);
        $this->guardDomain($request);
        $deregister();

        return $this->jsonOrRedirect($request, __('settings.license.deregister.done'), route('admin.settings.license.index'));
    }

    public function activate(Request $request, ActivateLicense $activate): JsonResponse|RedirectResponse
    {
        abort_unless(config('pos.license.recheck'), 404);
        $this->authorize('settings.update');
        abort_unless($request->user()?->is_super_admin, 403);
        $this->guardDemo();
        $data = $request->validate(['license_key' => ['required', 'string', 'max:64']]);
        $this->guardDomain($request);
        $activate($data['license_key']);

        return $this->jsonOrRedirect($request, __('settings.license.recheck.ok'), route('admin.settings.license.index'));
    }

    private function guardDemo(): void
    {
        if (pos_is_demo()) {
            throw ValidationException::withMessages(['_action' => __('settings.demo.locked')]);
        }
    }

    private function guardDomain(Request $request): void
    {
        if (! app(LicenseClient::class)->matchesDomain($request->root(), (string) config('app.url'))) {
            throw ValidationException::withMessages(['_action' => __('settings.license.domain_configuration')]);
        }
    }
}
