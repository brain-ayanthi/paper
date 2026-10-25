<?php

namespace App\Plugins;

/** Temporary product policy: only the first-party Hyper-POS SaaS add-on may execute. */
final class PluginPolicy
{
    public const SAAS_SLUG = 'hyper-pos-saas';

    public const SAAS_AUTHOR = 'Hyper-POS';

    public const SAAS_PROVIDER = 'HyperPos\\Saas\\Providers\\AddonServiceProvider';

    public const SAAS_NAMESPACE = 'HyperPos\\Saas\\';

    public const SAAS_SETTINGS_ROUTE = 'saas.addon.show';

    public function assertAllowed(PluginManifest $manifest): void
    {
        $this->assertIdentity($manifest->slug(), $manifest->data);
    }

    /** Reject stale or hand-written cache entries before any plugin autoloader is registered. */
    public function assertCachedEntry(string $slug, array $entry, PluginManifest $manifest): void
    {
        $this->assertAllowed($manifest);

        if ($slug !== $manifest->slug()
            || ($entry['slug'] ?? null) !== $manifest->slug()
            || ($entry['provider'] ?? null) !== $manifest->provider()
            || ($entry['autoload'] ?? null) !== $manifest->autoload()
            || ($entry['version'] ?? null) !== $manifest->version()
            || ($entry['data'] ?? null) !== $manifest->data) {
            throw new PluginException(__('plugins.errors.cache_identity_mismatch'));
        }
    }

    public function allows(string $slug, array $data): bool
    {
        try {
            $this->assertIdentity($slug, $data);

            return true;
        } catch (PluginException) {
            return false;
        }
    }

    public function supportsSlug(string $slug): bool
    {
        return hash_equals(self::SAAS_SLUG, $slug);
    }

    private function assertIdentity(string $slug, array $data): void
    {
        if (! $this->supportsSlug($slug)
            || ($data['slug'] ?? null) !== self::SAAS_SLUG
            || ($data['author'] ?? null) !== self::SAAS_AUTHOR
            || ($data['provider'] ?? null) !== self::SAAS_PROVIDER
            || ($data['settings_route'] ?? null) !== self::SAAS_SETTINGS_ROUTE
            || ($data['autoload']['psr-4'] ?? null) !== [self::SAAS_NAMESPACE => 'src/']) {
            throw new PluginException(__('plugins.errors.saas_only'));
        }
    }
}
