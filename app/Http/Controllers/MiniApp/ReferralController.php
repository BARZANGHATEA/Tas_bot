<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\Settings;
use App\Services\Telegram\Links;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly Settings $settings,
        private readonly Links $links,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user('miniapp');

        $direct = User::query()->where('referrer_id', $user->id);
        $rewards = ReferralReward::query()->with('sourceUser')->where('beneficiary_id', $user->id);

        $earned = (clone $rewards)->where('status', 'credited')->pluck('amount')
            ->reduce(fn (BigDecimal $c, $v) => $c->plus(Money::of($v)), Money::zero());

        $link = $this->links->referralLink($user);

        return response()->json([
            'code' => $user->referral_code,
            'link' => $link,
            'share_url' => $this->links->shareUrl($link, 'Roll dice with me and earn USDT rewards! 🎲'),
            'direct_count' => (clone $direct)->count(),
            'qualified_count' => (clone $direct)->whereNotNull('referral_qualified_at')->count(),
            'earned' => Money::format($earned),
            'levels' => collect($this->referrals->levelRules())->map(fn ($rule, $level) => [
                'level' => $level,
                'fixed' => Money::format($rule['fixed']),
                'percent' => (string) $rule['percent'],
            ])->values(),
            'qualification' => [
                'min_games' => $this->settings->int('referral.qualify_min_games'),
                'min_age_hours' => $this->settings->int('referral.qualify_min_age_hours'),
            ],
            'active' => $this->referrals->isCampaignActive(),
            'disclosure' => $this->settings->string('referral.disclosure'),
            'friends' => (clone $direct)->latest('id')->limit(30)->get()->map(fn (User $f) => [
                'name' => $f->first_name,
                'joined_at' => $f->created_at->toIso8601String(),
                'qualified' => $f->referral_qualified_at !== null,
            ]),
            'rewards' => (clone $rewards)->latest('id')->limit(30)->get()->map(fn (ReferralReward $r) => [
                'amount' => Money::format($r->amount),
                'level' => $r->level,
                'event' => $r->event,
                'status' => $r->status,
                'from' => $r->level === 1 ? $r->sourceUser?->first_name : $r->sourceUser?->publicId(),
                'created_at' => $r->created_at->toIso8601String(),
            ]),
        ]);
    }
}
