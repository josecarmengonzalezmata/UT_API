<?php

return [
    'ses' => [
        'key' => env('MAIL_USERNAME'),
        'secret' => env('MAIL_PASSWORD'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
];
