<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    // comma-separated; dev serves on several host:port combos, prod on one domain
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',',
        (string) env('FRONTEND_URLS', (string) env('FRONTEND_URL', 'http://localhost:4200')))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Auth-Token', 'Accept'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
