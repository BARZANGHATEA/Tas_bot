<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Services\Settings;
use App\Services\StatsService;
use App\Services\Telegram\Links;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __construct(
        private readonly StatsService $stats,
        private readonly Settings $settings,
        private readonly Links $links,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user('miniapp');

        return response()->json([
            'user' => [
                'id' => $user->publicId(),
                'name' => $user->displayName(),
                'first_name' => $user->first_name,
                'username' => $user->username,
                'photo_url' => $user->photo_url,
                'initials' => $user->initials(),
                'status' => $user->status->value,
                'member_since' => $user->created_at->toIso8601String(),
            ],
            'stats' => $this->stats->playerSummary($user),
            'transactions' => $this->stats->recentTransactions($user, $this->settings->int('home.recent_transactions', 8)),
            'home' => [
                'headline' => $this->settings->string('home.headline'),
                'announcement' => $this->settings->string('home.announcement'),
            ],
            'referral_link' => $this->links->referralLink($user),
            'restricted' => ! $user->isActive(),
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user('miniapp');
        $page = LedgerEntry::query()->where('user_id', $user->id)->latest('id')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn ($e) => $this->stats->presentEntry($e)),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }
}
