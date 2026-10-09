<?php

use App\Http\Controllers\CronController;
use App\Http\Controllers\MiniAppController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MiniAppController::class, 'landing'])->name('landing');
Route::get('/app', [MiniAppController::class, 'show'])->name('miniapp');
Route::get('/legal/{page}', [MiniAppController::class, 'legal'])->whereIn('page', ['terms', 'privacy'])->name('legal');

// For hosts without cron: call this URL every minute from an external cron service.
Route::get('/cron/run/{token}', CronController::class)->middleware('throttle:10,1')->name('cron.run');

require __DIR__.'/admin.php';
