<?php

use App\Http\Controllers\Admin;
use Illuminate\Support\Facades\Route;

/*
| Administrator dashboard. Cookie session + CSRF (web middleware group),
| role-based permissions via the "admin.can" middleware, and password
| re-confirmation on financial / sensitive actions.
*/

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [Admin\AuthController::class, 'showLogin'])->name('login');
        Route::post('login', [Admin\AuthController::class, 'login'])->middleware('throttle:admin-login')->name('login.attempt');
    });

    Route::middleware(['auth:admin', 'throttle:admin-actions'])->group(function () {
        Route::post('logout', [Admin\AuthController::class, 'logout'])->name('logout');
        Route::get('account', [Admin\AccountController::class, 'edit'])->name('account');
        Route::put('account/password', [Admin\AccountController::class, 'updatePassword'])->name('account.password');

        Route::get('/', [Admin\DashboardController::class, 'index'])->middleware('admin.can:dashboard.view')->name('dashboard');

        // Users
        Route::middleware('admin.can:users.view')->group(function () {
            Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
            Route::get('users/export', [Admin\UserController::class, 'export'])->middleware('admin.can:reports.export')->name('users.export');
            Route::get('users/{user}', [Admin\UserController::class, 'show'])->name('users.show');
        });
        Route::middleware('admin.can:users.manage')->group(function () {
            Route::put('users/{user}/status', [Admin\UserController::class, 'updateStatus'])->name('users.status');
            Route::put('users/{user}/note', [Admin\UserController::class, 'updateNote'])->name('users.note');
            Route::put('users/{user}/flag', [Admin\UserController::class, 'toggleFlag'])->name('users.flag');
        });
        Route::post('users/{user}/adjust', [Admin\UserController::class, 'adjust'])->middleware('admin.can:wallet.adjust')->name('users.adjust');

        // Withdrawals
        Route::middleware('admin.can:withdrawals.view')->group(function () {
            Route::get('withdrawals', [Admin\WithdrawalController::class, 'index'])->name('withdrawals.index');
            Route::get('withdrawals/export', [Admin\WithdrawalController::class, 'export'])->middleware('admin.can:reports.export')->name('withdrawals.export');
            Route::get('withdrawals/{withdrawal}', [Admin\WithdrawalController::class, 'show'])->name('withdrawals.show');
        });
        Route::middleware('admin.can:withdrawals.manage')->group(function () {
            Route::post('withdrawals/{withdrawal}/approve', [Admin\WithdrawalController::class, 'approve'])->name('withdrawals.approve');
            Route::post('withdrawals/{withdrawal}/processing', [Admin\WithdrawalController::class, 'processing'])->name('withdrawals.processing');
            Route::post('withdrawals/{withdrawal}/paid', [Admin\WithdrawalController::class, 'paid'])->name('withdrawals.paid');
            Route::post('withdrawals/{withdrawal}/reject', [Admin\WithdrawalController::class, 'reject'])->name('withdrawals.reject');
        });

        // Missions
        Route::middleware('admin.can:missions.view')->group(function () {
            Route::get('missions', [Admin\MissionController::class, 'index'])->name('missions.index');
            Route::get('missions/reviews', [Admin\MissionReviewController::class, 'index'])->name('missions.reviews');
        });
        Route::middleware('admin.can:missions.manage')->group(function () {
            Route::get('missions/create', [Admin\MissionController::class, 'create'])->name('missions.create');
            Route::post('missions', [Admin\MissionController::class, 'store'])->name('missions.store');
            Route::get('missions/{mission}/edit', [Admin\MissionController::class, 'edit'])->name('missions.edit');
            Route::put('missions/{mission}', [Admin\MissionController::class, 'update'])->name('missions.update');
            Route::post('missions/{mission}/duplicate', [Admin\MissionController::class, 'duplicate'])->name('missions.duplicate');
            Route::post('missions/{mission}/status', [Admin\MissionController::class, 'status'])->name('missions.status');
            Route::delete('missions/{mission}', [Admin\MissionController::class, 'destroy'])->name('missions.destroy');
        });
        Route::middleware('admin.can:missions.review')->group(function () {
            Route::post('missions/reviews/{completion}/approve', [Admin\MissionReviewController::class, 'approve'])->name('missions.reviews.approve');
            Route::post('missions/reviews/{completion}/reject', [Admin\MissionReviewController::class, 'reject'])->name('missions.reviews.reject');
        });

        // Games
        Route::middleware('admin.can:games.view')->group(function () {
            Route::get('games/rounds', [Admin\GameController::class, 'rounds'])->name('games.rounds');
            Route::get('games/matches', [Admin\GameController::class, 'matches'])->name('games.matches');
            Route::get('games/matches/{match}', [Admin\GameController::class, 'match'])->name('games.match');
        });

        // Referrals
        Route::get('referrals', [Admin\ReferralController::class, 'index'])->middleware('admin.can:referrals.view')->name('referrals.index');
        Route::post('referrals/{reward}/reverse', [Admin\ReferralController::class, 'reverse'])->middleware('admin.can:rewards.reverse')->name('referrals.reverse');

        // Ledger & budget
        Route::get('ledger', [Admin\LedgerController::class, 'index'])->middleware('admin.can:users.view')->name('ledger.index');
        Route::get('ledger/export', [Admin\LedgerController::class, 'export'])->middleware(['admin.can:users.view', 'admin.can:reports.export'])->name('ledger.export');
        Route::post('ledger/{entry}/reverse', [Admin\LedgerController::class, 'reverse'])->middleware('admin.can:rewards.reverse')->name('ledger.reverse');
        Route::get('budget', [Admin\BudgetController::class, 'index'])->middleware('admin.can:dashboard.view')->name('budget.index');
        Route::post('budget', [Admin\BudgetController::class, 'store'])->middleware('admin.can:budget.manage')->name('budget.store');

        // Fraud review
        Route::get('fraud', [Admin\FraudController::class, 'index'])->middleware('admin.can:users.view')->name('fraud.index');
        Route::post('fraud/{flag}/resolve', [Admin\FraudController::class, 'resolve'])->middleware('admin.can:fraud.manage')->name('fraud.resolve');

        // Settings & Telegram
        Route::middleware('admin.can:settings.manage')->group(function () {
            Route::get('settings/{group?}', [Admin\SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('settings/{group}', [Admin\SettingsController::class, 'update'])->name('settings.update');
        });
        Route::middleware('admin.can:telegram.manage')->group(function () {
            Route::get('telegram', [Admin\TelegramController::class, 'index'])->name('telegram.index');
            Route::post('telegram/setup', [Admin\TelegramController::class, 'setup'])->name('telegram.setup');
            Route::post('telegram/test', [Admin\TelegramController::class, 'test'])->name('telegram.test');
        });

        // Administrators & audit trail
        Route::middleware('admin.can:admins.manage')->group(function () {
            Route::get('admins', [Admin\AdminUserController::class, 'index'])->name('admins.index');
            Route::get('admins/create', [Admin\AdminUserController::class, 'create'])->name('admins.create');
            Route::post('admins', [Admin\AdminUserController::class, 'store'])->name('admins.store');
            Route::get('admins/{admin}/edit', [Admin\AdminUserController::class, 'edit'])->name('admins.edit');
            Route::put('admins/{admin}', [Admin\AdminUserController::class, 'update'])->name('admins.update');
        });
        Route::get('audit', [Admin\AuditController::class, 'index'])->middleware('admin.can:audit.view')->name('audit.index');
    });
});
