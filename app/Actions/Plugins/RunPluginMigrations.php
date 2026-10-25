<?php

namespace App\Actions\Plugins;

use App\Plugins\PluginManifest;

class RunPluginMigrations
{
    public function __invoke(PluginManifest $manifest): void
    {
        $path = $manifest->root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        if (! is_dir($path)) {
            return;
        }

        app('migrator')->run([$path], ['pretend' => false, 'step' => true]);
    }
}
