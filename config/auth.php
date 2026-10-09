<?php

use App\Models\Admin;
use App\Models\User;

return [

    /*
    | Two audiences:
    |  - "admin":   dashboard staff, cookie session + CSRF, password login.
    |  - "miniapp": Telegram players, bearer token issued after Telegram
    |              init-data validation (see App\Services\MiniAppAuth).
    */

    'defaults' => [
        'guard' => 'admin',
        'passwords' => 'admins',
    ],

    'guards' => [
        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
        'web' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
        'miniapp' => [
            'driver' => 'miniapp-token',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'admins' => [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ],
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

    'passwords' => [
        'admins' => [
            'provider' => 'admins',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
