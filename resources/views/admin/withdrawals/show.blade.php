@extends('admin.layout')
@section('title', 'Withdrawal '.$w->reference)
@section('content')
@if ($sharedAddress->isNotEmpty())
    <div class="alert alert-danger"><strong>This address was also used by other accounts:</strong>
        @foreach ($sharedAddress as $other) <a href="{{ route('admin.users.show', $other->user) }}">{{ $other->user->publicId() }}</a> @endforeach
    </div>
@endif
@if ($flags->isNotEmpty())
    <div class="alert alert-warn"><strong>The user has {{ $flags->count() }} open fraud signal(s):</strong> {{ $flags->pluck('type')->map(fn ($t) => str_replace('_', ' ', $t))->implode(', ') }}</div>
@endif

<div class="grid grid-sidebar">
    <div>
        <section class="panel">
            <div class="panel-head"><h2>{{ $w->reference }}</h2><x-admin.badge :status="$w->status" /></div>
            <dl class="kv">
                <dt>User</dt><dd>@include('admin.partials.user-link', ['u' => $user])</dd>
                <dt>Recipient name</dt><dd>{{ $w->full_name }} <span class="muted">(public alias: {{ $w->public_alias }})</span></dd>
                <dt>Network</dt><dd>{{ $w->network }}</dd>
                <dt>Address</dt><dd class="mono break">{{ $w->address }} <a href="#" class="small" data-copy="{{ $w->address }}">Copy</a></dd>
                <dt>Requested amount</dt><dd>{{ usdt($w->amount, 6) }} USDT</dd>
                <dt>Fee</dt><dd>{{ usdt($w->fee, 6) }} USDT</dd>
                <dt>Amount to send</dt><dd><strong style="font-size:18px">{{ usdt($w->net_amount, 6) }} USDT</strong> <a href="#" class="small" data-copy="{{ \App\Support\Money::str($w->net_amount) }}">Copy</a></dd>
                <dt>User note</dt><dd class="pre">{{ $w->note ?: '—' }}</dd>
                @if ($w->reject_reason)<dt>Rejection reason</dt><dd>{{ $w->reject_reason }}</dd>@endif
                @if ($w->tx_hash)<dt>Transaction hash</dt><dd class="mono break">@if ($explorer)<a href="{{ $explorer }}" target="_blank" rel="noopener noreferrer">{{ $w->tx_hash }}</a>@else{{ $w->tx_hash }}@endif</dd>@endif
                @if ($w->payment_reference)<dt>Payment reference</dt><dd>{{ $w->payment_reference }}</dd>@endif
                <dt>Published</dt><dd>{{ $w->published_at ? biz_date($w->published_at) : 'No' }}</dd>
            </dl>
        </section>

        <section class="panel">
            <h3>Timeline</h3>
            <dl class="kv">
                <dt>Requested</dt><dd>{{ biz_date($w->created_at) }}</dd>
                <dt>Approved</dt><dd>{{ $w->approved_at ? biz_date($w->approved_at).' by '.$w->approver?->name : '—' }}</dd>
                <dt>Processing</dt><dd>{{ $w->processing_at ? biz_date($w->processing_at).' by '.$w->processor?->name : '—' }}</dd>
                <dt>Paid</dt><dd>{{ $w->paid_at ? biz_date($w->paid_at).' by '.$w->payer?->name : '—' }}</dd>
                <dt>Rejected</dt><dd>{{ $w->rejected_at ? biz_date($w->rejected_at).' by '.$w->rejecter?->name : '—' }}</dd>
                <dt>Cancelled by user</dt><dd>{{ $w->cancelled_at ? biz_date($w->cancelled_at) : '—' }}</dd>
            </dl>
        </section>

        <section class="panel">
            <h3>Previous withdrawals of this user</h3>
            <div class="table-wrap"><table>
                <thead><tr><th>Reference</th><th class="num">Amount</th><th>Address</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>@forelse ($history as $h)<tr><td><a href="{{ route('admin.withdrawals.show', $h) }}">{{ $h->reference }}</a></td><td class="num">{{ usdt($h->amount) }}</td><td class="mono small">{{ \Illuminate\Support\Str::limit($h->address, 16) }}</td><td><x-admin.badge :status="$h->status" /></td><td class="small">{{ biz_date($h->created_at) }}</td></tr>@empty<tr><td colspan="5" class="muted">First withdrawal.</td></tr>@endforelse</tbody>
            </table></div>
        </section>
    </div>

    <aside>
        <section class="panel">
            <h3>Account</h3>
            <dl class="kv">
                <dt>Status</dt><dd><x-admin.badge :status="$user->status" /></dd>
                <dt>Registered</dt><dd>{{ biz_date($user->created_at) }}</dd>
                <dt>Games played</dt><dd>{{ $gamesPlayed }}</dd>
                <dt>Available</dt><dd>{{ usdt($user->wallet?->available) }} USDT</dd>
                <dt>Reserved</dt><dd>{{ usdt($user->wallet?->reserved) }} USDT</dd>
                <dt>Total earned</dt><dd>{{ usdt($user->wallet?->total_earned) }} USDT</dd>
                <dt>Withdrawn</dt><dd>{{ usdt($user->wallet?->total_withdrawn) }} USDT</dd>
            </dl>
            <a class="btn btn-ghost btn-sm mt" href="{{ route('admin.users.show', $user) }}">Full profile & ledger</a>
        </section>

        @adminCan('withdrawals.manage')
        <section class="panel">
            <h3>Actions</h3>
            @php $status = $w->status; @endphp

            @if ($status->canTransitionTo(\App\Enums\WithdrawalStatus::Approved))
                <details class="action-box" open><summary>Approve for payment</summary>
                    <p class="small muted">Approval does not send money. Pay the net amount from the operator wallet, then record the transaction below.</p>
                    <form method="post" action="{{ route('admin.withdrawals.approve', $w) }}" data-confirm="Approve {{ $w->reference }} for payment?">
                        @csrf <x-admin.confirm-password /> <button class="btn btn-ok">Approve</button>
                    </form>
                </details>
            @endif

            @if ($status->canTransitionTo(\App\Enums\WithdrawalStatus::Processing))
                <details class="action-box" open><summary>Mark as processing</summary>
                    <p class="small muted">Use when you start the payment, so other reviewers know it is in progress.</p>
                    <form method="post" action="{{ route('admin.withdrawals.processing', $w) }}" data-confirm="Mark {{ $w->reference }} as processing?">
                        @csrf <x-admin.confirm-password /> <button class="btn">Mark processing</button>
                    </form>
                </details>
            @endif

            @if ($status->canTransitionTo(\App\Enums\WithdrawalStatus::Paid))
                <details class="action-box" open><summary>Record completed payment</summary>
                    <p class="small muted">Only after the transfer of <strong>{{ usdt($w->net_amount, 6) }} USDT</strong> to the address above is confirmed on-chain. This publishes a masked confirmation to the public channel.</p>
                    <form method="post" action="{{ route('admin.withdrawals.paid', $w) }}" data-confirm="Record {{ $w->reference }} as PAID? This cannot be undone.">
                        @csrf
                        <label class="field"><span>Transaction hash</span><input class="input mono" name="tx_hash" required pattern="[A-Za-z0-9]{16,128}" value="{{ old('tx_hash') }}"></label>
                        <label class="field"><span>Internal payment reference (optional)</span><input class="input" name="payment_reference" maxlength="128" value="{{ old('payment_reference') }}"></label>
                        <label class="check"><input type="checkbox" name="verified" value="1" required> I verified this transaction on the blockchain explorer.</label>
                        <x-admin.confirm-password />
                        <button class="btn btn-ok">Mark as paid</button>
                    </form>
                </details>
            @endif

            @if ($status->canTransitionTo(\App\Enums\WithdrawalStatus::Rejected))
                <details class="action-box"><summary>Reject and return funds</summary>
                    <form method="post" action="{{ route('admin.withdrawals.reject', $w) }}" data-confirm="Reject {{ $w->reference }}? The reserved amount returns to the user's balance.">
                        @csrf
                        <label class="field"><span>Reason (sent to the user)</span><input class="input" name="reason" required minlength="3" maxlength="250"></label>
                        <x-admin.confirm-password />
                        <button class="btn btn-danger">Reject</button>
                    </form>
                </details>
            @endif

            @if (! $status->isOpen())<p class="muted">This request is final ({{ $status->label() }}). No further actions are possible.</p>@endif
        </section>
        @endadminCan
    </aside>
</div>
@endsection
