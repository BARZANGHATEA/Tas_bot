<?php

namespace App\Http\Controllers\Admin;

use App\Models\FraudFlag;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FraudController extends AdminController
{
    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['open', 'resolved', 'dismissed'], true) ? $request->query('status') : 'open';

        return view('admin.fraud.index', [
            'status' => $status,
            'flags' => FraudFlag::query()->with(['user', 'resolver'])->where('status', $status)->latest('id')->paginate(30)->withQueryString(),
        ]);
    }

    public function resolve(Request $request, FraudFlag $flag, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:resolved,dismissed'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
            'clear_user_flag' => ['nullable', 'boolean'],
        ]);

        $flag->forceFill([
            'status' => $data['status'],
            'resolution_note' => $data['note'],
            'resolved_by' => $this->admin()->id,
            'resolved_at' => now(),
        ])->save();

        if ($request->boolean('clear_user_flag') && ! $flag->user->fraudFlags()->where('status', 'open')->exists()) {
            $flag->user->forceFill(['is_flagged' => false])->save();
        }

        $audit->log('fraud.'.$data['status'], $flag, ['note' => $data['note']], user: $flag->user);

        return back()->with('success', 'Flag '.$data['status'].'.');
    }
}
