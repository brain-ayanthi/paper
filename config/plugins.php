<?php

return [
    /* Emergency safe mode. No manifest is read and no plugin code is loaded. */
    'disabled' => (bool) env('POS_PLUGINS_DISABLED', false),

    /* Compiled enabled-plugin manifest. Missing means the zero-plugin fast path. */
    'cache' => base_path('bootstrap/cache/plugins.php'),

    'directory' => base_path('plugins'),
    'public_directory' => public_path('plugins'),

    /* Plugin packages are intentionally much smaller than application updates. */
    'upload_max_kb' => 51200,
    'extract_max_entries' => 5000,
    'extract_max_bytes' => 200 * 1024 * 1024,
];
