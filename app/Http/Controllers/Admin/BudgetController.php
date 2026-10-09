<?php

namespace App\Http\Controllers\Admin;

use App\Models\BudgetTransaction;
use App\Services\AuditLogger;
use App\Services\BudgetService;
use App\Services\Settings;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BudgetController extends AdminController
{
    public function index(BudgetService $budget, Settings $settings): View
    {
        return view('admin.budget.index', [
            'budget' => $budget->current(),
            'issuedToday' => $budget->issuedToday(),
            'platformCap' => $settings->money('rewards.platform_daily_cap'),
            'userCap' => $settings->money('rewards.user_daily_cap'),
            'transactions' => BudgetTransaction::query()->with('admin')->latest('id')->paginate(25),
        ]);
    }

    public function store(Request $request, BudgetService $budget, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate($this->confirmRules() + [
            'direction' => ['required', 'in:fund,defund'],
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $amount = Money::of($data['amount']);
        $tx = $data['direction'] === 'fund'
            ? $budget->fund($this->admin(), $amount, $data['note'])
            : $budget->defund($this->admin(), $amount, $data['note']);

        $audit->log('budget.'.$data['direction'], $tx, ['amount' => Money::str($amount), 'note' => $data['note']]);

        return back()->with('success', 'Reward budget updated.');
    }
}
