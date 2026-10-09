<?php

use App\Http\Controllers\MiniApp;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// Telegram Bot API webhook (authenticated by X-Telegram-Bot-Api-Secret-Token).
Route::post('telegram/webhook', TelegramWebhookController::class)
    ->middleware('throttle:webhook')
    ->name('telegram.webhook');

// Mini App JSON API. Stateless: bearer token issued after init-data validation.
Route::prefix('miniapp')->name('miniapp.')->group(function () {
    Route::post('auth', [MiniApp\AuthController::class, 'login'])
        ->middleware(['throttle:miniapp-auth', 'miniapp.available'])
        ->name('auth');

    Route::middleware(['auth:miniapp', 'miniapp.available', 'throttle:miniapp'])->group(function () {
        Route::post('logout', [MiniApp\AuthController::class, 'logout'])->name('logout');

        Route::get('home', [MiniApp\HomeController::class, 'show'])->name('home');
        Route::get('transactions', [MiniApp\HomeController::class, 'transactions'])->name('transactions');

        Route::get('games', [MiniApp\GameController::class, 'index'])->name('games');
        Route::post('games/single/play', [MiniApp\GameController::class, 'play'])->middleware('throttle:game')->name('games.play');

        Route::get('matches', [MiniApp\MatchController::class, 'index'])->name('matches.index');
        Route::post('matches', [MiniApp\MatchController::class, 'store'])->middleware('throttle:game')->name('matches.store');
        Route::get('matches/{uuid}', [MiniApp\MatchController::class, 'show'])->name('matches.show');
        Route::post('matches/{uuid}/join', [MiniApp\MatchController::class, 'join'])->middleware('throttle:game')->name('matches.join');
        Route::post('matches/{uuid}/roll', [MiniApp\MatchController::class, 'roll'])->middleware('throttle:game')->name('matches.roll');
        Route::post('matches/{uuid}/cancel', [MiniApp\MatchController::class, 'cancel'])->name('matches.cancel');

        Route::get('missions', [MiniApp\MissionController::class, 'index'])->name('missions');
        Route::post('missions/{mission}/start', [MiniApp\MissionController::class, 'start'])->middleware('throttle:mission')->name('missions.start');
        Route::post('missions/{mission}/claim', [MiniApp\MissionController::class, 'claim'])->middleware('throttle:mission')->name('missions.claim');

        Route::get('referrals', [MiniApp\ReferralController::class, 'show'])->name('referrals');

        Route::get('withdrawals', [MiniApp\WithdrawalController::class, 'index'])->name('withdrawals');
        Route::post('withdrawals', [MiniApp\WithdrawalController::class, 'store'])->middleware('throttle:withdraw')->name('withdrawals.store');
        Route::post('withdrawals/{reference}/cancel', [MiniApp\WithdrawalController::class, 'cancel'])->name('withdrawals.cancel');
    });
});
