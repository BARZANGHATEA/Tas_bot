<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WithdrawalStatus;
use App\Models\FraudFlag;
use App\Models\Withdrawal;
use App\Services\StatsService;
use Illuminate\View\View;

class DashboardController extends AdminController
{
    public function index(StatsService $stats): View
    {
        return view('admin.dashboard', [
            'stats' => $stats->dashboard(),
            'pending' => Withdrawal::query()->with('user')->where('status', WithdrawalStatus::Pending)->oldest()->limit(8)->get(),
            'alerts' => FraudFlag::query()->with('user')->where('status', 'open')->latest('id')->limit(8)->get(),
        ]);
    }
}
