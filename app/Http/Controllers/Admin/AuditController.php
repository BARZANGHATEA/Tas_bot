<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Support\TableSort;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends AdminController
{
    public function index(Request $request): View
    {
        $query = AuditLog::query()->with(['admin', 'user']);
        if ($action = $request->query('action')) {
            $query->where('action', 'like', $action.'%');
        }
        if ($admin = $request->query('admin')) {
            $query->where('admin_id', (int) $admin);
        }

        return view('admin.audit.index', [
            'logs' => $query->latest('id')->paginate(TableSort::perPage($request, 50))->withQueryString(),
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
