<?php

namespace App\Http\Controllers\Admin;

use App\Models\FraudFlag;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\ReferralService;
use App\Support\TableSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReferralController extends AdminController
{
    public function index(Request $request, ReferralService $referrals): View
    {
        $query = ReferralReward::query()->with(['beneficiary', 'sourceUser']);
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($user = $request->query('user')) {
            $id = (int) preg_replace('/\D/', '', (string) $user);
            $query->where(fn ($q) => $q->where('beneficiary_id', $id)->orWhere('source_user_id', $id));
        }

        return view('admin.referrals.index', [
            'sort' => $sort = TableSort::apply($query, $request, ['date' => 'id', 'amount' => 'amount', 'level' => 'level'], 'date'),
            'rewards' => $query->paginate(TableSort::perPage($request))->withQueryString(),
            'topReferrers' => User::query()->withCount([
                'referrals',
                'referrals as qualified_count' => fn ($q) => $q->whereNotNull('referral_qualified_at'),
            ])->whereHas('referrals')->orderByDesc('referrals_count')->limit(10)->get(),
            'suspicious' => FraudFlag::query()->with('user')->where('status', 'open')
                ->whereIn('type', ['referral_velocity', 'referral_shared_network', 'referral_cluster'])->latest('id')->limit(20)->get(),
            'levels' => $referrals->levelRules(),
            'active' => $referrals->isCampaignActive(),
        ]);
    }

    public function reverse(Request $request, ReferralReward $reward, ReferralService $referrals): RedirectResponse
    {
        $data = $request->validate($this->confirmRules() + ['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $referrals->reverse($reward, $data['reason'], $this->admin());

        return back()->with('success', 'Referral reward reversed.');
    }
}
