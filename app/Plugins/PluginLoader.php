<?php

namespace App\Plugins;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class PluginLoader
{
    /** @var array<string, ServiceProvider> */
    private array $providers = [];

    /** @var array<string, array<string, mixed>> */
    private array $entries = [];

    public function __construct(
        private readonly Application $app,
        private readonly PluginRegistry $registry,
        private readonly PluginPolicy $policy,
    ) {}

    public function register(): void
    {
        if (config('plugins.disabled')) {
            return;
        }

        foreach ($this->registry->enabledManifest() as $slug => $entry) {
            try {
                $this->registerEntry((string) $slug, (array) $entry);
            } catch (Throwable $e) {
                $this->failed((string) $slug, $e);
            }
        }
    }

    public function boot(): void
    {
        foreach ($this->providers as $slug => $provider) {
            try {
                $this->app->instance('plugins.current', $slug);
                $this->registerResources($slug, $this->entries[$slug]);
                $this->app->call([$provider, 'boot']);
                if (method_exists($provider, 'schedule')) {
                    add_action('scheduler.register', fn ($schedule) => $this->app->call([$provider, 'schedule'], ['schedule' => $schedule]));
                }
                do_action('plugin.loaded', $slug, $this->entries[$slug]['data'] ?? []);
            } catch (Throwable $e) {
                $this->failed($slug, $e);
            } finally {
                $this->app->forgetInstance('plugins.current');
            }
        }
    }

    public function callLifecycle(PluginManifest $manifest, string $method): void
    {
        $this->policy->assertAllowed($manifest);
        $entry = [
            'slug' => $manifest->slug(),
            'path' => 'plugins/'.$manifest->slug(),
            'provider' => $manifest->provider(),
            'autoload' => $manifest->autoload(),
            'data' => $manifest->data,
        ];
        $provider = $this->makeProvider($manifest->slug(), $entry);

        $this->app->instance('plugins.current', $manifest->slug());
        try {
            $provider->register();
            $this->registerResources($manifest->slug(), $entry);
            $this->app->call([$provider, 'boot']);
            if (method_exists($provider, $method)) {
                $this->app->call([$provider, $method]);
            }
            $this->entries[$manifest->slug()] = $entry;
        } finally {
            $this->app->forgetInstance('plugins.current');
        }
    }

    /** @return list<array<string, mixed>> */
    public function languageNamespaces(): array
    {
        $namespaces = [];
        foreach ($this->entries as $slug => $entry) {
            $path = $this->root($entry).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'lang';
            if (is_dir($path)) {
                $namespaces[] = ['namespace' => $slug, 'path' => $path];
            }
        }

        return $namespaces;
    }

    public function hasLanguageNamespace(string $namespace): bool
    {
        return array_key_exists($namespace, $this->entries)
            && is_dir($this->root($this->entries[$namespace]).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'lang');
    }

    /** @param array<string, mixed> $entry */
    private function registerEntry(string $slug, array $entry): void
    {
        $root = $this->root($entry);
        $manifest = PluginManifest::fromPath($root.DIRECTORY_SEPARATOR.'plugin.json', $slug);
        $this->policy->assertCachedEntry($slug, $entry, $manifest);
        $provider = $this->makeProvider($slug, $entry);
        $this->app->instance('plugins.current', $slug);

        try {
            $this->mergeConfigs($slug, $this->root($entry));
            $provider->register();
            $this->providers[$slug] = $provider;
            $this->entries[$slug] = $entry;
        } finally {
            $this->app->forgetInstance('plugins.current');
        }
    }

    /** @param array<string, mixed> $entry */
    private function makeProvider(string $slug, array $entry): ServiceProvider
    {
        $root = $this->root($entry);
        $this->registerAutoload($root, (array) ($entry['autoload'] ?? []));
        $providerClass = (string) ($entry['provider'] ?? '');

        if ($providerClass === '' || ! class_exists($providerClass)) {
            throw new PluginException("Plugin [{$slug}] provider could not be loaded.");
        }
        if (! is_subclass_of($providerClass, ServiceProvider::class)) {
            throw new PluginException("Plugin [{$slug}] provider must extend Laravel ServiceProvider.");
        }

        return new $providerClass($this->app);
    }

    /** @param array<string, string> $map */
    private function registerAutoload(string $root, array $map): void
    {
        $vendorAutoload = $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
        $loader = is_file($vendorAutoload) ? require_once $vendorAutoload : null;

        if (! $loader instanceof ClassLoader) {
            $loaders = ClassLoader::getRegisteredLoaders();
            $loader = reset($loaders);
        }
        if (! $loader instanceof ClassLoader) {
            throw new PluginException('Composer autoloader is unavailable.');
        }

        foreach ($map as $namespace => $relative) {
            $path = $root.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim((string) $relative, '/\\'));
            if (! is_dir($path)) {
                throw new PluginException("Plugin autoload path [{$relative}] does not exist.");
            }
            $loader->addPsr4((string) $namespace, $path, true);
        }
    }

    /** @param array<string, mixed> $entry */
    private function registerResources(string $slug, array $entry): void
    {
        $root = $this->root($entry);
        $views = $root.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';
        $lang = $root.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'lang';
        $migrations = $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        $routes = $root.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php';
        $consoleRoutes = $root.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'console.php';

        if (is_dir($views)) {
            View::addNamespace($slug, $views);
        }
        if (is_dir($lang)) {
            Lang::addNamespace($slug, $lang);
        }
        if (is_dir($migrations)) {
            app('migrator')->path($migrations);
        }
        if (is_file($routes)) {
            Route::middleware('web')->group($routes);
        }
        if ($this->app->runningInConsole() && is_file($consoleRoutes)) {
            require $consoleRoutes;
        }

        $middleware = (array) data_get($entry, 'data.middleware.web', []);
        $router = app(Router::class);
        foreach (array_reverse((array) ($middleware['prepend'] ?? [])) as $class) {
            if (is_string($class)) {
                $router->prependMiddlewareToGroup('web', $class);
            }
        }
        foreach ((array) ($middleware['append'] ?? []) as $class) {
            if (is_string($class)) {
                $router->pushMiddlewareToGroup('web', $class);
            }
        }
    }

    private function mergeConfigs(string $slug, string $root): void
    {
        $directory = $root.DIRECTORY_SEPARATOR.'config';
        if (! is_dir($directory)) {
            return;
        }

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $defaults = require $file->getPathname();
            if (! is_array($defaults)) {
                continue;
            }
            $key = 'plugin.'.$slug.'.'.$file->getFilenameWithoutExtension();
            Config::set($key, array_replace_recursive($defaults, (array) Config::get($key, [])));
        }
    }

    /** @param array<string, mixed> $entry */
    private function root(array $entry): string
    {
        $relative = str_replace('\\', '/', (string) ($entry['path'] ?? ''));
        if (! str_starts_with($relative, 'plugins/') || in_array('..', explode('/', $relative), true)) {
            throw new PluginException('Compiled plugin path is unsafe.');
        }
        $slug = (string) ($entry['slug'] ?? basename($relative));
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,49}$/', $slug) !== 1) {
            throw new PluginException('Compiled plugin slug is unsafe.');
        }
        $base = rtrim((string) config('plugins.directory'), '\\/');
        $root = $base.DIRECTORY_SEPARATOR.$slug;
        if (! is_dir($root)) {
            throw new PluginException('Plugin directory is missing.');
        }

        $resolvedBase = realpath($base);
        $resolvedRoot = realpath($root);
        if ($resolvedBase === false || $resolvedRoot === false
            || ($resolvedRoot !== $resolvedBase && ! str_starts_with($resolvedRoot.DIRECTORY_SEPARATOR, $resolvedBase.DIRECTORY_SEPARATOR))) {
            throw new PluginException('Plugin directory resolves outside the configured plugin root.');
        }

        return $resolvedRoot;
    }

    private function failed(string $slug, Throwable $e): void
    {
        report($e);
        Log::error('Plugin was disabled after a boot failure.', [
            'plugin' => $slug,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
        unset($this->providers[$slug], $this->entries[$slug]);
        $this->registry->disable($slug, $e);
    }
}
