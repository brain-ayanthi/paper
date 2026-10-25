<?php

use App\Http\Controllers\Installer\AdminController;
use App\Http\Controllers\Installer\DatabaseController;
use App\Http\Controllers\Installer\DemoController;
use App\Http\Controllers\Installer\LicenseController;
use App\Http\Controllers\Installer\ModeController;
use App\Http\Controllers\Installer\SaasPurchaseController;
use App\Http\Controllers\Installer\SaasHostingController;
use App\Http\Controllers\Installer\SaasAdminController;
use App\Http\Controllers\Installer\SaasPackageController;
use App\Http\Controllers\Installer\SaasSharedHostingController;
use App\Http\Controllers\Installer\SaasVpsController;
use App\Http\Controllers\Installer\SaasOwnerController;
use App\Http\Controllers\Installer\SaasProgressController;
use App\Http\Controllers\Installer\RequirementsController;
use App\Http\Controllers\Installer\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::prefix('install')
    ->middleware(['installer.errors', 'restrict.during.install', 'no.cache'])
    ->group(function () {
        Route::get('/', [WelcomeController::class, 'show'])->name('install.welcome');
        Route::post('/language', [WelcomeController::class, 'setLanguage'])->name('install.language');
        Route::get('/mode', [ModeController::class, 'show'])->name('install.mode');
        Route::post('/mode', [ModeController::class, 'choose'])->name('install.mode.choose');
        Route::get('/saas-package', [SaasPackageController::class, 'show'])->name('install.saas.package');
        Route::post('/saas-package/chunk', [SaasPackageController::class, 'chunk'])
            ->middleware('throttle:180,1,saas-package-chunk:')->name('install.saas.package.chunk');
        Route::post('/saas-package', [SaasPackageController::class, 'store'])
            ->middleware('throttle:3,1,saas-package-store:')->name('install.saas.package.store');
        Route::get('/saas-admin', [SaasAdminController::class, 'show'])->name('install.saas.admin');
        Route::post('/saas-admin', [SaasAdminController::class, 'save'])
            ->middleware('throttle:5,1')->name('install.saas.admin.save');
        Route::get('/saas-hosting', [SaasHostingController::class, 'show'])->name('install.saas.hosting');
        Route::post('/saas-hosting', [SaasHostingController::class, 'choose'])->name('install.saas.hosting.choose');
        Route::get('/saas-hosting/shared', [SaasSharedHostingController::class, 'show'])->name('install.saas.shared-hosting');
        Route::post('/saas-hosting/shared', [SaasSharedHostingController::class, 'store'])->middleware('throttle:5,1')->name('install.saas.shared-hosting.store');
        Route::get('/saas-hosting/vps', [SaasVpsController::class, 'show'])->name('install.saas.vps');
        Route::post('/saas-hosting/vps', [SaasVpsController::class, 'store'])->middleware('throttle:5,1')->name('install.saas.vps.store');

        Route::get('/requirements', [RequirementsController::class, 'show'])
            ->middleware('installer.standard:handoff')->name('install.requirements');

        Route::get('/license', [LicenseController::class, 'show'])->name('install.license');
        Route::post('/license', [LicenseController::class, 'validateLicense'])->name('install.license.validate');
        Route::post('/license/core-launch-bypass', [LicenseController::class, 'bypassCorePurchase'])
            ->middleware('throttle:5,1')
            ->name('install.license.core-launch-bypass');

        Route::get('/saas-purchase', [SaasPurchaseController::class, 'show'])->name('install.saas.purchase');
        Route::post('/saas-purchase', [SaasPurchaseController::class, 'submit'])
            ->middleware('throttle:5,1')
            ->name('install.saas.purchase.submit');

        Route::get('/saas-owner', [SaasOwnerController::class, 'show'])->name('install.saas.owner');
        Route::post('/saas-owner/request', [SaasOwnerController::class, 'requestVerification'])
            ->middleware('throttle:3,1')->name('install.saas.owner.request');
        Route::post('/saas-owner/confirm', [SaasOwnerController::class, 'confirm'])
            ->middleware('throttle:5,1')->name('install.saas.owner.confirm');
        Route::post('/saas-owner/handoff', [SaasOwnerController::class, 'handoff'])
            ->middleware('throttle:3,1')->name('install.saas.owner.handoff');
        Route::get('/saas-progress', [SaasProgressController::class, 'show'])->name('install.saas.progress');
        Route::get('/saas-progress/status', [SaasProgressController::class, 'status'])
            ->middleware('throttle:30,1')->name('install.saas.progress.status');
        Route::post('/saas-progress/advance', [SaasProgressController::class, 'advance'])
            ->middleware('throttle:20,1')->name('install.saas.progress.advance');

        Route::middleware('installer.standard')->group(function () {
            Route::get('/database', [DatabaseController::class, 'show'])->name('install.database');
            Route::post('/database', [DatabaseController::class, 'save'])->name('install.database.save');
            Route::get('/database/migrate', [DatabaseController::class, 'migrate'])->name('install.database.migrate');
            Route::post('/database/migrate/step', [DatabaseController::class, 'runStep'])->name('install.database.migrate.step');
        });

        // Milestone guards (SEC #22). These pages cannot be authenticated —
        // there is no user until the admin step — so the ordering IS the
        // control. Without it anyone reaching a half-installed site could POST
        // /install/complete, which writes the install lock and permanently
        // 404s the wizard: an install with no admin account and no way to make
        // one, unrecoverable from the UI.
        Route::get('/admin', [AdminController::class, 'show'])
            ->middleware(['installer.standard', 'installer.step:db_configured,migrations_run'])
            ->name('install.admin');
        Route::post('/admin', [AdminController::class, 'save'])
            ->middleware(['installer.standard', 'installer.step:db_configured,migrations_run'])
            ->name('install.admin.save');

        Route::get('/demo', [DemoController::class, 'show'])
            ->middleware(['installer.standard', 'installer.step:db_configured,migrations_run,admin_created'])
            ->name('install.demo');
        Route::post('/complete', [DemoController::class, 'complete'])
            ->middleware(['installer.standard', 'installer.step:db_configured,migrations_run,admin_created'])
            ->name('install.complete');
    });
