<?php

namespace App\Actions\Plugins;

use App\Models\Plugin;
use App\Plugins\PluginManifest;
use App\Plugins\PluginPolicy;
use App\Plugins\PluginRegistry;
use Throwable;

class RevalidatePlugins
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginPolicy $policy,
    ) {}

    /** @return list<string> disabled plugin slugs */
    public function __invoke(?string $coreVersion = null): array
    {
        $disabled = [];

        foreach (Plugin::query()->where('is_enabled', true)->get() as $plugin) {
            try {
                $manifest = PluginManifest::fromPath(
                    $this->registry->pluginRoot($plugin->slug).DIRECTORY_SEPARATOR.'plugin.json',
                    $plugin->slug,
                );
                $this->policy->assertAllowed($manifest);
                $manifest->assertCompatible();
            } catch (Throwable $e) {
                $plugin->forceFill(['is_enabled' => false, 'last_error' => $e->getMessage()])->save();
                $disabled[] = $plugin->slug;
                report($e);
            }
        }

        $this->registry->rebuild();

        if ($disabled !== []) {
            notify_admins(
                'plugins_incompatible:'.implode(',', $disabled),
                __('plugins.notifications.incompatible_title'),
                __('plugins.notifications.incompatible_message', ['plugins' => implode(', ', $disabled)]),
                'warning',
                route('admin.settings.plugins.index'),
            );
        }

        return $disabled;
    }
}
