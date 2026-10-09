<?php

/*
|--------------------------------------------------------------------------
| Static / secret platform configuration
|--------------------------------------------------------------------------
|
| Everything in this file comes from the environment (.env) and is NOT
| editable from the admin dashboard. Credentials (bot token, webhook secret,
| cron token, database) must live here, never in the `settings` table.
| Business rules that administrators may change live in config/settings.php.
|
*/

return [

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => ltrim((string) env('TELEGRAM_BOT_USERNAME', ''), '@'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        // Optional: short name of a Mini App registered with @BotFather (/newapp).
        // When set, deep links open the Mini App directly (t.me/bot/app?startapp=...).
        'mini_app_short_name' => env('TELEGRAM_MINI_APP_SHORT_NAME'),
        'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
        'timeout' => (int) env('TELEGRAM_HTTP_TIMEOUT', 8),
        // Local development only: lets the Mini App log in without Telegram.
        // Ignored unless APP_ENV=local.
        'dev_auth' => (bool) env('TELEGRAM_DEV_AUTH', false),
    ],

    // Redirect to the web installer until storage/installed.lock exists.
    'require_install' => (bool) env('INSTALLER_GUARD', true),

    // Lifetime of a Mini App session token issued after init-data validation.
    'session_ttl_hours' => (int) env('MINIAPP_SESSION_TTL_HOURS', 12),

    // Secret for the HTTP cron endpoint (/cron/run/{token}) used on hosts
    // without a real cron. Leave empty to disable the endpoint.
    'cron_token' => env('CRON_TOKEN'),

    // When true, lightweight maintenance tasks are triggered by normal web
    // traffic (at most once per minute) for hosts with no cron at all.
    'scheduler_fallback' => (bool) env('SCHEDULER_FALLBACK', false),

    /*
    |--------------------------------------------------------------------------
    | Role based access control for the admin dashboard
    |--------------------------------------------------------------------------
    */
    'roles' => [
        'super_admin' => 'Super administrator',
        'admin' => 'Administrator',
        'finance' => 'Finance reviewer',
        'support' => 'Support moderator',
        'analyst' => 'Read-only analyst',
    ],

    'permissions' => [
        'dashboard.view' => ['super_admin', 'admin', 'finance', 'support', 'analyst'],
        'users.view' => ['super_admin', 'admin', 'finance', 'support', 'analyst'],
        'users.manage' => ['super_admin', 'admin', 'support'],
        'wallet.adjust' => ['super_admin', 'finance'],
        'rewards.reverse' => ['super_admin', 'admin', 'finance'],
        'budget.manage' => ['super_admin', 'finance'],
        'withdrawals.view' => ['super_admin', 'admin', 'finance', 'support', 'analyst'],
        'withdrawals.manage' => ['super_admin', 'admin', 'finance'],
        'missions.view' => ['super_admin', 'admin', 'support', 'analyst'],
        'missions.manage' => ['super_admin', 'admin'],
        'missions.review' => ['super_admin', 'admin', 'support'],
        'games.view' => ['super_admin', 'admin', 'support', 'analyst'],
        'referrals.view' => ['super_admin', 'admin', 'finance', 'support', 'analyst'],
        'fraud.manage' => ['super_admin', 'admin', 'support'],
        'settings.manage' => ['super_admin', 'admin'],
        'settings.sensitive' => ['super_admin'],
        'telegram.manage' => ['super_admin'],
        'admins.manage' => ['super_admin'],
        'system.manage' => ['super_admin'],
        'audit.view' => ['super_admin', 'admin'],
        'reports.export' => ['super_admin', 'admin', 'finance', 'analyst'],
    ],
];
