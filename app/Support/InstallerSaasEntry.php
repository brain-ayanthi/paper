<?php

namespace App\Support;

use App\Contracts\Installer\FreshSaasHandoff;
use App\Contracts\Installer\FreshSaasInstallerJourney;
use App\Contracts\Installer\FreshSaasSharedHostingSetup;
use App\Support\Installer\FreshSaasAdminCredentials;
use App\Contracts\Installer\FreshSaasSignedDelivery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class InstallerSaasEntry
{
    public const SESSION_MODE = 'installer_mode';
    public const MODE_SAAS = 'saas';
    public const SESSION_CORE_PURCHASE = 'installer_core_purchase';
    public const SESSION_OPERATION = 'installer_saas_operation';
    private const PROOF_SECONDS = 900;

    public function choiceAvailable(): bool
    {
        if (! $this->baseAvailable()) {
            return false;
        }

        return $this->journeyAvailable() || $this->handoffAvailable();
    }

    public function available(): bool
    {
        return $this->baseAvailable() && $this->handoffAvailable();
    }

    public function hostingChoiceAvailable(): bool
    {
        return $this->baseAvailable() && $this->journeyAvailable();
    }

    public function hostingCapabilities(): array
    {
        if (! $this->hostingChoiceAvailable()) {
            return $this->emptyHostingReport('awaiting_configuration');
        }

        try {
            $report = app(FreshSaasInstallerJourney::class)->hostingCapabilities();

            return is_array($report) ? $report : $this->emptyHostingReport('blocked');
        } catch (\Throwable) {
            return $this->emptyHostingReport('blocked');
        }
    }

    public function selectedHostingMode(): ?string
    {
        if (! $this->hostingChoiceAvailable()) {
            return null;
        }

        try {
            $mode = app(FreshSaasInstallerJourney::class)->selectedHostingMode();

            return in_array($mode, ['shared_manual', 'vps_automatic'], true) ? $mode : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function selectHostingMode(string $mode): bool
    {
        if (! $this->hostingChoiceAvailable()
            || ! in_array($mode, ['shared_manual', 'vps_automatic'], true)) {
            return false;
        }

        return app(FreshSaasInstallerJourney::class)->selectHostingMode($mode);
    }

    public function sharedHostingAvailable(): bool
    {
        if (! $this->baseAvailable()
            || ! app()->bound(FreshSaasSharedHostingSetup::class)) {
            return false;
        }

        try {
            return app(FreshSaasSharedHostingSetup::class)->available();
        } catch (\Throwable) {
            return false;
        }
    }

    public function sharedHostingState(): array
    {
        if (! $this->sharedHostingAvailable()) {
            return ['configured' => false];
        }

        try {
            $state = app(FreshSaasSharedHostingSetup::class)->state();

            return is_array($state) ? $state : ['configured' => false];
        } catch (\Throwable) {
            return ['configured' => false];
        }
    }

    public function configureSharedHosting(#[\SensitiveParameter] array $input): bool
    {
        return $this->sharedHostingAvailable()
            && app(FreshSaasSharedHostingSetup::class)->configure($input);
    }

    public function sharedHostingFailureCode(): ?string
    {
        if (! $this->sharedHostingAvailable()) return null;

        try {
            $setup = app(FreshSaasSharedHostingSetup::class);
            if (! method_exists($setup, 'failureCode')) return null;
            $code = $setup->failureCode();

            return is_string($code) && in_array($code, [
                'availability', 'installation_state', 'installer_url', 'domains',
                'database_details', 'platform_root', 'mail_details',
                'database_connection', 'package_approval', 'private_storage',
                'configuration_save', 'environment_save', 'configuration_verify',
            ], true) ? $code : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function selected(Request $request): bool
    {
        $state = $this->modeState();
        if (($state['status'] ?? null) === 'valid') {
            $request->session()->put(self::SESSION_MODE, $state['mode']);
            if ($state['mode'] === self::MODE_SAAS) {
                $request->session()->put(self::SESSION_OPERATION, $state['operation_id']);
            }

            return $state['mode'] === self::MODE_SAAS;
        }

        return ($state['status'] ?? null) === 'missing'
            && $request->session()->get(self::SESSION_MODE) === self::MODE_SAAS;
    }

    public function freshAdminReady(): bool
    {
        $mode = $this->modeState();
        if (($mode['status'] ?? null) !== 'valid'
            || ($mode['mode'] ?? null) !== self::MODE_SAAS
            || ! is_string($mode['prepared_source_sha256'] ?? null)) return false;
        try {
            return (new FreshSaasAdminCredentials(
                storage_path('app/private/saas-fresh-installer/admin'),
                (string) config('app.key', ''),
            ))->read($mode) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function recoveryRequired(): bool
    {
        return in_array($this->modeState()['status'] ?? null, ['invalid', 'foreign'], true);
    }

    public function handoffStarted(Request $request): bool
    {
        $state = $this->modeState();

        return (($state['status'] ?? null) === 'valid'
                && ($state['mode'] ?? null) === self::MODE_SAAS
                && ($state['handoff_started'] ?? false) === true)
            || $request->session()->get('installer_saas_handoff_started') === true;
    }

    public function selectMode(Request $request, string $mode): bool
    {
        if (! in_array($mode, ['standard', self::MODE_SAAS], true)
            || $this->recoveryRequired()
            || ($mode === 'standard' && $this->currentMode($request) === self::MODE_SAAS
                && $this->freshAdminReady())
            || ($this->businessBoundaryStarted() && $this->currentMode($request) !== $mode)
            || ($this->handoffStarted($request) && $this->currentMode($request) !== $mode)) {
            return false;
        }

        try {
            $state = app(InstallerModeState::class)->select($mode, $this->preparedSourceSha256());
        } catch (\Throwable) {
            return false;
        }

        $request->session()->put(self::SESSION_MODE, $mode);
        if ($mode === self::MODE_SAAS) {
            $request->session()->put(self::SESSION_OPERATION, $state['operation_id']);
        } else {
            $request->session()->forget([self::SESSION_OPERATION, self::SESSION_CORE_PURCHASE]);
        }

        return true;
    }

    public function currentMode(Request $request): ?string
    {
        $state = $this->modeState();
        if (($state['status'] ?? null) === 'valid') {
            return $state['mode'];
        }

        $legacy = $request->session()->get(self::SESSION_MODE);

        return ($state['status'] ?? null) === 'missing'
            && in_array($legacy, ['standard', self::MODE_SAAS], true) ? $legacy : null;
    }

    public function corePurchaseLaunchBypass(): bool
    {
        return config('app.env') === 'staging'
            && config('pos.fresh_saas_installer.core_purchase_launch_bypass', false) === true;
    }

    public function testPurchaseBypass(): bool
    {
        return config('pos.fresh_saas_installer.test_purchase_bypass', false) === true;
    }

    public function testHttpsBypass(): bool
    {
        return config('pos.fresh_saas_installer.test_https_bypass', false) === true;
    }

    public function recordCorePurchase(Request $request, string $scope): void
    {
        if (! in_array($scope, ['verified', 'launch_bypass'], true)) {
            throw new \InvalidArgumentException('Unsupported core purchase scope.');
        }

        $request->session()->put(self::SESSION_CORE_PURCHASE, [
            'scope' => $scope,
            'issued_at' => time(),
        ]);
    }

    public function corePurchaseScope(Request $request): ?string
    {
        $proof = $request->session()->get(self::SESSION_CORE_PURCHASE);
        if (! is_array($proof) || ! is_int($proof['issued_at'] ?? null)) {
            return null;
        }

        $age = time() - $proof['issued_at'];
        if ($age < 0 || $age >= self::PROOF_SECONDS) {
            return null;
        }

        if (($proof['scope'] ?? null) === 'verified' && InstallState::get('license_validated', false)) {
            return 'verified';
        }

        return ($proof['scope'] ?? null) === 'launch_bypass' && $this->corePurchaseLaunchBypass()
            ? 'launch_bypass'
            : null;
    }

    public function signedDeliveryReady(): bool
    {
        if (! $this->freshAdminReady() || ! app()->bound(FreshSaasHandoff::class)) {
            return false;
        }

        try {
            $handoff = app(FreshSaasHandoff::class);

            return $handoff instanceof FreshSaasSignedDelivery && $handoff->available();
        } catch (\Throwable) {
            return false;
        }
    }

    public function begin(Request $request): RedirectResponse
    {
        abort_unless($this->available() && $this->selected($request)
            && $this->freshAdminReady()
            && $this->corePurchaseScope($request) !== null, 404);

        try {
            app(InstallerModeState::class)->select(self::MODE_SAAS, $this->preparedSourceSha256());
            $state = app(InstallerModeState::class)->markHandoffStarted($this->preparedSourceSha256());
        } catch (\Throwable) {
            abort(409, __('installer.saas.recovery_required'));
        }

        $request->session()->put(self::SESSION_MODE, self::MODE_SAAS);
        $request->session()->put(self::SESSION_OPERATION, $state['operation_id']);
        $request->session()->put('installer_saas_handoff_started', true);

        return app(FreshSaasHandoff::class)->beginAddonPurchase($request);
    }

    private function baseAvailable(): bool
    {
        return config('pos.fresh_saas_installer.enabled', false)
            && ! config('pos.license.dev_bypass', false)
            && (config('pos.license.required', true) || $this->corePurchaseLaunchBypass())
            && ! InstallState::get('db_configured', false)
            && ! InstallState::get('migrations_run', false)
            && ! InstallState::get('admin_created', false);
    }

    private function businessBoundaryStarted(): bool
    {
        return InstallState::get('db_configured', false)
            || InstallState::get('migrations_run', false)
            || InstallState::get('admin_created', false);
    }

    private function modeState(): array
    {
        return app(InstallerModeState::class)->read($this->preparedSourceSha256());
    }

    private function preparedSourceSha256(): ?string
    {
        if (! app()->bound('saas.fresh.prepared_source')) return null;
        $source = app('saas.fresh.prepared_source');
        if (! is_array($source)) return null;
        $identity = [];
        foreach (['delivery_sha256', 'manifest_sha256', 'trusted_key_sha256', 'addon_zip_sha256'] as $field) {
            if (! is_string($source[$field] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/D', $source[$field])) return null;
            $identity[$field] = $source[$field];
        }

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function journeyAvailable(): bool
    {
        if (! app()->bound(FreshSaasInstallerJourney::class)) {
            return false;
        }

        try {
            return app(FreshSaasInstallerJourney::class)->available();
        } catch (\Throwable) {
            return false;
        }
    }

    private function handoffAvailable(): bool
    {
        if (! app()->bound(FreshSaasHandoff::class)) {
            return false;
        }

        try {
            return app(FreshSaasHandoff::class)->available();
        } catch (\Throwable) {
            return false;
        }
    }

    private function emptyHostingReport(string $status): array
    {
        return [
            'status' => $status,
            'recommended_mode' => null,
            'automatic_provisioning_ready' => false,
            'checks' => [],
            'missing_extensions' => [],
            'limitations' => [],
            'environment_classification' => 'unclassified',
        ];
    }
}
