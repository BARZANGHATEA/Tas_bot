<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\GameRound;
use App\Services\GameEngine;
use App\Services\MatchService;
use App\Services\StatsService;
use App\Support\Ip;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function __construct(
        private readonly GameEngine $engine,
        private readonly MatchService $matches,
        private readonly StatsService $stats,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user('miniapp');
        $rules = $this->engine->rules();

        return response()->json([
            'single' => $rules + [
                'reward_display' => Money::format($rules['reward']),
                'cooldown_remaining' => $this->engine->cooldownRemaining($user),
                'played_today' => $this->engine->roundsToday($user),
            ],
            'multi' => $this->matches->rules(),
            'recent_rounds' => GameRound::query()->where('user_id', $user->id)->latest('id')->limit(10)->get()
                ->map(fn (GameRound $r) => $this->presentRound($r)),
        ]);
    }

    /**
     * Rolls on the server. The request body is ignored: dice values, outcome
     * and reward can never be supplied by the client.
     */
    public function play(Request $request): JsonResponse
    {
        $user = $request->user('miniapp');

        ['round' => $round, 'replayed' => $replayed] = $this->engine->play(
            $user,
            $request->header('Idempotency-Key'),
            Ip::hash($request->ip()),
        );

        return response()->json([
            'round' => $this->presentRound($round),
            'replayed' => $replayed,
            'balance' => $this->stats->playerSummary($user)['available'],
            'cooldown_remaining' => $this->engine->cooldownRemaining($user),
            'played_today' => $this->engine->roundsToday($user),
        ]);
    }

    private function presentRound(GameRound $round): array
    {
        return [
            'id' => $round->uuid,
            'dice' => [$round->die_one, $round->die_two],
            'is_win' => $round->is_win,
            'reward' => Money::format($round->reward),
            'reward_status' => $round->reward_status,
            'created_at' => $round->created_at?->toIso8601String(),
        ];
    }
}
