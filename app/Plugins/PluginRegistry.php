<?php

namespace App\Plugins;

use App\Models\Plugin;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PluginRegistry
{
    public function __construct(private readonly PluginPolicy $policy) {}

    /** @return array<string, array<string, mixed>> */
    public function enabledManifest(): array
    {
        $cache = (string) config('plugins.cache');

        if (config('plugins.disabled') || ! is_file($cache)) {
            return [];
        }

        try {
            $manifest = require $cache;

            return is_array($manifest) ? $manifest : [];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Rebuild the fast-path cache. This is called only by lifecycle operations. */
    public function rebuild(): void
    {
        if (! $this->tableExists()) {
            $this->write([]);

            return;
        }

        $compiled = [];

        foreach (Plugin::query()->where('is_enabled', true)->orderBy('slug')->get() as $plugin) {
            try {
                $manifest = PluginManifest::fromPath(
                    $this->pluginRoot($plugin->slug).DIRECTORY_SEPARATOR.'plugin.json',
                    $plugin->slug,
                );
                $this->policy->assertAllowed($manifest);
                $manifest->assertCompatible();
                $compiled[$plugin->slug] = $this->compile($manifest);
                $plugin->forceFill(['manifest' => $manifest->data, 'version' => $manifest->version(), 'last_error' => null])->save();
            } catch (Throwable $e) {
                $plugin->forceFill(['is_enabled' => false, 'last_error' => $e->getMessage()])->save();
                report($e);
            }
        }

        $this->write($compiled);
    }

    public function disable(string $slug, Throwable|string $reason): void
    {
        try {
            if ($this->tableExists()) {
                Plugin::query()->where('slug', $slug)->update([
                    'is_enabled' => false,
                    'last_error' => $reason instanceof Throwable ? $reason->getMessage() : $reason,
                    'updated_at' => now(),
                ]);
                $this->rebuild();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function pluginRoot(string $slug): string
    {
        return rtrim((string) config('plugins.directory'), '\\/').DIRECTORY_SEPARATOR.$slug;
    }

    /** @return array<string, mixed> */
    private function compile(PluginManifest $manifest): array
    {
        return [
            'slug' => $manifest->slug(),
            'path' => 'plugins/'.$manifest->slug(),
            'provider' => $manifest->provider(),
            'autoload' => $manifest->autoload(),
            'version' => $manifest->version(),
            'data' => $manifest->data,
        ];
    }

    /** @param array<string, array<string, mixed>> $manifest */
    private function write(array $manifest): void
    {
        $cache = (string) config('plugins.cache');

        if ($manifest === []) {
            if (is_file($cache)) {
                @unlink($cache);
            }

            return;
        }

        File::ensureDirectoryExists(dirname($cache));
        $temporary = $cache.'.'.bin2hex(random_bytes(6)).'.tmp';
        $contents = "<?php\n\nreturn ".var_export($manifest, true).";\n";

        if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! @rename($temporary, $cache)) {
            @unlink($temporary);
            throw new PluginException('Could not write the enabled-plugin cache.');
        }
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('plugins');
        } catch (Throwable) {
            return false;
        }
    }
}
