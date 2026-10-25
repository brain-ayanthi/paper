<?php

namespace App\Actions\Plugins;

use App\Models\Plugin;
use App\Plugins\PluginException;
use App\Plugins\PluginLoader;
use App\Plugins\PluginManifest;
use App\Plugins\PluginPolicy;
use App\Plugins\PluginRegistry;
use Throwable;

class EnablePlugin
{
    public function __construct(
        private readonly RunPluginMigrations $migrations,
        private readonly PublishPluginAssets $assets,
        private readonly SyncPluginPermissions $permissions,
        private readonly PluginLoader $loader,
        private readonly PluginRegistry $registry,
        private readonly PluginPolicy $policy,
    ) {}

    public function __invoke(Plugin $plugin): Plugin
    {
        $manifest = PluginManifest::fromPath(
            $this->registry->pluginRoot($plugin->slug).DIRECTORY_SEPARATOR.'plugin.json',
            $plugin->slug,
        );
        $this->policy->assertAllowed($manifest);
        $manifest->assertCompatible();
        $this->assertNoConflict($plugin, $manifest);

        try {
            ($this->migrations)($manifest);
            ($this->assets)($manifest);
            ($this->permissions)($manifest);
            $this->loader->callLifecycle($manifest, 'onEnable');

            $plugin->forceFill([
                'name' => (string) $manifest->data['name'],
                'version' => $manifest->version(),
                'author' => (string) $manifest->data['author'],
                'manifest' => $manifest->data,
                'is_enabled' => true,
                'last_activated_at' => now(),
                'last_error' => null,
            ])->save();
            $this->registry->rebuild();

            return $plugin->fresh();
        } catch (Throwable $e) {
            $plugin->forceFill(['is_enabled' => false, 'last_error' => $e->getMessage()])->save();
            $this->registry->rebuild();
            throw $e;
        }
    }

    private function assertNoConflict(Plugin $plugin, PluginManifest $manifest): void
    {
        $enabled = Plugin::query()->where('is_enabled', true)->whereKeyNot($plugin->getKey())->get();

        foreach ($enabled as $other) {
            $otherManifest = (array) $other->manifest;
            $otherConflicts = (array) ($otherManifest['conflicts'] ?? []);
            if (in_array($other->slug, $manifest->conflicts(), true) || in_array($manifest->slug(), $otherConflicts, true)) {
                throw new PluginException("Plugin conflicts with enabled plugin [{$other->slug}].");
            }
        }
    }
}
