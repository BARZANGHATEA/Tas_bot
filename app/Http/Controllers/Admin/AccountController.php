<?php

namespace App\Http\Controllers\Admin;

use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends AdminController
{
    public function edit(): View
    {
        return view('admin.account', ['admin' => $this->admin()]);
    }

    public function updatePassword(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate($this->confirmRules() + [
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        $admin = $this->admin();
        $admin->forceFill(['password' => $data['password']])->save();
        $audit->log('admin.password_changed', $admin);

        return back()->with('success', 'Password updated.');
    }
}
