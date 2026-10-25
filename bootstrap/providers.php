<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\HookServiceProvider;
use App\Providers\FreshSaasPreBusinessServiceProvider;
use App\Providers\PluginServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    HookServiceProvider::class,
    FreshSaasPreBusinessServiceProvider::class,
    PluginServiceProvider::class,
];
