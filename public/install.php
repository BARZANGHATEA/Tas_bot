<?php

use App\Models\Admin;
use App\Services\BudgetService;
use App\Services\Settings;
use App\Services\Telegram\TelegramSetupService;
use App\Support\Money;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| Quick web installer – for hosts WITHOUT terminal / SSH access
|--------------------------------------------------------------------------
|
| Open https://your-domain/install.php in a browser, fill in the form and the
| installer will: check the server, write .env (with generated secrets),
| create the database tables, create the super administrator, optionally fund
| the reward budget and register the Telegram webhook.
|
| When it finishes it writes storage/installed.lock and refuses to run again.
| Deleting this file afterwards is recommended but not required.
|
| Written in conservative PHP syntax so that an outdated PHP version gets a
| readable message instead of a parse error.
*/

error_reporting(E_ALL);
ini_set('display_errors', '0');
@set_time_limit(300);

define('MIN_PHP', '8.3.0');

// ---------------------------------------------------------------- helpers

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function find_base_path()
{
    $candidates = [dirname(__DIR__)];
    foreach ((array) glob(dirname(__DIR__).'/*/bootstrap/app.php') as $file) {
        $candidates[] = dirname(dirname($file));
    }
    foreach ($candidates as $candidate) {
        if (is_file($candidate.'/bootstrap/app.php') && is_file($candidate.'/artisan')) {
            return realpath($candidate);
        }
    }

    return null;
}

function detect_url()
{
    $https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_HOST']) : 'localhost';
    $path = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
    // When served through the root .htaccess fallback the script lives in /public.
    $path = preg_replace('#/public$#', '', $path);

    return ($https ? 'https' : 'http').'://'.$host.$path;
}

/** Quote a value for .env (phpdotenv syntax). Returns null when it cannot be represented safely. */
function env_quote($value)
{
    $value = (string) $value;
    if (preg_match('/[\r\n]/', $value)) {
        return null;
    }
    if ($value === '') {
        return '';
    }
    if (preg_match('/^[A-Za-z0-9_.:\/@+=,-]+$/', $value)) {
        return $value;
    }
    if (strpos($value, "'") === false) {
        return "'".$value."'";
    }
    if (strpos($value, '${') !== false) {
        return null;
    }

    return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
}

function read_env_value($envFile, $key)
{
    if (! is_file($envFile)) {
        return null;
    }
    if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', (string) file_get_contents($envFile), $m)) {
        return trim($m[1], " \t\"'");
    }

    return null;
}

function random_token($bytes)
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function requirements($base)
{
    $checks = [];
    $checks[] = ['PHP '.MIN_PHP.'+', version_compare(PHP_VERSION, MIN_PHP, '>='), 'PHP '.PHP_VERSION.' – در cPanel بخش «Select PHP Version» یا «MultiPHP Manager» نسخه 8.3 یا بالاتر را انتخاب کنید.'];
    foreach (['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'curl'] as $ext) {
        $checks[] = ['PHP extension: '.$ext, extension_loaded($ext), 'در cPanel → Select PHP Version → Extensions فعال کنید.'];
    }
    $checks[] = ['vendor/ (کتابخانه‌ها)', $base !== null && is_file($base.'/vendor/autoload.php'), 'فایل ZIP آماده (release) را آپلود کنید؛ پوشه vendor داخل آن است.'];
    if ($base !== null) {
        foreach (['storage', 'storage/framework', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
            if (! is_dir($base.'/'.$dir)) {
                @mkdir($base.'/'.$dir, 0755, true);
            }
            $checks[] = ['قابل نوشتن: '.$dir, is_writable($base.'/'.$dir), 'در File Manager دسترسی پوشه را 755 قرار دهید.'];
        }
        $envWritable = is_file($base.'/.env') ? is_writable($base.'/.env') : is_writable($base);
        $checks[] = ['قابل نوشتن: .env', $envWritable, 'پوشه اصلی برنامه یا فایل .env باید قابل نوشتن باشد (644 / 755).'];
    }

    return $checks;
}

function validate_input($in)
{
    $errors = [];
    if (! filter_var($in['app_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'آدرس سایت (APP_URL) معتبر نیست.';
    } elseif (strpos($in['app_url'], 'https://') !== 0) {
        $errors[] = 'آدرس سایت باید با https:// شروع شود (تلگرام فقط HTTPS را می‌پذیرد). ابتدا SSL را در cPanel فعال کنید.';
    }
    if ($in['app_name'] === '' || mb_strlen($in['app_name']) > 60) {
        $errors[] = 'نام برنامه را وارد کنید (حداکثر ۶۰ کاراکتر).';
    }
    if (! in_array($in['timezone'], timezone_identifiers_list(), true)) {
        $errors[] = 'منطقه زمانی معتبر نیست.';
    }
    foreach (['db_host' => 'میزبان پایگاه داده', 'db_name' => 'نام پایگاه داده', 'db_user' => 'نام کاربری پایگاه داده'] as $key => $label) {
        if ($in[$key] === '') {
            $errors[] = $label.' را وارد کنید.';
        }
    }
    if (! ctype_digit($in['db_port'])) {
        $errors[] = 'پورت پایگاه داده باید عدد باشد.';
    }
    if ($in['bot_token'] !== '' && ! preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,}$/', $in['bot_token'])) {
        $errors[] = 'توکن ربات معتبر نیست (از @BotFather کپی کنید).';
    }
    if ($in['bot_username'] !== '' && ! preg_match('/^[A-Za-z0-9_]{5,32}$/', ltrim($in['bot_username'], '@'))) {
        $errors[] = 'نام کاربری ربات معتبر نیست.';
    }
    if ($in['bot_token'] !== '' && $in['bot_username'] === '') {
        $errors[] = 'نام کاربری ربات را هم وارد کنید.';
    }
    if ($in['admin_name'] === '') {
        $errors[] = 'نام مدیر را وارد کنید.';
    }
    if (! filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'ایمیل مدیر معتبر نیست.';
    }
    if (strlen($in['admin_password']) < 12 || ! preg_match('/[A-Za-z]/', $in['admin_password']) || ! preg_match('/\d/', $in['admin_password'])) {
        $errors[] = 'رمز مدیر باید حداقل ۱۲ کاراکتر و شامل حرف و عدد باشد.';
    }
    if ($in['admin_password'] !== $in['admin_password_confirmation']) {
        $errors[] = 'تکرار رمز مدیر مطابقت ندارد.';
    }
    if ($in['budget'] !== '' && ! preg_match('/^\d{1,9}(\.\d{1,6})?$/', $in['budget'])) {
        $errors[] = 'مبلغ بودجه جوایز باید عدد باشد (مثلاً 100 یا 50.5).';
    }
    if (! in_array($in['cron_mode'], ['cron', 'url', 'traffic'], true)) {
        $errors[] = 'روش اجرای کارهای زمان‌بندی را انتخاب کنید.';
    }
    foreach (['app_name', 'db_host', 'db_name', 'db_user', 'db_pass'] as $key) {
        if (env_quote($in[$key]) === null) {
            $errors[] = 'مقدار «'.$key.'» شامل کاراکترهای غیرمجاز است (خط جدید یا ترکیب ${).';
        }
    }

    return $errors;
}

function test_database($in)
{
    $dsn = 'mysql:host='.$in['db_host'].';port='.(int) $in['db_port'].';dbname='.$in['db_name'].';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, $in['db_user'], $in['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();

        return [true, (string) $version];
    } catch (Exception $e) {
        return [false, $e->getMessage()];
    }
}

function build_env($in, $existingKey, $secrets)
{
    $vals = [
        'APP_NAME' => $in['app_name'],
        'APP_ENV' => 'production',
        'APP_KEY' => $existingKey ?: 'base64:'.base64_encode(random_bytes(32)),
        'APP_DEBUG' => 'false',
        'APP_URL' => rtrim($in['app_url'], '/'),
        'APP_TIMEZONE' => 'UTC',
        'APP_LOCALE' => 'en',
        'APP_FALLBACK_LOCALE' => 'en',
        'APP_MAINTENANCE_DRIVER' => 'file',
        'BCRYPT_ROUNDS' => '12',
        'LOG_CHANNEL' => 'daily',
        'LOG_LEVEL' => 'warning',
        'LOG_DAILY_DAYS' => '14',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $in['db_host'],
        'DB_PORT' => $in['db_port'],
        'DB_DATABASE' => $in['db_name'],
        'DB_USERNAME' => $in['db_user'],
        'DB_PASSWORD' => $in['db_pass'],
        'SESSION_DRIVER' => 'database',
        'SESSION_LIFETIME' => '120',
        'SESSION_ENCRYPT' => 'true',
        'SESSION_SECURE_COOKIE' => 'true',
        'SESSION_SAME_SITE' => 'lax',
        'CACHE_STORE' => 'database',
        'QUEUE_CONNECTION' => 'sync',
        'FILESYSTEM_DISK' => 'local',
        'BROADCAST_CONNECTION' => 'log',
        'MAIL_MAILER' => 'log',
        'TRUSTED_PROXIES' => $in['behind_proxy'] ? '*' : '127.0.0.1',
        'TELEGRAM_BOT_TOKEN' => $in['bot_token'],
        'TELEGRAM_BOT_USERNAME' => ltrim($in['bot_username'], '@'),
        'TELEGRAM_WEBHOOK_SECRET' => $secrets['webhook'],
        'TELEGRAM_MINI_APP_SHORT_NAME' => $in['mini_app_short_name'],
        'TELEGRAM_HTTP_TIMEOUT' => '8',
        'TELEGRAM_DEV_AUTH' => 'false',
        'MINIAPP_SESSION_TTL_HOURS' => '12',
        'CRON_TOKEN' => $secrets['cron'],
        'SCHEDULER_FALLBACK' => $in['cron_mode'] === 'traffic' ? 'true' : 'false',
    ];

    $lines = ['# Generated by the web installer on '.gmdate('Y-m-d H:i').' UTC. Keep this file private.'];
    foreach ($vals as $key => $value) {
        $lines[] = $key.'='.env_quote($value);
    }

    return [implode("\n", $lines)."\n", $vals];
}

// ---------------------------------------------------------------- request

session_name('dice_installer');
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$base = find_base_path();
$lockFile = $base ? $base.'/storage/installed.lock' : null;
$installed = $lockFile && is_file($lockFile);
$checks = requirements($base);
$requirementsOk = true;
foreach ($checks as $check) {
    $requirementsOk = $requirementsOk && $check[1];
}

$defaults = [
    'app_name' => 'Dice Rewards', 'app_url' => detect_url(), 'timezone' => 'Asia/Tehran',
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'bot_token' => '', 'bot_username' => '', 'mini_app_short_name' => '',
    'admin_name' => '', 'admin_email' => '', 'admin_password' => '', 'admin_password_confirmation' => '',
    'budget' => '', 'cron_mode' => 'cron', 'seed' => '1', 'behind_proxy' => '',
];
$input = $defaults;
$errors = [];
$log = [];
$done = false;
$result = [];

if (! $installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($defaults as $key => $default) {
        $input[$key] = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    }
    // Passwords are taken verbatim (no trimming).
    foreach (['db_pass', 'admin_password', 'admin_password_confirmation'] as $key) {
        $input[$key] = isset($_POST[$key]) ? (string) $_POST[$key] : '';
    }

    if (! isset($_POST['csrf']) || ! hash_equals($_SESSION['csrf'], (string) $_POST['csrf'])) {
        $errors[] = 'نشست منقضی شده است. صفحه را دوباره بارگذاری کنید.';
    } elseif (! $requirementsOk) {
        $errors[] = 'ابتدا پیش‌نیازهای قرمز را برطرف کنید.';
    } else {
        $errors = validate_input($input);
    }

    if (! $errors) {
        [$dbOk, $dbInfo] = test_database($input);
        if (! $dbOk) {
            $errors[] = 'اتصال به پایگاه داده ناموفق بود: '.$dbInfo;
        } else {
            $log[] = [true, 'اتصال به پایگاه داده برقرار شد ('.$dbInfo.').'];
        }
    }

    if (! $errors) {
        try {
            // 1. Write .env (reuse an existing APP_KEY so encrypted data stays readable).
            $envFile = $base.'/.env';
            $existingKey = read_env_value($envFile, 'APP_KEY');
            $secrets = [
                'webhook' => read_env_value($envFile, 'TELEGRAM_WEBHOOK_SECRET') ?: random_token(30),
                'cron' => read_env_value($envFile, 'CRON_TOKEN') ?: random_token(30),
            ];
            [$envContent, $envValues] = build_env($input, $existingKey, $secrets);

            if (is_file($envFile)) {
                @copy($envFile, $envFile.'.backup-'.date('YmdHis'));
            }
            if (file_put_contents($envFile, $envContent, LOCK_EX) === false) {
                throw new RuntimeException('نوشتن فایل .env ممکن نشد.');
            }
            @chmod($envFile, 0600);
            $log[] = [true, 'فایل .env ساخته شد (کلید برنامه و رمزهای امنیتی به صورت خودکار تولید شدند).'];

            // Stale caches would make Laravel ignore the new .env.
            foreach (['config.php', 'routes-v7.php', 'events.php'] as $cached) {
                @unlink($base.'/bootstrap/cache/'.$cached);
            }

            // 2. Boot Laravel with the new configuration.
            require $base.'/vendor/autoload.php';

            // Make sure the parsed .env equals what the user typed (quoting edge cases).
            $parsed = Dotenv::parse($envContent);
            foreach (['DB_PASSWORD', 'DB_USERNAME', 'APP_NAME'] as $key) {
                if ((string) $parsed[$key] !== (string) $envValues[$key]) {
                    throw new RuntimeException('مقدار '.$key.' شامل کاراکتری است که در .env قابل ذخیره نیست. لطفاً آن را تغییر دهید.');
                }
            }

            $app = require $base.'/bootstrap/app.php';
            $kernel = $app->make('Illuminate\Contracts\Console\Kernel');
            $kernel->bootstrap();

            // 3. Database tables.
            $code = Artisan::call('migrate', ['--force' => true]);
            if ($code !== 0) {
                throw new RuntimeException('ساخت جداول ناموفق بود: '.Artisan::output());
            }
            $log[] = [true, 'جداول پایگاه داده ساخته شدند.'];

            // 4. Super administrator (+ example missions).
            $email = strtolower($input['admin_email']);
            $admin = Admin::query()->where('email', $email)->first();
            if ($admin) {
                $admin->forceFill(['password' => $input['admin_password'], 'role' => 'super_admin', 'is_active' => true])->save();
                $log[] = [true, 'مدیر با این ایمیل از قبل وجود داشت؛ رمز و نقش آن به‌روزرسانی شد.'];
            } else {
                $code = Artisan::call('app:install', [
                    '--name' => $input['admin_name'],
                    '--email' => $email,
                    '--password' => $input['admin_password'],
                ]);
                if ($code !== 0) {
                    throw new RuntimeException('ساخت مدیر ناموفق بود: '.Artisan::output());
                }
                $admin = Admin::query()->where('email', $email)->firstOrFail();
                $log[] = [true, 'حساب مدیر کل ساخته شد.'];
            }

            if ($input['seed'] === '1') {
                Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\MissionSeeder', '--force' => true]);
                $log[] = [true, 'مأموریت‌های نمونه اضافه شدند (موارد نیازمند تنظیم، غیرفعال هستند).'];
            }

            app(Settings::class)->update([
                'app.name' => $input['app_name'],
                'app.timezone' => $input['timezone'],
            ], $admin);

            // 5. Reward budget.
            if ($input['budget'] !== '' && Money::of($input['budget'])->isPositive()) {
                app(BudgetService::class)->fund($admin, Money::of($input['budget']), 'Initial funding (installer)');
                $log[] = [true, 'بودجه جوایز به مقدار '.h($input['budget']).' USDT شارژ شد.'];
            } else {
                $log[] = [false, 'بودجه جوایز خالی است؛ تا آن را از پنل (بودجه جوایز) شارژ نکنید، جایزه‌ای پرداخت نمی‌شود.'];
            }

            // 6. Telegram webhook, commands and menu button.
            if ($input['bot_token'] !== '') {
                try {
                    URL::forceRootUrl($envValues['APP_URL']);
                    URL::forceScheme('https');
                    app(TelegramSetupService::class)->configure();
                    $log[] = [true, 'وبهوک تلگرام، دستورات و دکمه منوی ربات ثبت شدند.'];
                } catch (Throwable $e) {
                    $log[] = [false, 'ثبت وبهوک تلگرام ناموفق بود: '.$e->getMessage().' — بعداً از پنل مدیریت → ربات تلگرام دوباره امتحان کنید.'];
                }
            } else {
                $log[] = [false, 'توکن ربات وارد نشد؛ بعداً آن را در فایل .env وارد کنید و از پنل → ربات تلگرام وبهوک را ثبت کنید.'];
            }

            // 7. Lock the installer.
            file_put_contents($lockFile, json_encode(['installed_at' => gmdate('c'), 'by' => $email]), LOCK_EX);
            $log[] = [true, 'نصب قفل شد؛ این نصب‌کننده دیگر اجرا نمی‌شود.'];

            $done = true;
            $result = [
                'admin_url' => rtrim($envValues['APP_URL'], '/').'/admin',
                'app_url' => rtrim($envValues['APP_URL'], '/').'/app',
                'cron_cmd' => '* * * * * /usr/local/bin/php '.$base.'/artisan schedule:run >> /dev/null 2>&1',
                'cron_url' => rtrim($envValues['APP_URL'], '/').'/cron/run/'.$secrets['cron'],
                'cron_mode' => $input['cron_mode'],
                'bot' => ltrim($input['bot_username'], '@'),
            ];
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$timezones = ['Asia/Tehran', 'UTC', 'Europe/Istanbul', 'Asia/Dubai', 'Europe/London', 'Europe/Berlin', 'America/New_York'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>نصب سریع – Dice Rewards</title>
    <style>
        :root { --brand:#5b4ce0; --ok:#0f9d6b; --bad:#d23b5a; --warn:#b7791f; --line:#e4e6f0; --muted:#6b6f8e; }
        * { box-sizing: border-box; }
        body { margin:0; background:linear-gradient(135deg,#15162b,#2b2560); font-family: Tahoma, "Segoe UI", system-ui, sans-serif; color:#1d1f33; line-height:1.8; font-size:14px; }
        .wrap { max-width:820px; margin:30px auto; padding:0 16px; }
        .card { background:#fff; border-radius:16px; padding:24px; margin-bottom:18px; box-shadow:0 20px 50px rgba(0,0,0,.25); }
        h1 { margin:0 0 4px; font-size:22px; } h2 { font-size:16px; margin:0 0 12px; border-bottom:1px solid var(--line); padding-bottom:8px; }
        .muted { color:var(--muted); } .small { font-size:12px; }
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
        label { display:block; margin-bottom:12px; font-weight:bold; }
        label span.hint { display:block; font-weight:normal; color:var(--muted); font-size:12px; }
        input, select { width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:8px; font:inherit; direction:ltr; text-align:left; margin-top:4px; }
        input:focus, select:focus { outline:2px solid #eeebff; border-color:var(--brand); }
        .check { display:flex; gap:8px; align-items:flex-start; font-weight:normal; }
        .check input { width:auto; margin-top:6px; }
        button { background:var(--brand); color:#fff; border:0; border-radius:10px; padding:12px 22px; font:inherit; font-weight:bold; cursor:pointer; width:100%; font-size:16px; }
        ul.req { list-style:none; padding:0; margin:0; } ul.req li { padding:4px 0; border-bottom:1px dashed var(--line); }
        .ok { color:var(--ok); } .bad { color:var(--bad); } .warn { color:var(--warn); }
        .alert { border-radius:10px; padding:12px 16px; margin-bottom:16px; }
        .alert-bad { background:#fde8ed; color:#8d1d36; } .alert-ok { background:#e3f7ef; color:#0b6b49; } .alert-info { background:#e5eeff; color:#1e429f; }
        code, .ltr { direction:ltr; unicode-bidi:embed; font-family: ui-monospace, Menlo, Consolas, monospace; font-size:12.5px; background:#f4f5fb; padding:2px 6px; border-radius:6px; word-break:break-all; display:inline-block; }
        .radio { display:block; font-weight:normal; border:1px solid var(--line); border-radius:10px; padding:10px 12px; margin-bottom:8px; }
        .radio input { width:auto; margin-left:6px; }
        a.btn { display:inline-block; background:var(--brand); color:#fff; padding:10px 18px; border-radius:10px; text-decoration:none; font-weight:bold; margin:4px 0; }
        @media (max-width:640px) { .grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>🎲 نصب سریع Dice Rewards</h1>
        <p class="muted" style="margin:0">نصب کامل بدون نیاز به ترمینال: پر کردن فرم ← ساخت خودکار .env، جداول، حساب مدیر و اتصال ربات تلگرام.</p>
    </div>

<?php if ($base === null) { ?>
    <div class="card"><div class="alert alert-bad">فایل‌های برنامه پیدا نشدند. محتوای ZIP را طوری استخراج کنید که این فایل در پوشه <code>public</code> برنامه باشد.</div></div>

<?php } elseif ($installed && ! $done) { ?>
    <div class="card">
        <div class="alert alert-ok">برنامه قبلاً نصب شده است. این نصب‌کننده برای امنیت غیرفعال است.</div>
        <p><a class="btn" href="admin">ورود به پنل مدیریت</a></p>
        <p class="small muted">برای نصب مجدد (فقط اگر می‌دانید چه می‌کنید): فایل <code>storage/installed.lock</code> را حذف کنید. برای امنیت بیشتر می‌توانید فایل <code>public/install.php</code> را هم حذف کنید.</p>
    </div>

<?php } elseif ($done) { ?>
    <div class="card">
        <div class="alert alert-ok"><strong>نصب با موفقیت انجام شد! 🎉</strong></div>
        <ul class="req">
            <?php foreach ($log as $line) { ?>
                <li class="<?php echo $line[0] ? 'ok' : 'warn'; ?>"><?php echo $line[0] ? '✔' : '⚠'; ?> <?php echo h($line[1]); ?></li>
            <?php } ?>
        </ul>
    </div>
    <div class="card">
        <h2>قدم‌های بعدی</h2>
        <p><strong>۱. ورود به پنل مدیریت:</strong><br><a class="btn" href="<?php echo h($result['admin_url']); ?>"><?php echo h($result['admin_url']); ?></a></p>
        <p><strong>۲. کارهای زمان‌بندی‌شده (ارسال پیام‌ها، بستن مسابقات منقضی، کنترل حساب‌ها):</strong></p>
        <?php if ($result['cron_mode'] === 'cron') { ?>
            <p>در cPanel بخش <b>Cron Jobs</b> (حتی بدون ترمینال در دسترس است) یک کرون با زمان‌بندی «Once Per Minute» بسازید و این دستور را وارد کنید (مسیر PHP را در صورت نیاز با مسیری که میزبان اعلام کرده جایگزین کنید، مثلاً <code>/opt/cpanel/ea-php83/root/usr/bin/php</code>):</p>
            <p><code><?php echo h($result['cron_cmd']); ?></code></p>
            <p class="small muted">اگر بخش Cron Jobs ندارید، می‌توانید از سرویس رایگان cron-job.org هر دقیقه این آدرس را صدا بزنید:<br><code><?php echo h($result['cron_url']); ?></code></p>
        <?php } elseif ($result['cron_mode'] === 'url') { ?>
            <p>در سایتی مثل <b>cron-job.org</b> یک کار «هر ۱ دقیقه» بسازید که این آدرس را باز کند (این آدرس را محرمانه نگه دارید):</p>
            <p><code><?php echo h($result['cron_url']); ?></code></p>
        <?php } else { ?>
            <p>حالت «اجرا با بازدید کاربران» فعال شد؛ نیازی به کاری نیست. کارها هنگام استفاده کاربران از برنامه (حداکثر هر دقیقه یک بار) اجرا می‌شوند.</p>
        <?php } ?>
        <p><strong>۳. در پنل:</strong> بودجه جوایز را شارژ کنید، تنظیمات (جوایز، محدودیت‌ها، شبکه‌های برداشت، کانال‌ها) را بررسی کنید و مأموریت‌ها را فعال کنید.</p>
        <?php if ($result['bot']) { ?>
            <p><strong>۴. تست ربات:</strong> به <a href="https://t.me/<?php echo h($result['bot']); ?>">@<?php echo h($result['bot']); ?></a> پیام <code>/start</code> بدهید.</p>
        <?php } ?>
        <p class="small muted">پیشنهاد امنیتی: پس از نصب، فایل <code>public/install.php</code> را از File Manager حذف کنید (نصب‌کننده به هر حال قفل شده است).</p>
    </div>

<?php } else { ?>
    <div class="card">
        <h2>۱. بررسی سرور</h2>
        <ul class="req">
            <?php foreach ($checks as $check) { ?>
                <li class="<?php echo $check[1] ? 'ok' : 'bad'; ?>"><?php echo $check[1] ? '✔' : '✖'; ?> <?php echo h($check[0]); ?>
                    <?php if (! $check[1]) { ?><br><span class="small"><?php echo h($check[2]); ?></span><?php } ?></li>
            <?php } ?>
        </ul>
    </div>

    <?php if ($errors) { ?>
        <div class="card"><div class="alert alert-bad"><strong>خطا:</strong><ul><?php foreach ($errors as $error) { ?><li><?php echo h($error); ?></li><?php } ?></ul></div>
            <?php if ($log) { ?><ul class="req"><?php foreach ($log as $line) { ?><li class="ok">✔ <?php echo h($line[1]); ?></li><?php } ?></ul><?php } ?>
        </div>
    <?php } ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">

        <div class="card">
            <h2>۲. اطلاعات سایت</h2>
            <div class="grid">
                <label>نام برنامه<input name="app_name" value="<?php echo h($input['app_name']); ?>" required></label>
                <label>آدرس سایت (APP_URL)<span class="hint">باید https باشد، بدون / در انتها</span><input name="app_url" value="<?php echo h($input['app_url']); ?>" required></label>
                <label>منطقه زمانی<select name="timezone"><?php foreach ($timezones as $tz) { ?><option value="<?php echo h($tz); ?>" <?php echo $input['timezone'] === $tz ? 'selected' : ''; ?>><?php echo h($tz); ?></option><?php } ?></select></label>
                <label class="check" style="margin-top:28px"><input type="checkbox" name="behind_proxy" value="1" <?php echo $input['behind_proxy'] ? 'checked' : ''; ?>> سایت پشت Cloudflare یا پروکسی است</label>
            </div>
        </div>

        <div class="card">
            <h2>۳. پایگاه داده MySQL</h2>
            <p class="small muted">در cPanel → MySQL Databases یک پایگاه داده و کاربر بسازید و کاربر را با ALL PRIVILEGES به پایگاه داده اضافه کنید. نام‌ها معمولاً پیشوند نام کاربری هاست را دارند (مثلاً <code>user_dice</code>).</p>
            <div class="grid">
                <label>میزبان (DB_HOST)<input name="db_host" value="<?php echo h($input['db_host']); ?>" required></label>
                <label>پورت<input name="db_port" value="<?php echo h($input['db_port']); ?>" required></label>
                <label>نام پایگاه داده<input name="db_name" value="<?php echo h($input['db_name']); ?>" required></label>
                <label>نام کاربری<input name="db_user" value="<?php echo h($input['db_user']); ?>" required></label>
                <label>رمز عبور پایگاه داده<input type="password" name="db_pass" value="<?php echo h($input['db_pass']); ?>"></label>
            </div>
        </div>

        <div class="card">
            <h2>۴. ربات تلگرام</h2>
            <p class="small muted">در @BotFather با دستور <code>/newbot</code> ربات بسازید و توکن را کپی کنید. (اختیاری: با <code>/newapp</code> یک Mini App با آدرس <code><?php echo h(rtrim($input['app_url'], '/')); ?>/app</code> بسازید.) می‌توانید این بخش را خالی بگذارید و بعداً در .env وارد کنید.</p>
            <div class="grid">
                <label>توکن ربات (TELEGRAM_BOT_TOKEN)<input name="bot_token" value="<?php echo h($input['bot_token']); ?>" placeholder="123456789:AA..."></label>
                <label>نام کاربری ربات (بدون @)<input name="bot_username" value="<?php echo h($input['bot_username']); ?>" placeholder="MyDiceBot"></label>
                <label>نام کوتاه Mini App (اختیاری)<input name="mini_app_short_name" value="<?php echo h($input['mini_app_short_name']); ?>" placeholder="app"></label>
            </div>
        </div>

        <div class="card">
            <h2>۵. حساب مدیر کل</h2>
            <div class="grid">
                <label>نام<input name="admin_name" value="<?php echo h($input['admin_name']); ?>" required></label>
                <label>ایمیل (نام کاربری ورود)<input type="email" name="admin_email" value="<?php echo h($input['admin_email']); ?>" required></label>
                <label>رمز عبور<span class="hint">حداقل ۱۲ کاراکتر، شامل حرف و عدد</span><input type="password" name="admin_password" required autocomplete="new-password"></label>
                <label>تکرار رمز عبور<span class="hint">&nbsp;</span><input type="password" name="admin_password_confirmation" required autocomplete="new-password"></label>
            </div>
        </div>

        <div class="card">
            <h2>۶. تنظیمات اولیه</h2>
            <label>بودجه اولیه جوایز (USDT، اختیاری)<span class="hint">مبلغی که واقعاً برای جوایز کنار گذاشته‌اید. جوایز فقط تا این سقف پرداخت می‌شوند و بعداً از پنل قابل تغییر است.</span><input name="budget" value="<?php echo h($input['budget']); ?>" placeholder="100"></label>
            <label class="check"><input type="checkbox" name="seed" value="1" <?php echo $input['seed'] === '1' ? 'checked' : ''; ?>> افزودن مأموریت‌های نمونه</label>

            <p style="font-weight:bold;margin-bottom:6px">اجرای کارهای زمان‌بندی‌شده:</p>
            <label class="radio"><input type="radio" name="cron_mode" value="cron" <?php echo $input['cron_mode'] === 'cron' ? 'checked' : ''; ?>> <b>Cron Jobs در cPanel</b> (توصیه‌شده – معمولاً بدون ترمینال هم در دسترس است)</label>
            <label class="radio"><input type="radio" name="cron_mode" value="url" <?php echo $input['cron_mode'] === 'url' ? 'checked' : ''; ?>> <b>سرویس کرون خارجی</b> (مثل cron-job.org) – یک آدرس مخفی به شما داده می‌شود</label>
            <label class="radio"><input type="radio" name="cron_mode" value="traffic" <?php echo $input['cron_mode'] === 'traffic' ? 'checked' : ''; ?>> <b>اجرا با بازدید کاربران</b> – بدون هیچ تنظیم اضافه (ساده‌ترین، ولی فقط وقتی کاربران فعال‌اند)</label>
        </div>

        <div class="card">
            <?php if (! $requirementsOk) { ?><div class="alert alert-bad">پیش‌نیازهای قرمز در بخش ۱ را برطرف کنید و صفحه را دوباره بارگذاری کنید.</div><?php } ?>
            <button type="submit" <?php echo $requirementsOk ? '' : 'disabled'; ?>>نصب کن</button>
            <p class="small muted" style="margin-bottom:0">نصب ممکن است تا یک دقیقه طول بکشد. اطلاعات حساس فقط در فایل <code>.env</code> روی سرور شما ذخیره می‌شود.</p>
        </div>
    </form>
<?php } ?>
</div>
</body>
</html>
