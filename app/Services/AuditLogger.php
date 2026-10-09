<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditLogger
{
    public function log(string $action, ?Model $subject = null, array $data = [], ?Admin $admin = null, ?User $user = null): AuditLog
    {
        $admin ??= Auth::guard('admin')->user();
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::query()->create([
            'actor_type' => $admin ? 'admin' : ($user ? 'user' : 'system'),
            'admin_id' => $admin?->id,
            'user_id' => $user?->id ?? ($subject instanceof User ? $subject->id : null),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'data' => $data ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ]);
    }
}
