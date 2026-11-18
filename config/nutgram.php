<?php

return [
    'token' => env('TELEGRAM_TOKEN'),
    'safe_mode' => env('APP_ENV', 'production') === 'production',
    'config' => [
        'timeout' => 30,
        'connect_timeout' => 15,
        'client' => [
            'timeout' => 30,
            'connect_timeout' => 15,
            'force_ip_resolve' => 'v4',
        ],
    ],
    'routes' => true,
    'mixins' => false,
    'namespace' => App\Telegram::class,
];
