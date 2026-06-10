<?php

return [
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'register',
        'user',
        'users',
        'auth/*',
        'forms',
        'forms/*',
        'groups',
        'groups/*',
        'conversations',
        'conversations/*',
        '*'
    ],
    
    'allowed_methods' => ['*'],
    
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('FRONTEND_ALLOWED', 'http://localhost:5173')))),
    
    'allowed_origins_patterns' => [
        '/^https?:\/\/.*\.(ngrok-free\.dev|usw3\.devtunnels\.ms)$/',
    ],
    
    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'Accept',
        'Origin',
        'ngrok-skip-browser-warning',
    ],
    
    'exposed_headers' => [],
    
    'max_age' => 3600,
    
    'supports_credentials' => true,
];