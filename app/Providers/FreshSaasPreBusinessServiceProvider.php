<?php

namespace App\Providers;

use App\Support\Installer\FreshSaasPublisherTrust;
use App\Support\Installer\FreshSaasRehearsalAuthorization;
use App\Support\Installer\PreparedFreshSaasSource;
use App\Support\InstallerModeState;
use App\Support\InstallState;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\ServiceProvider;

/** Loads add-on PHP only after a trusted host seal and every file hash pass. */
final class FreshSaasPreBusinessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $mode = (new InstallerModeState)->read(null);
        $modeChoiceRequest = $this->app->bound('request')
            && $this->app->make('request')->is('install/mode');
        if (($mode['status'] ?? null) === 'valid' && ($mode['mode'] ?? null) === 'standard'
            && ! $modeChoiceRequest) {
            return;
        }

        if (! config('pos.fresh_saas_installer.enabled', false)
            || InstallState::get('db_configured', false)
            || InstallState::get('migrations_run', false)
            || InstallState::get('admin_created', false)) {
            return;
        }
        $trustedKeyDigest = (new FreshSaasPublisherTrust(
            (string) config('pos.fresh_saas_installer.publisher_trust_file', ''),
        ))->digest();
        if ($trustedKeyDigest === null) {
            return;
        }

        $verified = (new PreparedFreshSaasSource)->verify(
            (string) config('pos.fresh_saas_installer.source_directory', ''),
            (string) config('app.key', ''),
            (string) config('pos.version', ''),
            $trustedKeyDigest,
            FreshSaasRehearsalAuthorization::fromCoreRoot(base_path()),
        );
        if ($verified === null) {
            return;
        }
        $loaders = ClassLoader::getRegisteredLoaders();
        $loader = reset($loaders);
        if (! $loader instanceof ClassLoader) {
            return;
        }
        $loader->addPsr4('HyperPos\\Saas\\', $verified['source_root'].'/src', true);
        $provider = 'HyperPos\\Saas\\Providers\\FreshInstallerServiceProvider';
        if (class_exists($provider) && is_subclass_of($provider, ServiceProvider::class)) {
            $this->app->instance('saas.fresh.prepared_source', $verified);
            $this->app->register($provider);
        }
    }
}
