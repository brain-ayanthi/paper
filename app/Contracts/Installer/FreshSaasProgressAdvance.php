<?php

namespace App\Contracts\Installer;

use Illuminate\Http\Request;

interface FreshSaasProgressAdvance
{
    public function advanceShared(Request $request): ?array;
}
