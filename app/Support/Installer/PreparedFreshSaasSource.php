<?php

namespace App\Support\Installer;

/** Verifies a host-staged, signed add-on before any add-on PHP is loaded. */
final class PreparedFreshSaasSource
{
    public function verify(string $directory, #[\SensitiveParameter] string $sealKey,
        string $coreVersion, string $trustedKeyDigest,
        ?FreshSaasRehearsalAuthorization $rehearsal = null): ?array
    {
        try {
            if ($directory === '' || strlen($sealKey) < 32 || ! is_dir($directory)
                || $this->linked($directory) || $this->insidePublic($directory)
                || $this->writableByOthers($directory)) {
                return null;
            }
            $sealPath = rtrim($directory, '/\\').'/fresh-source.json';
            if (! is_file($sealPath) || is_link($sealPath) || filesize($sealPath) > 2097152
                || $this->writableByOthers($sealPath)) {
                return null;
            }
            $sealed = json_decode((string) file_get_contents($sealPath), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($sealed) || array_keys($sealed) !== ['payload', 'mac']
                || ! is_string($sealed['payload']) || ! is_string($sealed['mac'])
                || ! hash_equals(hash_hmac('sha256', $sealed['payload'], $sealKey), $sealed['mac'])) {
                return null;
            }
            $payload = json_decode($sealed['payload'], true, 12, JSON_THROW_ON_ERROR);
            $payloadKeys = is_array($payload) ? array_keys($payload) : [];
            $payloadKeys = array_values(array_diff($payloadKeys, ['rehearsal_item_id']));
            if (! is_array($payload) || $payloadKeys !== [
                'version', 'source_dir', 'delivery_archive', 'delivery_sha256', 'manifest_sha256',
                'trusted_key_sha256', 'addon_zip_sha256', 'core_version', 'addon_version', 'files',
            ] || $payload['version'] !== 1 || $payload['source_dir'] !== $directory
                || $payload['core_version'] !== $coreVersion
                || ! preg_match('/\A[a-f0-9]{64}\z/D', $trustedKeyDigest)
                || $payload['trusted_key_sha256'] !== $trustedKeyDigest
                || ! is_array($payload['files']) || count($payload['files']) < 4
                || count($payload['files']) > 10000) {
                return null;
            }
            foreach (['delivery_sha256', 'manifest_sha256', 'trusted_key_sha256', 'addon_zip_sha256'] as $field) {
                if (! is_string($payload[$field]) || ! preg_match('/\A[a-f0-9]{64}\z/D', $payload[$field])) {
                    return null;
                }
            }
            $delivery = $payload['delivery_archive'];
            $addon = rtrim($directory, '/\\').'/addon.zip';
            if (! is_string($delivery) || $this->linked($delivery)
                || ! is_file($delivery) || filesize($delivery) > 1073741824
                || ! hash_equals($payload['delivery_sha256'], hash_file('sha256', $delivery))
                || ! is_file($addon) || is_link($addon) || filesize($addon) > 268435456
                || $this->writableByOthers($addon)
                || ! hash_equals($payload['addon_zip_sha256'], hash_file('sha256', $addon))) {
                return null;
            }
            $source = rtrim($directory, '/\\').'/source';
            if (! is_dir($source) || is_link($source) || $this->writableByOthers($source)) {
                return null;
            }
            $actual = [];
            $size = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,
                \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isLink() || ! $file->isFile()
                    || $this->writableByOthers($file->getPathname())
                    || ($size += $file->getSize()) > 268435456) {
                    return null;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
                if (! $this->safeName($relative)) {
                    return null;
                }
                $actual[$relative] = hash_file('sha256', $file->getPathname());
                if (count($actual) > 10000) {
                    return null;
                }
            }
            ksort($actual);
            if ($actual !== $payload['files']) {
                return null;
            }
            foreach (['plugin.json', 'release-status.json',
                'src/Providers/FreshInstallerServiceProvider.php',
                'src/Setup/FreshInstallerHostConfiguration.php',
                'src/Setup/FreshInstallerJourneyAdapter.php',
                'src/Setup/FreshInstallerJourneyStore.php',
                'src/Setup/FreshSharedHostingConfigurator.php',
                'src/Setup/FreshSharedHostingPreflight.php',
                'src/Setup/FreshVpsBootstrapConfiguration.php',
                'src/Setup/FreshVpsHostingConfigurator.php',
                'src/Setup/FreshVpsPreflight.php',
                'src/Infrastructure/Ubuntu/FreshVpsBootstrapInstaller.php',
                'src/Infrastructure/Ubuntu/FreshVpsRootCoordinator.php',
                'src/Setup/HostingMode.php',
                'src/Setup/HostingModeContract.php',
                'src/Setup/HostingModeState.php',
                'src/Setup/HostingCapabilityDetector.php',
                'src/Setup/HostingCapabilityObservation.php',
                'src/Setup/LocalSetupPreflight.php',
                'src/Setup/FreshInstallerHostRuntimeFactory.php',
                'src/Setup/FreshInstallerPlatformBridgeFactory.php',
                'src/Setup/FreshInstallerPlatformBridge.php',
                'src/Setup/FreshInstallerAdminHandoff.php',
                'src/Setup/FreshInstallerCoreAdminReader.php',
                'src/Setup/FreshInstallerDirectHandoffAdapter.php',
                'src/Setup/SharedPlatformRoot.php',
                'src/SharedHosting/FreshSharedPlatformInstaller.php',
                'src/SharedHosting/FreshSharedPlatformHost.php',
                'src/SharedHosting/FreshSharedManagementBootstrap.php',
                'src/SharedHosting/SharedPlatformRuntime.php',
                'src/Setup/FreshInstallerHandoffAdapter.php', 'src/Setup/FreshPurchaseSubmission.php',
                'src/Setup/FreshPurchaseReceiptStore.php', 'src/Setup/FreshPurchaseGate.php',
                'src/Setup/AddonPurchaseVerification.php', 'src/Setup/FreshOwnerProof.php',
                'src/Setup/FreshHostHandoff.php', 'src/Setup/FreshPlatformPreflight.php',
                'src/Setup/FreshPlatformRunnerRequest.php', 'src/Setup/BootstrapJournal.php',
                'src/Setup/ActivationConfiguration.php', 'src/Actions/Setup/AdvanceOperatorSetup.php',
                'src/Contracts/SetupVerification.php', 'src/Contracts/SetupModeVerification.php',
                'src/Contracts/SetupPreflight.php', 'src/Contracts/DetailedSetupPreflight.php',
                'src/Contracts/FreshInstallerHostPreflight.php',
                'src/Infrastructure/Ubuntu/FreshPlatformRunnerReview.php',
                'src/Infrastructure/Ubuntu/NativeFreshPlatformRequestReview.php',
                'src/Infrastructure/Ubuntu/NativeFreshAdministratorClaim.php',
                'src/Infrastructure/Ubuntu/PlatformRunnerJob.php',
                'src/Infrastructure/Ubuntu/HostFiles.php',
                'bin/saas-fresh-request-review.php',
                'bin/saas-fresh-vps-bootstrap.php',
                'src/Setup/LicenseHttpTransport.php', 'src/Setup/SetupException.php',
                'src/Contracts/FreshCorePurchaseEvidence.php', 'src/Contracts/SetupPurchaseVerification.php',
                'src/Packaging/FreshInstallerPackageApproval.php', 'src/Packaging/VerifyCustomerRelease.php',
        'src/Packaging/FreshInstallerRehearsalAuthorization.php', 'src/Packaging/FreshInstallerRehearsalPermit.php',
                'src/Packaging/PrepareFreshInstallerDelivery.php', 'src/Packaging/ReleaseFiles.php',
                'bin/prepare-fresh-installer-delivery.php', 'src/Infrastructure/Platform/BuildPlatformPlan.php'] as $required) {
                if (! isset($actual[$required])) {
                    return null;
                }
            }
            $plugin = json_decode((string) file_get_contents($source.'/plugin.json'), true, 12, JSON_THROW_ON_ERROR);
            $status = json_decode((string) file_get_contents($source.'/release-status.json'), true, 12, JSON_THROW_ON_ERROR);
            $productionStatus = ($payload['rehearsal_item_id'] ?? null) === null
                && ($status['type'] ?? null) === 'hyper-pos-addon-production'
                && ($status['entry'] ?? null) === 'fresh_installer_and_plugin_manager'
                && ($status['customer_acceptance_ready'] ?? null) === (! in_array($status['version'] ?? 1, [2, 3], true))
                && ($status['fresh_core_installer_handoff'] ?? null) === true
                && ($status['separate_saas_entitlement_verified'] ?? null) === (! in_array($status['version'] ?? 1, [2, 3], true));
            $rehearsalStatus = $rehearsal !== null && $rehearsal->active()
                && ($payload['rehearsal_item_id'] ?? null) === $rehearsal->itemId()
                && ($status['type'] ?? null) === 'hyper-pos-addon-preview'
                && ($status['entry'] ?? null) === 'hyper_pos_plugin_manager'
                && ($status['customer_acceptance_ready'] ?? null) === false
                && ($status['fresh_core_installer_handoff'] ?? null) === false
                && ($status['separate_saas_entitlement_verified'] ?? null) === false
                && ($status['addon_item_id'] ?? null) === null;
            if (($plugin['slug'] ?? null) !== 'hyper-pos-saas'
                || ($plugin['version'] ?? null) !== $payload['addon_version']
                || ($plugin['requires']['core'] ?? null) !== '='.$coreVersion
                || ($plugin['autoload']['psr-4']['HyperPos\\Saas\\'] ?? null) !== 'src/'
                || (! $productionStatus && ! $rehearsalStatus)
                || ($status['includes_application_migrations'] ?? null) !== false) {
                return null;
            }

            return ['source_root' => $source, 'delivery_archive' => $delivery,
                'unreleased' => ($status['version'] ?? null) === 3 && ($status['addon_item_id'] ?? null) === 'unreleased',
                'delivery_sha256' => $payload['delivery_sha256'],
                'manifest_sha256' => $payload['manifest_sha256'],
                'trusted_key_sha256' => $payload['trusted_key_sha256'],
                'addon_zip_sha256' => $payload['addon_zip_sha256'],
                'rehearsal_item_id' => $rehearsalStatus ? $rehearsal->itemId() : null];
        } catch (\Throwable) {
            return null;
        }
    }

    private function linked(string $path): bool
    {
        for ($cursor = $path; ; $cursor = dirname($cursor)) {
            if (is_link($cursor)) {
                return true;
            }
            if ($cursor === dirname($cursor)) {
                return false;
            }
        }
    }

    private function insidePublic(string $path): bool
    {
        $public = realpath(public_path());
        $resolved = realpath($path);

        return $public === false || $resolved === false
            || $resolved === $public
            || str_starts_with(strtolower(str_replace('\\', '/', $resolved)),
                strtolower(str_replace('\\', '/', $public)).'/');
    }

    private function safeName(string $name): bool
    {
        if (! preg_match('~\A[A-Za-z0-9][A-Za-z0-9_./-]*\z~D', $name)) {
            return false;
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '' || str_starts_with($part, '.')
                || in_array(strtolower($part), ['vendor', 'database', 'storage', 'tests'], true)) {
                return false;
            }
        }
        return true;
    }

    private function writableByOthers(string $path): bool
    {
        return PHP_OS_FAMILY !== 'Windows' && ((fileperms($path) & 0022) !== 0);
    }
}
