<?php

namespace App\Plugins;

use Illuminate\Support\Str;
use JsonException;

final class PluginManifest
{
    private function __construct(
        public readonly string $root,
        public readonly array $data,
    ) {}

    public static function fromPath(string $path, ?string $expectedSlug = null): self
    {
        if (! is_file($path)) {
            throw new PluginException('plugin.json is missing.');
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PluginException('plugin.json is not valid JSON.', previous: $e);
        }

        if (! is_array($data)) {
            throw new PluginException('plugin.json must contain an object.');
        }

        foreach (['slug', 'name', 'version', 'author', 'provider', 'autoload'] as $field) {
            if (! isset($data[$field]) || $data[$field] === '' || $data[$field] === []) {
                throw new PluginException("plugin.json field [{$field}] is required.");
            }
        }

        $slug = (string) $data['slug'];
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,49}$/', $slug) !== 1 || Str::startsWith($slug, '_')) {
            throw new PluginException('Plugin slug must contain 2-50 lowercase letters, numbers, or hyphens.');
        }
        if ($expectedSlug !== null && $slug !== $expectedSlug) {
            throw new PluginException('Plugin slug does not match its directory name.');
        }
        if (preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', (string) $data['version']) !== 1) {
            throw new PluginException('Plugin version is invalid.');
        }
        if (! is_string($data['provider']) || ! str_contains($data['provider'], '\\')) {
            throw new PluginException('Plugin provider must be a fully-qualified class name.');
        }

        $psr4 = $data['autoload']['psr-4'] ?? null;
        if (! is_array($psr4) || $psr4 === []) {
            throw new PluginException('Plugin must declare at least one PSR-4 namespace.');
        }
        foreach ($psr4 as $namespace => $relativePath) {
            if (! is_string($namespace) || ! str_ends_with($namespace, '\\') || ! self::safeRelativePath($relativePath)) {
                throw new PluginException('Plugin PSR-4 autoload map is invalid.');
            }
        }

        $requires = $data['requires'] ?? [];
        if (! is_array($requires) || empty($requires['core'])) {
            throw new PluginException('Plugin must declare requires.core.');
        }

        foreach (['capabilities', 'conflicts'] as $list) {
            if (isset($data[$list]) && (! is_array($data[$list]) || array_filter($data[$list], 'is_string') !== $data[$list])) {
                throw new PluginException("Plugin field [{$list}] must be a list of strings.");
            }
        }

        if (isset($data['settings_route']) && ! is_string($data['settings_route'])) {
            throw new PluginException('Plugin settings_route must be a route name.');
        }
        if (isset($data['permissions']) && ! is_array($data['permissions'])) {
            throw new PluginException('Plugin permissions must be a list.');
        }
        if (isset($data['middleware']['web'])) {
            $web = $data['middleware']['web'];
            if (! is_array($web)) {
                throw new PluginException('Plugin web middleware declaration is invalid.');
            }
            foreach (['prepend', 'append'] as $position) {
                $classes = $web[$position] ?? [];
                if (! is_array($classes) || array_filter($classes, 'is_string') !== $classes) {
                    throw new PluginException("Plugin middleware.{$position} must be a list of class names.");
                }
            }
        }

        return new self(dirname($path), $data);
    }

    public function slug(): string
    {
        return (string) $this->data['slug'];
    }

    public function provider(): string
    {
        return (string) $this->data['provider'];
    }

    public function version(): string
    {
        return (string) $this->data['version'];
    }

    public function capabilities(): array
    {
        return array_values($this->data['capabilities'] ?? []);
    }

    public function conflicts(): array
    {
        return array_values($this->data['conflicts'] ?? []);
    }

    public function autoload(): array
    {
        return $this->data['autoload']['psr-4'];
    }

    public function assertCompatible(): void
    {
        $core = (string) config('pos.version', '0.0.0');
        $coreConstraint = (string) ($this->data['requires']['core'] ?? '');
        if (! VersionConstraint::matches($core, $coreConstraint)) {
            throw new PluginException("Plugin requires core {$coreConstraint}; this installation is {$core}.");
        }

        $phpConstraint = (string) ($this->data['requires']['php'] ?? '*');
        if (! VersionConstraint::matches(PHP_VERSION, $phpConstraint)) {
            throw new PluginException("Plugin requires PHP {$phpConstraint}; this server runs ".PHP_VERSION.'.');
        }
    }

    private static function safeRelativePath(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
            return false;
        }

        $path = str_replace('\\', '/', $path);

        return ! str_starts_with($path, '/')
            && preg_match('/^[A-Za-z]:/', $path) !== 1
            && ! in_array('..', explode('/', $path), true);
    }
}
