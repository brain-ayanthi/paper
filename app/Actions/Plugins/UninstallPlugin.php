<?php

namespace App\Actions\Plugins;

use App\Models\Plugin;
use App\Plugins\PluginException;
use App\Plugins\PluginManifest;
use App\Plugins\PluginRegistry;
use Illuminate\Support\Facades\File;

class UninstallPlugin
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly SyncPluginPermissions $permissions,
    ) {}

    public function __invoke(Plugin $plugin): void
    {
        if ($plugin->is_enabled) {
            throw new PluginException('Disable the plugin before uninstalling it.');
        }

        $root = $this->registry->pluginRoot($plugin->slug);
        $public = rtrim((string) config('plugins.public_directory'), '\\/').DIRECTORY_SEPARATOR.$plugin->slug;

        if (is_file($root.DIRECTORY_SEPARATOR.'plugin.json')) {
            $manifest = PluginManifest::fromPath($root.DIRECTORY_SEPARATOR.'plugin.json', $plugin->slug);
            $this->permissions->remove($manifest);
        }

        File::deleteDirectory($public);
        File::deleteDirectory($root);
        $plugin->delete();
        $this->registry->rebuild();
    }
}
