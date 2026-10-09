<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * HTTP trigger for the scheduler, for hosts that cannot run cron jobs.
 * Disabled unless CRON_TOKEN is set; the token is compared in constant time.
 */
class CronController extends Controller
{
    public function __invoke(string $token): JsonResponse
    {
        $expected = (string) config('dicegame.cron_token');
        abort_if($expected === '' || strlen($expected) < 24 || ! hash_equals($expected, $token), 404);

        if (! Cache::add('cron-http-tick', 1, 50)) {
            return response()->json(['ok' => true, 'skipped' => 'ran less than a minute ago']);
        }

        set_time_limit(55);
        Artisan::call('schedule:run');

        return response()->json(['ok' => true]);
    }
}
