<?php

namespace App\Actions\Plugins;

use App\Plugins\PluginException;
use App\Plugins\PluginManifest;
use Illuminate\Support\Facades\File;

class PublishPluginAssets
{
    public function __invoke(PluginManifest $manifest): void
    {
        $source = $manifest->root.DIRECTORY_SEPARATOR.'public';
        if (! is_dir($source)) {
            return;
        }

        $destination = rtrim((string) config('plugins.public_directory'), '\\/').DIRECTORY_SEPARATOR.$manifest->slug();
        File::deleteDirectory($destination);
        File::ensureDirectoryExists($destination);

        if (! File::copyDirectory($source, $destination)) {
            File::deleteDirectory($destination);
            throw new PluginException('Plugin public assets could not be published.');
        }
    }
}
