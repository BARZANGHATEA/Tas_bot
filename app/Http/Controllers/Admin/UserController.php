<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LedgerType;
use App\Enums\UserStatus;
use App\Models\GameMatch;
use App\Models\GameRound;
use App\Models\LedgerEntry;
use App\Models\MissionCompletion;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\AuditLogger;
use App\Services\ReferralService;
use App\Services\Settings;
use App\Services\StatsService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends AdminController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $query = User::query()->with('wallet')->withCount('referrals');

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($search) {
                $id = preg_match('/^U?0*(\d+)$/i', $search, $m) ? (int) $m[1] : null;
                if ($id !== null) {
                    $q->orWhere('id', $id)->orWhere('telegram_id', (int) $search);
                }
                $q->orWhere('username', 'like', '%'.ltrim($search, '@').'%')
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('referral_code', strtoupper($search));
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($request->boolean('flagged')) {
            $query->where('is_flagged', true);
        }

        return view('admin.users.index', [
            'users' => $query->latest('id')->paginate(25)->withQueryString(),
        ]);
    }

    public function show(User $user, StatsService $stats, ReferralService $referrals): View
    {
        $user->load(['wallet', 'referrer']);

        return view('admin.users.show', [
            'user' => $user,
            'summary' => $stats->playerSummary($user),
            'upline' => $referrals->ancestors($user, 10),
            'ledger' => LedgerEntry::query()->where('user_id', $user->id)->with('reversal')->latest('id')->paginate(15, ['*'], 'ledger_page'),
            'rounds' => GameRound::query()->where('user_id', $user->id)->latest('id')->limit(15)->get(),
            'matches' => GameMatch::query()->with(['creator', 'opponent'])->where(fn ($q) => $q->where('creator_id', $user->id)->orWhere('opponent_id', $user->id))->latest('id')->limit(10)->get(),
            'referralsList' => User::query()->where('referrer_id', $user->id)->latest('id')->limit(20)->get(),
            'referralRewards' => ReferralReward::query()->with('sourceUser')->where('beneficiary_id', $user->id)->latest('id')->limit(20)->get(),
            'missions' => MissionCompletion::query()->with('mission')->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'withdrawals' => Withdrawal::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'flags' => $user->fraudFlags()->latest('id')->get(),
            'gamesPlayed' => $referrals->gamesPlayed($user),
        ]);
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
            'reason' => ['required_unless:status,active', 'nullable', 'string', 'max:255'],
        ]);

        $old = $user->status->value;
        $user->forceFill(['status' => $data['status'], 'status_reason' => $data['reason'] ?? null])->save();

        if ($data['status'] === UserStatus::Suspended->value) {
            $user->appSessions()->delete(); // sign out everywhere
        }

        $this->audit->log('user.status_changed', $user, ['from' => $old, 'to' => $data['status'], 'reason' => $data['reason'] ?? null]);

        return back()->with('success', 'Account status updated.');
    }

    public function updateNote(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:5000']]);
        $user->forceFill(['admin_note' => $data['admin_note']])->save();
        $this->audit->log('user.note_updated', $user, ['length' => Str::length((string) $data['admin_note'])]);

        return back()->with('success', 'Note saved.');
    }

    public function toggleFlag(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['flagged' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);
        $user->forceFill(['is_flagged' => $data['flagged']])->save();

        if ($data['flagged']) {
            app(\App\Services\FraudService::class)->flag($user, 'manual', 'medium', ['reason' => $data['reason'] ?? null, 'admin_id' => $this->admin()->id]);
        }

        $this->audit->log($data['flagged'] ? 'user.flagged' : 'user.unflagged', $user, ['reason' => $data['reason'] ?? null]);

        return back()->with('success', $data['flagged'] ? 'User flagged for review.' : 'Flag removed.');
    }

    public function adjust(Request $request, User $user, WalletService $wallet, Settings $settings): RedirectResponse
    {
        $data = $request->validate($this->confirmRules() + [
            'direction' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,6})?$/'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $amount = Money::of($data['amount']);
        $max = $settings->money('admin.max_adjustment');
        if (! $amount->isPositive() || ($max->isPositive() && $amount->isGreaterThan($max))) {
            return back()->with('error', 'Adjustments must be between 0 and '.Money::format($max).' USDT per action.');
        }

        $entry = $data['direction'] === 'credit'
            ? $wallet->credit($user, $amount, LedgerType::AdminCredit, 'admin-credit:'.Str::uuid(), null, $data['reason'], ['reason' => $data['reason']], $this->admin())
            : $wallet->adminDebit($user, $amount, $data['reason'], $this->admin());

        $this->audit->log('wallet.adjusted', $user, [
            'direction' => $data['direction'], 'amount' => Money::str($amount), 'reason' => $data['reason'], 'ledger_entry' => $entry->uuid,
        ]);

        return back()->with('success', 'Balance adjusted ('.$data['direction'].' '.Money::format($amount).' USDT).');
    }

    public function export(Request $request)
    {
        $this->audit->log('report.exported', null, ['report' => 'users']);

        $rows = (function () {
            foreach (User::query()->with('wallet')->withCount('referrals')->orderBy('id')->lazy(500) as $u) {
                yield [$u->publicId(), $u->telegram_id, $u->username, $u->displayName(), $u->status->value, $u->is_flagged ? 'yes' : 'no',
                    $u->referrer_id, $u->referrals_count, Money::str($u->wallet?->available), Money::str($u->wallet?->reserved),
                    Money::str($u->wallet?->total_earned), Money::str($u->wallet?->total_withdrawn), $u->created_at?->toIso8601String()];
            }
        })();

        return $this->csv('users-'.now()->format('Ymd-His').'.csv',
            ['id', 'telegram_id', 'username', 'name', 'status', 'flagged', 'referrer_id', 'referrals', 'available', 'reserved', 'total_earned', 'total_withdrawn', 'registered_at'],
            $rows);
    }
}
