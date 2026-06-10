<?php

$frontendUrl = env('FRONTEND_URL', '*');
$allowedOrigins = $frontendUrl === '*' ? ['*'] : array_filter(array_map('trim', explode(',', $frontendUrl)));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 86400,
    'supports_credentials' => false,
];