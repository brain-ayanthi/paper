<?php

namespace App\Actions\Plugins;

use App\Models\Permission;
use App\Models\User;
use App\Plugins\PluginException;
use App\Plugins\PluginManifest;

class SyncPluginPermissions
{
    /** @return list<string> */
    public function __invoke(PluginManifest $manifest): array
    {
        $keys = [];
        foreach ((array) ($manifest->data['permissions'] ?? []) as $permission) {
            if (! is_array($permission) || empty($permission['key']) || empty($permission['label'])) {
                throw new PluginException('Every plugin permission requires a key and label.');
            }

            $key = (string) $permission['key'];
            if (! str_starts_with($key, $manifest->slug().'.') || preg_match('/^[a-z0-9._-]+$/', $key) !== 1) {
                throw new PluginException("Plugin permission [{$key}] must be prefixed with [{$manifest->slug}.].");
            }

            Permission::query()->updateOrCreate(['key' => $key], [
                'label' => (string) $permission['label'],
                'group' => (string) ($permission['group'] ?? 'Plugin: '.$manifest->data['name']),
                'description' => isset($permission['description']) ? (string) $permission['description'] : null,
                'is_dangerous' => (bool) ($permission['is_dangerous'] ?? false),
            ]);
            $keys[] = $key;
        }

        if ($keys !== []) {
            User::bumpPermissionsVersion();
        }

        return $keys;
    }

    public function remove(PluginManifest $manifest): void
    {
        $keys = collect((array) ($manifest->data['permissions'] ?? []))
            ->pluck('key')
            ->filter(fn ($key) => is_string($key) && str_starts_with($key, $manifest->slug().'.'))
            ->values()
            ->all();

        if ($keys !== []) {
            Permission::query()->whereIn('key', $keys)->delete();
            User::bumpPermissionsVersion();
        }
    }
}
