<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Date;

/** Keep persistence timestamps independent from the company's display zone. */
trait UsesUtcTimestamps
{
    public function freshTimestamp()
    {
        return Date::now('UTC');
    }
}
