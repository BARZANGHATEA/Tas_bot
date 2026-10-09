<?php

use Illuminate\Support\Facades\Schedule;

/*
| One cron entry runs everything (see docs/DEPLOYMENT.md):
|   * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
| No queue worker or long-running process is required. Each task is short
| and protected against overlapping runs.
*/

Schedule::command('telegram:dispatch')->everyMinute()->withoutOverlapping(5);
Schedule::command('matches:expire')->everyMinute()->withoutOverlapping(5);
Schedule::command('missions:close')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('fraud:scan')->hourly()->withoutOverlapping();
Schedule::command('wallet:reconcile')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('maintenance:prune')->dailyAt('03:40')->withoutOverlapping();
