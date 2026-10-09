<?php

namespace App\Support;

/**
 * Shared hosting fallback layout: the project is extracted into a web folder
 * (e.g. public_html/Tas_bot) and the root .htaccess rewrites every request into
 * public/. PHP then reports SCRIPT_NAME "/Tas_bot/public/index.php" while the
 * visitor's URL is "/Tas_bot/admin/login", so the request base path cannot be
 * detected and every route returns 404.
 *
 * This makes PHP see the layout the visitor uses ("/Tas_bot/index.php"), so the
 * base path is "/Tas_bot" and the route path is "/admin/login". Requests that do
 * include "/public" in the URL, and the recommended layout (document root =
 * public/), are left untouched.
 */
final class SubdirectoryRewrite
{
    public static function apply(array $server): array
    {
        $script = (string) ($server['SCRIPT_NAME'] ?? '');
        if (! str_ends_with($script, '/public/index.php')) {
            return $server;
        }

        $publicDir = substr($script, 0, -strlen('/index.php'));
        $path = strtok((string) ($server['REQUEST_URI'] ?? '/'), '?') ?: '/';

        if ($path === $publicDir || str_starts_with($path, $publicDir.'/')) {
            return $server;
        }

        $visibleDir = substr($publicDir, 0, -strlen('/public'));
        $server['SCRIPT_NAME'] = $visibleDir.'/index.php';
        $server['PHP_SELF'] = $visibleDir.'/index.php';

        return $server;
    }
}
