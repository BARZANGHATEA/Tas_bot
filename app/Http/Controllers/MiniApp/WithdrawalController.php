<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Http\Requests\MiniApp\StoreWithdrawalRequest;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use App\Support\Ip;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user('miniapp');

        return response()->json([
            'summary' => $this->withdrawals->summary($user),
            'history' => Withdrawal::query()->where('user_id', $user->id)->latest('id')->limit(30)->get()
                ->map(fn (Withdrawal $w) => $this->present($w)),
        ]);
    }

    public function store(StoreWithdrawalRequest $request): JsonResponse
    {
        $withdrawal = $this->withdrawals->request(
            $request->user('miniapp'),
            $request->validated(),
            $request->header('Idempotency-Key'),
            Ip::hash($request->ip()),
        );

        return response()->json(['withdrawal' => $this->present($withdrawal)], 201);
    }

    public function cancel(Request $request, string $reference): JsonResponse
    {
        $user = $request->user('miniapp');
        $withdrawal = Withdrawal::query()->where('reference', $reference)->where('user_id', $user->id)->firstOrFail();

        return response()->json(['withdrawal' => $this->present($this->withdrawals->cancel($withdrawal, $user))]);
    }

    private function present(Withdrawal $w): array
    {
        return [
            'reference' => $w->reference,
            'amount' => Money::format($w->amount),
            'fee' => Money::format($w->fee),
            'net_amount' => Money::format($w->net_amount),
            'network' => $w->network,
            // The owner sees their own address, shortened for the list.
            'address' => mb_substr($w->address, 0, 6).'…'.mb_substr($w->address, -6),
            'status' => $w->status->value,
            'status_label' => $w->status->label(),
            'reject_reason' => $w->reject_reason,
            'tx_hash' => $w->tx_hash,
            'can_cancel' => $w->status->value === 'pending',
            'created_at' => $w->created_at->toIso8601String(),
            'paid_at' => $w->paid_at?->toIso8601String(),
        ];
    }
}
