<?php

namespace App\Http\Controllers\Admin;

use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends AdminController
{
    public function showLogin(): View
    {
        return view('admin.auth.login');
    }

    public function login(Request $request, AuditLogger $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $credentials['email'] = strtolower($credentials['email']);

        if (! Auth::guard('admin')->attempt($credentials + ['is_active' => true], false)) {
            Log::warning('Failed admin login', ['email' => $credentials['email'], 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active administrator.']);
        }

        $request->session()->regenerate();
        $admin = Auth::guard('admin')->user();
        $admin->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $audit->log('admin.login', $admin, [], $admin);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
