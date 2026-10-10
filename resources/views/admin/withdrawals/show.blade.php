@extends('admin.layout')
@section('title', 'Withdrawal '.$w->reference)
@use('App\Enums\WithdrawalStatus', 'WS')
@section('content')
@php
    $status = $w->status;
    $failed = in_array($status, [WS::Rejected, WS::Cancelled], true);
    $order = [WS::Pending, WS::Approved, WS::Processing, WS::Paid];
    $reached = array_search($status, $order, true);
    $steps = [
        ['Requested by player', $w->created_at, null],
        ['Approved for payment', $w->approved_at, $w->approver?->name],
        ['Payment in progress', $w->processing_at, $w->processor?->name],
        ['Paid and settled', $w->paid_at, $w->payer?->name],
    ];
@endphp
<nav class="breadcrumb" aria-label="Breadcrumb"><a href="{{ route('admin.withdrawals.index') }}">Withdrawals</a><x-admin.icon name="chevron-right" size="xs" /><span aria-current="page">{{ $w->reference }}</span></nav>
<div class="page-header">
    <div>
        <h1><span class="mono" style="font-size:inherit">{{ $w->reference }}</span> <x-admin.badge :status="$status" /></h1>
        <p class="page-sub">Requested {{ biz_date($w->created_at) }} ({{ $w->created_at->diffForHumans() }}) by <a href="{{ route('admin.users.show', $user) }}">{{ $user->displayName() }}</a></p>
    </div>
    @adminCan('withdrawals.manage')
    <div class="page-actions">
        @if ($status->canTransitionTo(WS::Rejected))<button type="button" class="btn" data-dialog-open="reject-dialog"><x-admin.icon name="x-circle" size="sm" /> Reject</button>@endif
        @if ($status->canTransitionTo(WS::Approved))<button type="button" class="btn btn-primary" data-dialog-open="approve-dialog"><x-admin.icon name="check" size="sm" /> Approve</button>@endif
        @if ($status->canTransitionTo(WS::Processing))<button type="button" class="btn btn-primary" data-dialog-open="processing-dialog"><x-admin.icon name="clock" size="sm" /> Mark processing</button>@endif
        @if ($status->canTransitionTo(WS::Paid))<button type="button" class="btn btn-success" data-dialog-open="paid-dialog"><x-admin.icon name="check-circle" size="sm" /> Record payment</button>@endif
    </div>
    @endadminCan
</div>

@if ($sharedAddress->isNotEmpty())
    <div class="alert alert-danger"><x-admin.icon name="alert" /><div class="alert-body"><strong>This payout address is also used by other accounts:</strong>
        @foreach ($sharedAddress as $other) <a href="{{ route('admin.users.show', $other->user) }}">{{ $other->user->displayName() }} ({{ $other->user->publicId() }})</a>@if (! $loop->last),@endif @endforeach
    </div></div>
@endif
@if ($flags->isNotEmpty())
    <div class="alert alert-warning"><x-admin.icon name="shield" /><div class="alert-body"><strong>{{ $flags->count() }} open fraud signal(s) on this player:</strong> {{ $flags->pluck('type')->map(fn ($t) => str_replace('_', ' ', $t))->implode(', ') }}. <a href="{{ route('admin.users.show', $user) }}">Review the player</a></div></div>
@endif

<div class="grid grid-main">
    <div class="stack">
        <section class="card">
            <div class="card-header"><h2>Payment details</h2></div>
            <div class="card-body">
                <div class="summary">
                    <dl class="dl">
                        <dt>Requested</dt><dd><x-admin.money :amount="$w->amount" :decimals="6" /></dd>
                        <dt>Network fee</dt><dd><x-admin.money :amount="$w->fee" :decimals="6" /></dd>
                        <dt>Amount to send</dt><dd><span style="font-size:20px"><x-admin.money :amount="$w->net_amount" :decimals="6" /></span> <button type="button" class="btn btn-xs" data-copy="{{ \App\Support\Money::str($w->net_amount) }}"><x-admin.icon name="copy" size="xs" /> Copy</button></dd>
                    </dl>
                </div>
                <dl class="dl">
                    <dt>Network</dt><dd>{{ $w->network }}</dd>
                    <dt>Address</dt><dd><div class="copy-field"><code>{{ $w->address }}</code><button type="button" class="btn btn-xs" data-copy="{{ $w->address }}"><x-admin.icon name="copy" size="xs" /> Copy</button></div></dd>
                    <dt>Recipient name</dt><dd>{{ $w->full_name }} <span class="muted small">· public alias “{{ $w->public_alias }}”</span></dd>
                    <dt>Player note</dt><dd class="pre">{{ $w->note ?: '—' }}</dd>
                    @if ($w->reject_reason)<dt>Rejection reason</dt><dd>{{ $w->reject_reason }}</dd>@endif
                    @if ($w->tx_hash)
                        <dt>Transaction</dt><dd class="mono break">@if ($explorer)<a href="{{ $explorer }}" target="_blank" rel="noopener noreferrer">{{ $w->tx_hash }} <x-admin.icon name="external" size="xs" /></a>@else{{ $w->tx_hash }}@endif</dd>
                    @endif
                    @if ($w->payment_reference)<dt>Payment reference</dt><dd>{{ $w->payment_reference }}</dd>@endif
                    <dt>Public confirmation</dt><dd>{{ $w->published_at ? 'Posted '.biz_date($w->published_at) : 'Not posted' }}</dd>
                </dl>
            </div>
        </section>

        <section class="card card-flush">
            <div class="card-header"><h2>Other withdrawals by this player</h2></div>
            @if ($history->isEmpty())
                <x-admin.empty icon="banknote" title="First withdrawal" text="This player has no other withdrawal requests." />
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th scope="col">Request</th><th scope="col" class="num">Amount</th><th scope="col">Address</th><th scope="col">Status</th><th scope="col">Date</th></tr></thead>
                    <tbody>@foreach ($history as $h)
                        <tr><td><a class="mono" href="{{ route('admin.withdrawals.show', $h) }}">{{ $h->reference }}</a></td><td class="num"><x-admin.money :amount="$h->amount" :unit="false" /></td><td class="mono small">{{ \Illuminate\Support\Str::limit($h->address, 16, '…') }}@if ($h->address === $w->address) <span class="badge badge-neutral badge-plain">same</span>@endif</td><td><x-admin.badge :status="$h->status" /></td><td class="nowrap">{{ biz_date($h->created_at) }}</td></tr>
                    @endforeach</tbody>
                </table></div>
            @endif
        </section>
    </div>

    <aside class="stack" aria-label="Status and player">
        <section class="card">
            <div class="card-header"><h2>Status</h2></div>
            <div class="card-body">
                <ol class="timeline">
                    @foreach ($steps as $i => [$label, $at, $by])
                        @php
                            $state = $at ? 'is-done' : '';
                            if (! $failed && $reached !== false && $i === $reached + 1 && $status !== WS::Paid) { $state = 'is-current'; }
                        @endphp
                        <li class="{{ $state }}">
                            <span class="timeline-dot">@if ($at)<x-admin.icon name="check" />@endif</span>
                            <div><div class="timeline-title">{{ $label }}</div><div class="timeline-meta">{{ $at ? biz_date($at).($by ? ' · '.$by : '') : ($state === 'is-current' ? 'Next step' : 'Not reached') }}</div></div>
                        </li>
                    @endforeach
                    @if ($failed)
                        <li class="is-failed">
                            <span class="timeline-dot"><x-admin.icon name="x" /></span>
                            <div><div class="timeline-title">{{ $status === WS::Rejected ? 'Rejected – funds returned' : 'Cancelled by player – funds returned' }}</div><div class="timeline-meta">{{ biz_date($w->rejected_at ?? $w->cancelled_at) }}{{ $w->rejecter ? ' · '.$w->rejecter->name : '' }}</div></div>
                        </li>
                    @endif
                </ol>
                @if (! $status->isOpen())<p class="small muted mt-4 mb-0">This request is final. No further actions are possible.</p>@endif
            </div>
        </section>

        <section class="card">
            <div class="card-header"><h2>Player</h2><a class="btn btn-xs" href="{{ route('admin.users.show', $user) }}">Profile</a></div>
            <div class="card-body">
                <div class="mb-4"><x-admin.user :u="$user" /></div>
                <dl class="dl">
                    <dt>Status</dt><dd><x-admin.badge :status="$user->status" /></dd>
                    <dt>Member since</dt><dd>{{ $user->created_at->diffForHumans(null, true) }}</dd>
                    <dt>Games played</dt><dd>{{ number_format($gamesPlayed) }}</dd>
                    <dt>Available</dt><dd><x-admin.money :amount="$user->wallet?->available" /></dd>
                    <dt>Reserved</dt><dd><x-admin.money :amount="$user->wallet?->reserved" /></dd>
                    <dt>Total earned</dt><dd><x-admin.money :amount="$user->wallet?->total_earned" /></dd>
                    <dt>Withdrawn</dt><dd><x-admin.money :amount="$user->wallet?->total_withdrawn" /></dd>
                </dl>
            </div>
        </section>
    </aside>
</div>

@adminCan('withdrawals.manage')
    @if ($status->canTransitionTo(WS::Approved))
    <x-admin.modal id="approve-dialog" title="Approve for payment" description="Approval does not move any money. After approving, send the payment from your wallet and record it." icon="check" tone="success">
        <form method="post" action="{{ route('admin.withdrawals.approve', $w) }}">@csrf
            <div class="modal-body">
                <x-admin.form-errors dialog="approve-dialog" />
                <div class="summary"><dl class="dl"><dt>Send</dt><dd><x-admin.money :amount="$w->net_amount" :decimals="6" /></dd><dt>Network</dt><dd>{{ $w->network }}</dd><dt>To</dt><dd class="mono small break">{{ $w->address }}</dd></dl></div>
                <x-admin.confirm-password />
            </div>
            <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-success" type="submit">Approve</button></div>
        </form>
    </x-admin.modal>
    @endif
    @if ($status->canTransitionTo(WS::Processing))
    <x-admin.modal id="processing-dialog" title="Mark as processing" description="Tells other reviewers that the payment is being sent, so it is not paid twice." icon="clock">
        <form method="post" action="{{ route('admin.withdrawals.processing', $w) }}">@csrf
            <div class="modal-body"><x-admin.form-errors dialog="processing-dialog" /><x-admin.confirm-password /></div>
            <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-primary" type="submit">Mark processing</button></div>
        </form>
    </x-admin.modal>
    @endif
    @if ($status->canTransitionTo(WS::Paid))
    <x-admin.modal id="paid-dialog" title="Record completed payment" description="Only after the transfer is confirmed on-chain. This settles the reservation, notifies the player and posts a masked confirmation to the public channel. It cannot be undone." icon="check-circle" tone="success" wide>
        <form method="post" action="{{ route('admin.withdrawals.paid', $w) }}">@csrf
            <div class="modal-body">
                <x-admin.form-errors dialog="paid-dialog" />
                <div class="summary"><dl class="dl"><dt>Amount sent</dt><dd><x-admin.money :amount="$w->net_amount" :decimals="6" /></dd><dt>Network</dt><dd>{{ $w->network }}</dd><dt>To</dt><dd class="mono small break">{{ $w->address }}</dd></dl></div>
                <label class="field"><span class="field-label">Transaction hash <span class="req" aria-hidden="true">*</span></span><input class="input mono" name="tx_hash" required pattern="[A-Za-z0-9]{16,128}" value="{{ old('tx_hash') }}" autocomplete="off" @error('tx_hash') aria-invalid="true" @enderror>@error('tx_hash')<span class="field-error">{{ $message }}</span>@enderror</label>
                <label class="field"><span class="field-label">Internal payment reference <span class="muted">(optional)</span></span><input class="input" name="payment_reference" maxlength="128" value="{{ old('payment_reference') }}"></label>
                <label class="check"><input type="checkbox" name="verified" value="1" required><span class="check-text">I verified this transaction on the blockchain explorer</span></label>
                <x-admin.confirm-password />
            </div>
            <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-success" type="submit">Record payment</button></div>
        </form>
    </x-admin.modal>
    @endif
    @if ($status->canTransitionTo(WS::Rejected))
    <x-admin.modal id="reject-dialog" title="Reject and return funds" description="The reserved amount returns to the player's available balance and the reason is sent to them on Telegram." icon="x-circle" tone="danger">
        <form method="post" action="{{ route('admin.withdrawals.reject', $w) }}">@csrf
            <div class="modal-body">
                <x-admin.form-errors dialog="reject-dialog" />
                <label class="field"><span class="field-label">Reason (sent to the player) <span class="req" aria-hidden="true">*</span></span><input class="input" name="reason" required minlength="3" maxlength="250" value="{{ old('reason') }}" @error('reason') aria-invalid="true" @enderror></label>
                <x-admin.confirm-password />
            </div>
            <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-danger" type="submit">Reject withdrawal</button></div>
        </form>
    </x-admin.modal>
    @endif
@endadminCan
@endsection
