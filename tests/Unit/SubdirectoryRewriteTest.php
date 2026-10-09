<?php

namespace Tests\Unit;

use App\Support\SubdirectoryRewrite;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SubdirectoryRewriteTest extends TestCase
{
    public static function layouts(): array
    {
        return [
            // [SCRIPT_NAME, REQUEST_URI, expected base URL, expected route path]
            'sub-folder behind root .htaccess' => ['/Tas_bot/public/index.php', '/Tas_bot/admin/login', '/Tas_bot', '/admin/login'],
            'sub-folder home page' => ['/Tas_bot/public/index.php', '/Tas_bot/', '/Tas_bot', '/'],
            'sub-folder with query string' => ['/Tas_bot/public/index.php', '/Tas_bot/app?tab=games', '/Tas_bot', '/app'],
            'sub-folder, /public visible in URL' => ['/Tas_bot/public/index.php', '/Tas_bot/public/admin/login', '/Tas_bot/public', '/admin/login'],
            'domain root behind root .htaccess' => ['/public/index.php', '/admin/login', '', '/admin/login'],
            'document root = public (recommended)' => ['/index.php', '/admin/login', '', '/admin/login'],
            'sub-folder with document root = public' => ['/dice/index.php', '/dice/api/telegram/webhook', '/dice', '/api/telegram/webhook'],
        ];
    }

    #[DataProvider('layouts')]
    public function test_route_path_is_detected_for_every_layout(string $script, string $uri, string $base, string $path): void
    {
        $server = SubdirectoryRewrite::apply([
            'SCRIPT_NAME' => $script,
            'PHP_SELF' => $script,
            'SCRIPT_FILENAME' => '/home/user/public_html'.$script,
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
        ]);

        $request = new Request([], [], [], [], [], $server);

        $this->assertSame($base, $request->getBaseUrl());
        $this->assertSame($path, $request->getPathInfo());
    }
}
