<?php

$frontendUrl  = env('FRONTEND_URL', '*');
$chatUrl      = env('CHAT_URL', '');
$origins      = array_filter(array_map('trim', explode(',', $frontendUrl . ($chatUrl ? ',' . $chatUrl : ''))));
$allowedOrigins = (count($origins) === 1 && $origins[0] === '*') ? ['*'] : array_values($origins);

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins ?: ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 86400,
    'supports_credentials' => false,
];