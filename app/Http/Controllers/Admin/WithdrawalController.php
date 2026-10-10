<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use App\Services\AuditLogger;
use App\Services\ReferralService;
use App\Services\WithdrawalService;
use App\Support\Money;
use App\Support\TableSort;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WithdrawalController extends AdminController
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    public function index(Request $request): View
    {
        $query = $this->filtered($request)->with('user');
        $sort = TableSort::apply($query, $request, [
            'requested' => 'id',
            'amount' => 'amount',
            'status' => 'status',
            'network' => 'network',
        ], 'requested', $request->query('status') === 'pending' ? 'asc' : 'desc');

        return view('admin.withdrawals.index', [
            'withdrawals' => $query->paginate(TableSort::perPage($request))->withQueryString(),
            'sort' => $sort,
            'statuses' => WithdrawalStatus::cases(),
            'networks' => $this->withdrawals->networks(false),
            'counts' => Withdrawal::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function show(Withdrawal $withdrawal, ReferralService $referrals): View
    {
        $withdrawal->load(['user.wallet', 'approver', 'processor', 'payer', 'rejecter']);
        $user = $withdrawal->user;

        return view('admin.withdrawals.show', [
            'w' => $withdrawal,
            'user' => $user,
            'history' => Withdrawal::query()->where('user_id', $user->id)->where('id', '!=', $withdrawal->id)->latest('id')->limit(10)->get(),
            'sharedAddress' => Withdrawal::query()->with('user')->where('address', $withdrawal->address)->where('user_id', '!=', $user->id)->limit(10)->get(),
            'flags' => $user->fraudFlags()->where('status', 'open')->get(),
            'gamesPlayed' => $referrals->gamesPlayed($user),
            'explorer' => $this->withdrawals->explorerUrl(collect($this->withdrawals->networks(false))->firstWhere('code', $withdrawal->network), $withdrawal->tx_hash),
        ]);
    }

    public function approve(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $request->validate($this->confirmRules());
        $this->withdrawals->approve($withdrawal, $this->admin());

        return back()->with('success', "Withdrawal {$withdrawal->reference} approved for payment.");
    }

    public function processing(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $request->validate($this->confirmRules());
        $this->withdrawals->markProcessing($withdrawal, $this->admin());

        return back()->with('success', "Withdrawal {$withdrawal->reference} marked as processing.");
    }

    public function paid(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $data = $request->validate($this->confirmRules() + [
            'tx_hash' => ['required', 'string', 'regex:/^[A-Za-z0-9]{16,128}$/'],
            'payment_reference' => ['nullable', 'string', 'max:128'],
            'verified' => ['accepted'],
        ], ['verified.accepted' => 'Confirm that you verified the payment on the blockchain.']);

        $this->withdrawals->markPaid($withdrawal, $this->admin(), $data['tx_hash'], $data['payment_reference'] ?? null);

        return back()->with('success', "Withdrawal {$withdrawal->reference} recorded as paid.");
    }

    public function reject(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $data = $request->validate($this->confirmRules() + ['reason' => ['required', 'string', 'min:3', 'max:250']]);
        $this->withdrawals->reject($withdrawal, $this->admin(), $data['reason']);

        return back()->with('success', "Withdrawal {$withdrawal->reference} rejected; funds returned to the user.");
    }

    public function export(Request $request, AuditLogger $audit)
    {
        $audit->log('report.exported', null, ['report' => 'withdrawals', 'filters' => $request->query()]);

        $rows = (function () use ($request) {
            foreach ($this->filtered($request)->with('user')->orderBy('id')->lazy(500) as $w) {
                yield [$w->reference, $w->user->publicId(), $w->user->telegram_id, $w->full_name, $w->network, $w->address,
                    Money::str($w->amount), Money::str($w->fee), Money::str($w->net_amount), $w->status->value, $w->tx_hash,
                    $w->payment_reference, $w->created_at?->toIso8601String(), $w->paid_at?->toIso8601String()];
            }
        })();

        return $this->csv('withdrawals-'.now()->format('Ymd-His').'.csv',
            ['reference', 'user', 'telegram_id', 'full_name', 'network', 'address', 'amount', 'fee', 'net', 'status', 'tx_hash', 'payment_reference', 'created_at', 'paid_at'],
            $rows);
    }

    private function filtered(Request $request)
    {
        $query = Withdrawal::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($network = $request->query('network')) {
            $query->where('network', $network);
        }
        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', $search)
                    ->orWhere('tx_hash', $search)
                    ->orWhere('payment_reference', $search)
                    ->orWhere('address', $search)
                    ->orWhereHas('user', function ($u) use ($search) {
                        $id = preg_match('/^U?0*(\d+)$/i', $search, $m) ? (int) $m[1] : -1;
                        $u->where('id', $id)->orWhere('telegram_id', is_numeric($search) ? (int) $search : -1)
                            ->orWhere('username', ltrim($search, '@'));
                    });
            });
        }
        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', CarbonImmutable::parse($from)->startOfDay());
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', CarbonImmutable::parse($to)->endOfDay());
        }
        if (is_numeric($request->query('min'))) {
            $query->where('amount', '>=', $request->query('min'));
        }
        if (is_numeric($request->query('max'))) {
            $query->where('amount', '<=', $request->query('max'));
        }

        return $query;
    }
}
