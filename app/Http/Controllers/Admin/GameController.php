<?php

namespace App\Http\Controllers\Admin;

use App\Models\GameMatch;
use App\Models\GameRound;
use App\Services\MatchService;
use App\Support\TableSort;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only: settled results can be inspected but never edited. */
class GameController extends AdminController
{
    public function rounds(Request $request): View
    {
        $query = GameRound::query()->with('user');
        if ($request->query('result') === 'win') {
            $query->where('is_win', true);
        } elseif ($request->query('result') === 'loss') {
            $query->where('is_win', false);
        }
        if ($userId = $request->query('user')) {
            $query->where('user_id', (int) ltrim((string) $userId, 'Uu0'));
        }

        $sort = TableSort::apply($query, $request, ['date' => 'id', 'reward' => 'reward'], 'date');

        return view('admin.games.rounds', [
            'rounds' => $query->paginate(TableSort::perPage($request, 50))->withQueryString(),
            'sort' => $sort,
            'totals' => [
                'all' => GameRound::query()->count(),
                'wins' => GameRound::query()->where('is_win', true)->count(),
                'unfunded' => GameRound::query()->where('reward_status', 'unfunded')->count(),
            ],
        ]);
    }

    public function matches(Request $request): View
    {
        $query = GameMatch::query()->with(['creator', 'opponent', 'winner']);
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $sort = TableSort::apply($query, $request, ['date' => 'id', 'status' => 'status'], 'date');

        return view('admin.games.matches', [
            'matches' => $query->paginate(TableSort::perPage($request))->withQueryString(),
            'sort' => $sort,
            'counts' => GameMatch::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function match(GameMatch $match, MatchService $service): View
    {
        $match->load(['creator', 'opponent', 'winner', 'rolls.user']);

        return view('admin.games.match', ['match' => $match]);
    }
}
