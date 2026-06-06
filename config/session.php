<?php

return [
    'driver'          => env('SESSION_DRIVER', 'cookie'),
    'lifetime'        => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'encrypt'         => false,
    'files'           => storage_path('framework/sessions'),
    'cookie'          => env('SESSION_COOKIE', 'ut_api_session'),
    'domain'          => env('SESSION_DOMAIN', null),
    'same_site'       => 'none',
    'secure'          => true,
];