<?php

namespace App\Actions\Plugins;

use App\Models\Plugin;
use App\Plugins\PluginException;
use App\Plugins\PluginManifest;
use App\Plugins\PluginPolicy;
use App\Support\SafeZipExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InstallPlugin
{
    public function __construct(private readonly PluginPolicy $policy) {}

    public function __invoke(UploadedFile $archive): Plugin
    {
        if (! Schema::hasTable('plugins')) {
            throw new PluginException('Plugin database tables are not available. Complete the application update first.');
        }

        $work = storage_path('app/plugin-installs/'.Str::uuid());
        $extract = $work.DIRECTORY_SEPARATOR.'extract';
        File::ensureDirectoryExists($extract);

        try {
            (new SafeZipExtractor(
                (int) config('plugins.extract_max_entries'),
                (int) config('plugins.extract_max_bytes'),
            ))->extract((string) $archive->getRealPath(), $extract);

            $root = $this->packageRoot($extract);
            $manifest = PluginManifest::fromPath($root.DIRECTORY_SEPARATOR.'plugin.json');
            $this->policy->assertAllowed($manifest);
            $manifest->assertCompatible();

            $destination = rtrim((string) config('plugins.directory'), '\\/').DIRECTORY_SEPARATOR.$manifest->slug();
            $existing = Plugin::query()->where('slug', $manifest->slug())->first();
            if ($existing?->is_enabled) {
                throw new PluginException('Disable the installed plugin before uploading a replacement package.');
            }
            if ($existing === null && file_exists($destination)) {
                throw new PluginException('A plugin directory with this slug already exists but is not registered.');
            }

            File::ensureDirectoryExists(dirname($destination));
            $backup = $work.DIRECTORY_SEPARATOR.'previous';
            if ($existing && is_dir($destination) && ! File::moveDirectory($destination, $backup)) {
                throw new PluginException('The existing plugin files could not be staged for replacement.');
            }
            if (! File::moveDirectory($root, $destination)) {
                if (is_dir($backup)) {
                    File::moveDirectory($backup, $destination);
                }
                throw new PluginException('The plugin files could not be installed.');
            }

            try {
                if ($existing) {
                    $existing->forceFill([
                        'name' => (string) $manifest->data['name'],
                        'version' => $manifest->version(),
                        'author' => (string) $manifest->data['author'],
                        'manifest' => $manifest->data,
                        'last_error' => null,
                    ])->save();

                    return $existing->fresh();
                }

                return Plugin::query()->create([
                    'slug' => $manifest->slug(),
                    'name' => (string) $manifest->data['name'],
                    'version' => $manifest->version(),
                    'author' => (string) $manifest->data['author'],
                    'is_enabled' => false,
                    'installed_at' => now(),
                    'manifest' => $manifest->data,
                ]);
            } catch (\Throwable $e) {
                File::deleteDirectory($destination);
                if (is_dir($backup)) {
                    File::moveDirectory($backup, $destination);
                }
                throw $e;
            }
        } finally {
            File::deleteDirectory($work);
        }
    }

    private function packageRoot(string $extract): string
    {
        if (is_file($extract.DIRECTORY_SEPARATOR.'plugin.json')) {
            return $extract;
        }

        $directories = array_values(array_filter(
            File::directories($extract),
            fn (string $path) => basename($path) !== '__MACOSX',
        ));
        $files = array_values(array_filter(
            File::files($extract),
            fn ($file) => $file->getFilename() !== '.DS_Store',
        ));

        if (count($directories) !== 1 || $files !== [] || ! is_file($directories[0].DIRECTORY_SEPARATOR.'plugin.json')) {
            throw new PluginException('The archive must contain plugin.json at its root or inside one top-level folder.');
        }

        return $directories[0];
    }
}
