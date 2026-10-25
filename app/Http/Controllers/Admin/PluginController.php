<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Plugins\DisablePlugin;
use App\Actions\Plugins\EnablePlugin;
use App\Actions\Plugins\InstallPlugin;
use App\Actions\Plugins\UninstallPlugin;
use App\Http\Controllers\Concerns\RespondsJsonOrRedirect;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InstallPluginRequest;
use App\Models\Plugin;
use App\Plugins\PluginPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class PluginController extends Controller
{
    use RespondsJsonOrRedirect;

    public function index(Request $request): View
    {
        $this->ownerOnly($request);
        $ready = Schema::hasTable('plugins');

        return view('admin.settings.plugins', [
            'plugins' => $ready ? Plugin::query()->orderBy('name')->get() : collect(),
            'platformReady' => $ready,
            'pluginPolicy' => app(PluginPolicy::class),
        ]);
    }

    public function install(InstallPluginRequest $request, InstallPlugin $install): JsonResponse|RedirectResponse
    {
        $this->mutable($request);

        return $this->attempt($request, function () use ($request, $install) {
            $plugin = $install($request->file('plugin'));

            return __($plugin->wasRecentlyCreated ? 'plugins.flash.installed' : 'plugins.flash.updated', ['name' => $plugin->name]);
        });
    }

    public function enable(Request $request, Plugin $plugin, EnablePlugin $enable): JsonResponse|RedirectResponse
    {
        $this->mutable($request);
        $request->validate(['current_password' => ['required', 'current_password']]);

        return $this->attempt($request, function () use ($plugin, $enable) {
            $enabled = $enable($plugin);

            return __('plugins.flash.enabled', ['name' => $enabled->name]);
        });
    }

    public function disable(Request $request, Plugin $plugin, DisablePlugin $disable): JsonResponse|RedirectResponse
    {
        $this->mutable($request);

        return $this->attempt($request, function () use ($plugin, $disable) {
            $disabled = $disable($plugin);

            return __('plugins.flash.disabled', ['name' => $disabled->name]);
        });
    }

    public function destroy(Request $request, Plugin $plugin, UninstallPlugin $uninstall): JsonResponse|RedirectResponse
    {
        $this->mutable($request);
        $request->validate([
            'confirm_slug' => ['required', 'in:'.$plugin->slug],
            'current_password' => ['required', 'current_password'],
        ]);

        return $this->attempt($request, function () use ($plugin, $uninstall) {
            $name = $plugin->name;
            $uninstall($plugin);

            return __('plugins.flash.uninstalled', ['name' => $name]);
        });
    }

    private function ownerOnly(Request $request): void
    {
        abort_unless($request->user()?->is_super_admin, 403);
    }

    private function mutable(Request $request): void
    {
        $this->ownerOnly($request);
        if (pos_is_demo()) {
            abort(403, __('settings.demo.action_locked'));
        }
    }

    private function attempt(Request $request, callable $operation): JsonResponse|RedirectResponse
    {
        try {
            return $this->jsonOrStay($request, $operation(), route('admin.settings.plugins.index'), ['refresh' => '#plugins-list']);
        } catch (Throwable $e) {
            report($e);

            return $this->jsonOrError($request, __('plugins.errors.operation_failed', ['message' => $e->getMessage()]), route('admin.settings.plugins.index'));
        }
    }
}
