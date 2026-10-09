<?php

namespace App\Http\Controllers\Admin;

use App\Models\MissionCompletion;
use App\Services\MissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MissionReviewController extends AdminController
{
    public function __construct(private readonly MissionService $missions) {}

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['pending_review', 'rewarded', 'rejected'], true) ? $request->query('status') : 'pending_review';

        return view('admin.missions.reviews', [
            'status' => $status,
            'completions' => MissionCompletion::query()->with(['mission', 'user', 'reviewer'])
                ->where('status', $status)
                ->orderBy($status === 'pending_review' ? 'submitted_at' : 'reviewed_at', $status === 'pending_review' ? 'asc' : 'desc')
                ->paginate(30)->withQueryString(),
        ]);
    }

    public function approve(MissionCompletion $completion): RedirectResponse
    {
        $this->missions->approve($completion, $this->admin());

        return back()->with('success', 'Submission approved and rewarded.');
    }

    public function reject(Request $request, MissionCompletion $completion): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->missions->reject($completion, $this->admin(), $data['reason']);

        return back()->with('success', 'Submission rejected.');
    }
}
