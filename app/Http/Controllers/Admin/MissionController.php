<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MissionType;
use App\Enums\MissionVerification;
use App\Http\Requests\Admin\MissionRequest;
use App\Models\Mission;
use App\Models\MissionCompletion;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MissionController extends AdminController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $query = Mission::query()->withCount([
            'completions as rewarded_count' => fn ($q) => $q->where('status', 'rewarded'),
            'completions as pending_count' => fn ($q) => $q->where('status', 'pending_review'),
            'completions as rejected_count' => fn ($q) => $q->where('status', 'rejected'),
        ]);
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('admin.missions.index', [
            'missions' => $query->orderBy('sort_order')->orderBy('id')->paginate(30)->withQueryString(),
            'pendingReviews' => MissionCompletion::query()->where('status', 'pending_review')->count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.missions.form', ['mission' => new Mission(['status' => 'active', 'repeat' => 'once', 'reward' => '0.10']), 'types' => MissionType::cases(), 'verifications' => MissionVerification::cases()]);
    }

    public function store(MissionRequest $request): RedirectResponse
    {
        $mission = Mission::query()->create($request->missionData());
        $this->audit->log('mission.created', $mission, $request->missionData());

        return redirect()->route('admin.missions.index')->with('success', 'Mission created.');
    }

    public function edit(Mission $mission): View
    {
        return view('admin.missions.form', ['mission' => $mission, 'types' => MissionType::cases(), 'verifications' => MissionVerification::cases()]);
    }

    public function update(MissionRequest $request, Mission $mission): RedirectResponse
    {
        $before = $mission->only(array_keys($request->missionData()));
        $mission->update($request->missionData());
        $this->audit->log('mission.updated', $mission, ['before' => $before, 'after' => $request->missionData()]);

        return redirect()->route('admin.missions.index')->with('success', 'Mission updated.');
    }

    public function duplicate(Mission $mission): RedirectResponse
    {
        $copy = $mission->replicate(['budget_used', 'completions_count', 'deleted_at']);
        $copy->title = mb_substr($mission->title.' (copy)', 0, 120);
        $copy->status = 'paused';
        $copy->save();
        $this->audit->log('mission.duplicated', $copy, ['source_id' => $mission->id]);

        return redirect()->route('admin.missions.edit', $copy)->with('success', 'Mission duplicated (paused). Review and activate it.');
    }

    public function status(Request $request, Mission $mission): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:active,paused,completed']]);
        $from = $mission->status;
        $mission->update(['status' => $data['status']]);
        $this->audit->log('mission.status_changed', $mission, ['from' => $from, 'to' => $data['status']]);

        return back()->with('success', 'Mission '.$data['status'].'.');
    }

    public function destroy(Mission $mission): RedirectResponse
    {
        // Missions with history are soft deleted so completions and ledger references stay intact.
        if ($mission->completions()->where('status', 'pending_review')->exists()) {
            return back()->with('error', 'Review the pending submissions of this mission before deleting it.');
        }

        $mission->delete();
        $this->audit->log('mission.deleted', $mission);

        return redirect()->route('admin.missions.index')->with('success', 'Mission deleted.');
    }
}
