<?php

namespace App\Actions\Plugins;

use App\Models\Plugin;
use App\Plugins\PluginLoader;
use App\Plugins\PluginManifest;
use App\Plugins\PluginRegistry;
use Throwable;

class DisablePlugin
{
    public function __construct(
        private readonly PluginLoader $loader,
        private readonly PluginRegistry $registry,
    ) {}

    public function __invoke(Plugin $plugin): Plugin
    {
        $error = null;
        try {
            $manifest = PluginManifest::fromPath(
                $this->registry->pluginRoot($plugin->slug).DIRECTORY_SEPARATOR.'plugin.json',
                $plugin->slug,
            );
            $this->loader->callLifecycle($manifest, 'onDisable');
        } catch (Throwable $e) {
            report($e);
            $error = $e->getMessage();
        } finally {
            $plugin->forceFill(['is_enabled' => false, 'last_error' => $error])->save();
            $this->registry->rebuild();
        }

        return $plugin->fresh();
    }
}
