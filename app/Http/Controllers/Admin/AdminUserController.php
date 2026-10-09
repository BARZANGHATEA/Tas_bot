<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AdminUserController extends AdminController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.admins.index', ['admins' => Admin::query()->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('admin.admins.form', ['subject' => new Admin(['role' => AdminRole::Support, 'is_active' => true]), 'roles' => AdminRole::cases()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->confirmRules() + [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:admins,email'],
            'role' => ['required', Rule::enum(AdminRole::class)],
            'telegram_id' => ['nullable', 'integer', 'min:1', 'unique:admins,telegram_id'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        $admin = Admin::query()->create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'role' => $data['role'],
            'telegram_id' => $data['telegram_id'] ?? null,
            'password' => $data['password'],
            'is_active' => true,
        ]);
        $this->audit->log('admin.created', $admin, ['role' => $data['role']]);

        return redirect()->route('admin.admins.index')->with('success', 'Administrator created.');
    }

    public function edit(Admin $admin): View
    {
        return view('admin.admins.form', ['subject' => $admin, 'roles' => AdminRole::cases()]);
    }

    public function update(Request $request, Admin $admin): RedirectResponse
    {
        $data = $request->validate($this->confirmRules() + [
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::enum(AdminRole::class)],
            'telegram_id' => ['nullable', 'integer', 'min:1', Rule::unique('admins', 'telegram_id')->ignore($admin->id)],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        $isActive = $request->boolean('is_active');
        if ($admin->is($this->admin()) && (! $isActive || $data['role'] !== AdminRole::SuperAdmin->value)) {
            return back()->with('error', 'You cannot deactivate or demote your own account.');
        }

        $before = ['role' => $admin->role->value, 'is_active' => $admin->is_active];
        $admin->forceFill([
            'name' => $data['name'],
            'role' => $data['role'],
            'telegram_id' => $data['telegram_id'] ?? null,
            'is_active' => $isActive,
        ]);
        if (! empty($data['password'])) {
            $admin->password = $data['password'];
        }
        $admin->save();

        $this->audit->log('admin.updated', $admin, ['before' => $before, 'after' => ['role' => $data['role'], 'is_active' => $isActive], 'password_changed' => ! empty($data['password'])]);

        return redirect()->route('admin.admins.index')->with('success', 'Administrator updated.');
    }
}
