<?php

namespace App\Providers;

use App\Actions\Plugins\RevalidatePlugins;
use App\Models\Plugin;
use App\Plugins\PluginLoader;
use App\Plugins\PluginRegistry;
use App\Plugins\VersionConstraint;
use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginRegistry::class);
        $this->app->singleton(PluginLoader::class);
        $this->app->make(PluginLoader::class)->register();
    }

    public function boot(PluginLoader $loader): void
    {
        $loader->boot();

        add_action('updater.post_update_hooks', function (string $version): void {
            app(RevalidatePlugins::class)($version);
        });

        add_filter('updater.preflight_checks', function (array $checks, array $release): array {
            $target = (string) ($release['version'] ?? '');
            if ($target === '' || ! is_file((string) config('plugins.cache'))) {
                return $checks;
            }

            foreach (Plugin::query()->where('is_enabled', true)->get() as $plugin) {
                $constraint = (string) data_get($plugin->manifest, 'requires.core', '');
                $passed = $constraint !== '' && VersionConstraint::matches($target, $constraint);
                $checks[] = [
                    'key' => 'plugin_'.$plugin->slug,
                    'label' => __('plugins.preflight.label', ['name' => $plugin->name]),
                    'passed' => $passed,
                    'message' => $passed ? null : __('plugins.preflight.incompatible', [
                        'name' => $plugin->name,
                        'version' => $target,
                        'constraint' => $constraint ?: '—',
                    ]),
                    'required' => true,
                ];
            }

            return $checks;
        });
    }
}
