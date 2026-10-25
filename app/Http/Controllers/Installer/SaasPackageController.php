<?php

namespace App\Http\Controllers\Installer;

use App\Actions\Installer\WriteEnvFile;
use App\Http\Controllers\Controller;
use App\Support\InstallState;
use App\Support\InstallerModeState;
use App\Support\InstallerSaasEntry;
use App\Support\Installer\FreshSaasPublisherTrust;
use App\Support\Installer\FreshSaasRehearsalAuthorization;
use App\Support\Installer\PreparedFreshSaasSource;
use App\Support\Installer\SaasChunkUpload;
use App\Support\Installer\SignedSaasDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

final class SaasPackageController extends Controller
{
    public function show(Request $request, InstallerSaasEntry $saas)
    {
        $this->requireFresh($saas);
        if ($saas->choiceAvailable() && $saas->selected($request)) {
            return redirect()->route('install.saas.admin');
        }
        return view('installer.saas-package', ['currentStep' => 2]);
    }

    public function chunk(Request $request, InstallerSaasEntry $saas)
    {
        $this->requireFresh($saas);
        if ($saas->choiceAvailable() && $saas->selected($request)) {
            return $this->failure($request);
        }
        $data = $request->validate([
            'upload_id' => ['required', 'regex:/\A[a-f0-9]{32}\z/D'],
            'index' => ['required', 'integer', 'min:0', 'max:127'],
            'total' => ['required', 'integer', 'min:1', 'max:128'],
            'size' => ['required', 'integer', 'min:1', 'max:67108864'],
            'chunk' => ['required', 'file', 'max:512'],
        ]);
        try {
            $next = (new SaasChunkUpload(
                storage_path('app/private/saas-fresh-installer/browser-upload'),
                (string) config('app.key', ''),
            ))->append($request->session()->getId(), $data['upload_id'],
                (int) $data['index'], (int) $data['total'], (int) $data['size'],
                $request->file('chunk'));
            return response()->json(['ok' => true, 'next_index' => $next]);
        } catch (\Throwable $error) {
            report($error);
            return $this->failure($request, 'installer.saas_package.upload_failed');
        }
    }
    public function store(Request $request, InstallerSaasEntry $saas,
        SignedSaasDelivery $signature, WriteEnvFile $writeEnv)
    {
        $this->requireFresh($saas);
        if ($saas->choiceAvailable() && $saas->selected($request)) {
            return $this->success($request, route('install.saas.admin'));
        }
        $chunked = $request->filled('upload_id');
        $request->validate($chunked
            ? ['upload_id' => ['required', 'regex:/\A[a-f0-9]{32}\z/D'],
                'delivery' => ['prohibited']]
            : ['delivery' => ['required', 'file', 'mimes:zip', 'max:65536']]);
        $pin = (new FreshSaasPublisherTrust(
            (string) config('pos.fresh_saas_installer.publisher_trust_file', '')
        ))->digest();
        if ($pin === null || strlen((string) config('app.key', '')) < 32) {
            return $this->failure($request, 'installer.saas_package.trust_unavailable');
        }

        $uploadRoot = storage_path('app/private/saas-fresh-installer/browser-upload');
        $upload = null;
        $hostCode = null;
        $lock = null;
        $phase = 'upload';
        try {
            $this->privateDirectory($uploadRoot);
            if ($chunked) {
                $upload = (new SaasChunkUpload($uploadRoot, (string) config('app.key', '')))
                    ->consume($request->session()->getId(), $request->input('upload_id'));
            }
            if (is_link($uploadRoot.'/.upload.lock')) {
                throw new \RuntimeException('Linked SaaS upload lock.');
            }
            $lock = fopen($uploadRoot.'/.upload.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX)) {
                throw new \RuntimeException('SaaS upload lock unavailable.');
            }
            chmod($uploadRoot.'/.upload.lock', 0600);
            if (! $chunked) {
                $upload = $uploadRoot.'/'.bin2hex(random_bytes(16)).'.zip';
                $request->file('delivery')->move($uploadRoot, basename($upload));
                chmod($upload, 0600);
            }

            $phase = 'verify';
            $approved = $signature->verify($upload, $pin, (string) config('pos.version', ''));
            $hostCode = $uploadRoot.'/code-'.bin2hex(random_bytes(16));
            $phase = 'prepare';
            $signature->extractSignedHostCode($upload, $approved['files'], $hostCode);

            $loader = static function (string $class) use ($hostCode): void {
                $prefix = 'HyperPos\\Saas\\';
                if (! str_starts_with($class, $prefix)) return;
                $file = $hostCode.'/src/'.str_replace('\\', '/',
                    substr($class, strlen($prefix))).'.php';
                if (is_file($file)) require $file;
            };
            spl_autoload_register($loader, true, true);
            try {
                $prepared = (new \HyperPos\Saas\Packaging\PrepareFreshInstallerDelivery)
                    ->prepare($upload, base_path());
            } finally {
                spl_autoload_unregister($loader);
            }
            $phase = 'check_prepared';
            $verified = (new PreparedFreshSaasSource)->verify(
                (string) ($prepared['source_directory'] ?? ''),
                (string) config('app.key', ''),
                (string) config('pos.version', ''),
                $pin,
                FreshSaasRehearsalAuthorization::fromCoreRoot(base_path()),
            );
            if ($verified === null) {
                throw new \RuntimeException('Prepared SaaS source could not be verified.');
            }

            $identity = [];
            foreach (['delivery_sha256', 'manifest_sha256', 'trusted_key_sha256',
                'addon_zip_sha256'] as $field) {
                $identity[$field] = $verified[$field];
            }
            $sourceDigest = hash('sha256', json_encode($identity,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $phase = 'save';
            $writeEnv([
                'POS_FRESH_SAAS_INSTALLER' => 'true',
                'POS_FRESH_SAAS_SOURCE_DIRECTORY' => $prepared['source_directory'],
            ]);
            if (app()->configurationIsCached()) {
                Artisan::call('config:clear');
            }
            $state = app(InstallerModeState::class)->select(
                InstallerSaasEntry::MODE_SAAS, $sourceDigest
            );
            $request->session()->put(InstallerSaasEntry::SESSION_MODE,
                InstallerSaasEntry::MODE_SAAS);
            $request->session()->put(InstallerSaasEntry::SESSION_OPERATION,
                $state['operation_id']);

            return $this->success($request, route('install.saas.admin'));
        } catch (\Throwable $error) {
            report($error);
            $messageKey = match ($phase) {
                'verify' => match ($error->getMessage()) {
                    'Untrusted SaaS delivery signature.' => 'installer.saas_package.untrusted',
                    'Incompatible SaaS delivery.' => 'installer.saas_package.incompatible',
                    default => 'installer.saas_package.invalid',
                },
                'prepare' => $error->getMessage() === 'Fresh installer delivery is not production approved.'
                    ? 'installer.saas_package.not_approved'
                    : 'installer.saas_package.prepare_failed',
                'check_prepared' => 'installer.saas_package.prepared_source_failed',
                'save' => 'installer.saas_package.save_failed',
                default => 'installer.saas_package.upload_failed',
            };
            return $this->failure($request, $messageKey);
        } finally {
            if ($hostCode !== null) $this->removeOwnDirectory($hostCode, $uploadRoot);
            if ($upload !== null && is_file($upload)) unlink($upload);
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function requireFresh(InstallerSaasEntry $saas): void
    {
        abort_unless(config('pos.fresh_saas_installer.enabled', false)
            && ! InstallState::isLocked()
            && ! InstallState::get('db_configured', false)
            && ! InstallState::get('migrations_run', false)
            && ! InstallState::get('admin_created', false)
            && ! $saas->recoveryRequired(), 404);
    }

    private function privateDirectory(string $path): void
    {
        for ($cursor = $path; ; $cursor = dirname($cursor)) {
            if (is_link($cursor)) throw new \RuntimeException('Linked upload path.');
            if ($cursor === dirname($cursor)) break;
        }
        if (! is_dir($path) && ! mkdir($path, 0700, true)) {
            throw new \RuntimeException('Private upload directory unavailable.');
        }
        if (! is_dir($path) || (PHP_OS_FAMILY !== 'Windows'
            && (fileperms($path) & 0002) !== 0)) {
            throw new \RuntimeException('Unsafe upload directory.');
        }
    }

    private function removeOwnDirectory(string $path, string $root): void
    {
        if (! is_dir($path) || is_link($path)
            || ! str_starts_with($path, rtrim($root, '/\\').'/code-')) return;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isLink()) throw new \RuntimeException('Linked staged source.');
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }

    private function success(Request $request, string $redirect)
    {
        return $request->expectsJson()
            ? response()->json(['ok' => true, 'redirect' => $redirect])
            : redirect($redirect);
    }

    private function failure(Request $request, string $messageKey = 'installer.saas_package.invalid')
    {
        $message = __($messageKey);
        return $request->expectsJson()
            ? response()->json(['message' => $message,
                'errors' => ['delivery' => [$message]]], 422)
            : back()->withErrors(['delivery' => $message]);
    }
}
