<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Services\MatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function __construct(private readonly MatchService $matches) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user('miniapp');

        return response()->json([
            'open' => $this->matches->openPublicMatches($user)->map(fn (GameMatch $m) => [
                'uuid' => $m->uuid,
                'creator' => $m->creator->displayName(),
                'rounds' => $m->total_rounds,
                'dice_count' => $m->dice_count,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
            'mine' => $this->matches->matchesFor($user)->map(fn (GameMatch $m) => $this->matches->present($m, $user)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visibility' => ['required', 'in:public,private'],
            'invite' => ['nullable', 'string', 'max:64', 'regex:/^@?[A-Za-z0-9_]{3,64}$/'],
        ]);

        $user = $request->user('miniapp');
        $match = $this->matches->create($user, $data['visibility'], $data['invite'] ?? null);

        return response()->json(['match' => $this->matches->present($match, $user)], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user('miniapp');
        $match = $this->find($uuid);
        $code = $request->query('code');

        abort_unless($this->matches->canView($user, $match, is_string($code) ? $code : null), 404);

        return response()->json(['match' => $this->matches->present($match, $user)]);
    }

    /** Resolve an invitation deep link (start_param "m_<code>"). */
    public function byCode(Request $request, string $code): JsonResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{20}$/', $code) === 1, 404);
        $user = $request->user('miniapp');
        $match = GameMatch::query()->where('invite_code', $code)->firstOrFail();

        abort_unless($this->matches->canView($user, $match, $code), 404);

        return response()->json(['match' => $this->matches->present($match, $user), 'code' => $code]);
    }

    public function join(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:32']]);
        $user = $request->user('miniapp');
        $match = $this->matches->join($user, $this->find($uuid), $data['code'] ?? null);

        return response()->json(['match' => $this->matches->present($match->fresh(), $user)]);
    }

    public function roll(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user('miniapp');
        ['match' => $match, 'roll' => $roll] = $this->matches->roll($user, $this->find($uuid));

        return response()->json([
            'roll' => ['round' => $roll->round_no, 'dice' => $roll->dice, 'total' => $roll->total],
            'match' => $this->matches->present($match, $user),
        ]);
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user('miniapp');
        $match = $this->matches->cancel($user, $this->find($uuid));

        return response()->json(['match' => $this->matches->present($match, $user)]);
    }

    private function find(string $uuid): GameMatch
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $uuid) === 1, 404);

        return GameMatch::query()->where('uuid', $uuid)->firstOrFail();
    }
}
