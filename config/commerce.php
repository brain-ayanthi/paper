<?php

return [
    'connect_timeout' => (int) env('COMMERCE_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('COMMERCE_TIMEOUT', 20),
    'processing' => [
        'batch_size' => (int) env('COMMERCE_SYNC_BATCH_SIZE', 25),
        'worker_batch_size' => (int) env('COMMERCE_WORKER_BATCH_SIZE', 10),
        'scheduler_max_seconds' => (int) env('COMMERCE_SCHEDULER_MAX_SECONDS', 20),
        'immediate_after_response' => (bool) env('COMMERCE_IMMEDIATE_AFTER_RESPONSE', true),
        'immediate_batch_size' => (int) env('COMMERCE_IMMEDIATE_BATCH_SIZE', 25),
        'max_attempts' => (int) env('COMMERCE_SYNC_MAX_ATTEMPTS', 8),
        'retry_after_seconds' => (int) env('COMMERCE_SYNC_RETRY_AFTER', 30),
        'max_retry_after_seconds' => (int) env('COMMERCE_SYNC_MAX_RETRY_AFTER', 3600),
        'stale_after_minutes' => (int) env('COMMERCE_SYNC_STALE_AFTER', 10),
    ],
    'shopify' => [
        'api_version' => env('SHOPIFY_ADMIN_API_VERSION', '2026-07'),
        'token_refresh_leeway' => 120,
    ],
    'woocommerce' => [
        'api_path' => '/wp-json/wc/v3',
    ],
];
