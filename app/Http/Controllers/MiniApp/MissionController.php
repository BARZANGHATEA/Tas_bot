<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\Mission;
use App\Services\MissionService;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MissionController extends Controller
{
    public function __construct(
        private readonly MissionService $missions,
        private readonly Settings $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => $this->settings->bool('missions.enabled'),
            'missions' => $this->missions->listFor($request->user('miniapp')),
        ]);
    }

    public function start(Request $request, Mission $mission): JsonResponse
    {
        $completion = $this->missions->start($request->user('miniapp'), $mission);

        return response()->json(['status' => $completion->status->value, 'action_url' => $mission->actionUrl()]);
    }

    public function claim(Request $request, Mission $mission): JsonResponse
    {
        $data = $request->validate(['proof' => ['nullable', 'string', 'max:500']]);
        $completion = $this->missions->claim($request->user('miniapp'), $mission, $data['proof'] ?? null);

        return response()->json([
            'status' => $completion->status->value,
            'message' => $completion->status->value === 'rewarded'
                ? 'Mission complete! Reward added to your balance.'
                : 'Submitted! A moderator will review it shortly.',
        ]);
    }
}
