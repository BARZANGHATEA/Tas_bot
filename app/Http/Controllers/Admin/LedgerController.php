<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LedgerType;
use App\Models\LedgerEntry;
use App\Models\ReferralReward;
use App\Services\AuditLogger;
use App\Services\WalletService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LedgerController extends AdminController
{
    public function index(Request $request): View
    {
        return view('admin.ledger.index', [
            'entries' => $this->filtered($request)->with(['user', 'admin', 'reversal'])->latest('id')->paginate(40)->withQueryString(),
            'types' => LedgerType::cases(),
        ]);
    }

    public function reverse(Request $request, LedgerEntry $entry, WalletService $wallet, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate($this->confirmRules() + ['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $reversal = $wallet->reverse($entry, $data['reason'], $this->admin());

        // Keep the referral record in sync when a referral credit is reversed from here.
        ReferralReward::query()->where('ledger_entry_id', $entry->id)->where('status', 'credited')
            ->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $this->admin()->id, 'reverse_reason' => $data['reason']]);

        $audit->log('ledger.reversed', $entry, ['reason' => $data['reason'], 'reversal' => $reversal->uuid, 'amount' => $entry->available_delta], user: $entry->user);

        return back()->with('success', 'Transaction reversed.');
    }

    public function export(Request $request, AuditLogger $audit)
    {
        $audit->log('report.exported', null, ['report' => 'ledger', 'filters' => $request->query()]);

        $rows = (function () use ($request) {
            foreach ($this->filtered($request)->with('user')->orderBy('id')->lazy(1000) as $e) {
                yield [$e->uuid, $e->created_at?->toIso8601String(), $e->user?->publicId(), $e->type->value,
                    Money::str($e->available_delta), Money::str($e->reserved_delta), Money::str($e->available_after),
                    Money::str($e->reserved_after), $e->reference_type, $e->reference_id, $e->description, $e->admin_id];
            }
        })();

        return $this->csv('ledger-'.now()->format('Ymd-His').'.csv',
            ['uuid', 'created_at', 'user', 'type', 'available_delta', 'reserved_delta', 'available_after', 'reserved_after', 'reference_type', 'reference_id', 'description', 'admin_id'],
            $rows);
    }

    private function filtered(Request $request)
    {
        $query = LedgerEntry::query();
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($user = $request->query('user')) {
            $query->where('user_id', (int) preg_replace('/\D/', '', (string) $user));
        }
        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', CarbonImmutable::parse($from)->startOfDay());
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', CarbonImmutable::parse($to)->endOfDay());
        }

        return $query;
    }
}
