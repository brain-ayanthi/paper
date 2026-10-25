<?php

namespace App\Http\Controllers\Installer;

use App\Http\Controllers\Controller;
use App\Support\InstallState;
use App\Support\InstallerSaasEntry;
use Illuminate\Http\Request;

class WelcomeController extends Controller
{
    public function show()
    {
        return view('installer.welcome', [
            'currentStep' => 1,
            'languages' => $this->supportedLanguages(),
            'selectedLanguage' => InstallState::get('language', 'en'),
        ]);
    }

    public function setLanguage(Request $request, InstallerSaasEntry $saas)
    {
        $data = $request->validate([
            'language' => ['required', 'string', 'in:'.implode(',', array_keys($this->supportedLanguages()))],
        ]);

        InstallState::set('language', $data['language']);
        app()->setLocale($data['language']);

        $redirect = route(config('pos.fresh_saas_installer.enabled', false)
            && ! InstallState::get('db_configured', false)
            && ! InstallState::get('migrations_run', false)
            && ! InstallState::get('admin_created', false)
                ? 'install.mode' : 'install.requirements');

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'redirect' => $redirect]);
        }

        return redirect($redirect);
    }

    /** @return array<string, string> */
    private function supportedLanguages(): array
    {
        return [
            'en' => 'English',
            'ar' => 'العربية',
            'hi' => 'हिन्दी',
            'es' => 'Español',
            'fr' => 'Français',
        ];
    }
}
