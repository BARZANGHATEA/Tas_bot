@extends('admin.layout')
@section('title', $user->displayName().' · '.$user->publicId())
@section('content')
<nav class="breadcrumb" aria-label="Breadcrumb"><a href="{{ route('admin.users.index') }}">Users</a><x-admin.icon name="chevron-right" size="xs" /><span aria-current="page">{{ $user->publicId() }}</span></nav>
<div class="page-header">
    <div class="row" style="gap:16px">
        <span class="avatar avatar-lg" aria-hidden="true">{{ $user->initials() }}</span>
        <div>
            <h1>{{ $user->displayName() }} <x-admin.badge :status="$user->status" />@if ($user->is_flagged) <x-admin.badge status="flagged" label="Flagged" />@endif</h1>
            <p class="page-sub"><span class="mono">{{ $user->publicId() }}</span>{{ $user->username ? ' · @'.$user->username : '' }} · Telegram <span class="mono">{{ $user->telegram_id }}</span> · joined {{ biz_date($user->created_at) }}</p>
        </div>
    </div>
    <div class="page-actions">
        @adminCan('wallet.adjust')<button type="button" class="btn" data-dialog-open="adjust-dialog"><x-admin.icon name="wallet" size="sm" /> Adjust balance</button>@endadminCan
        @adminCan('users.manage')
            <button type="button" class="btn" data-dialog-open="status-dialog"><x-admin.icon name="lock" size="sm" /> Change status</button>
            @if ($user->is_flagged)
                <form method="post" action="{{ route('admin.users.flag', $user) }}" data-confirm="Remove the review flag from this user?" data-confirm-title="Remove flag" data-confirm-button="Remove flag">@csrf @method('put')<input type="hidden" name="flagged" value="0"><button class="btn" type="submit"><x-admin.icon name="flag" size="sm" /> Unflag</button></form>
            @else
                <button type="button" class="btn" data-dialog-open="flag-dialog"><x-admin.icon name="flag" size="sm" /> Flag</button>
            @endif
        @endadminCan
    </div>
</div>

@if ($user->status_reason && ! $user->isActive())
    <div class="alert alert-warning"><x-admin.icon name="lock" /><div class="alert-body"><strong>{{ $user->status->label() }}:</strong> {{ $user->status_reason }}</div></div>
@endif

<div class="kpis mb-4">
    <x-admin.stat label="Available" icon="wallet" :value="usdt($user->wallet?->available)" unit="USDT" />
    <x-admin.stat label="Reserved for withdrawals" icon="clock" :value="usdt($user->wallet?->reserved)" unit="USDT" />
    <x-admin.stat label="Total earned" icon="gift" :value="usdt($user->wallet?->total_earned)" unit="USDT" :hint="'Pending rewards '.$summary['pending_rewards'].' USDT'" />
    <x-admin.stat label="Total withdrawn" icon="banknote" :value="usdt($user->wallet?->total_withdrawn)" unit="USDT" />
    <x-admin.stat label="Games played" icon="dice" :value="number_format($gamesPlayed)" :hint="number_format($summary['games_won']).' won'" />
</div>

<div class="grid grid-main">
    <div class="stack">
        <section class="card card-flush" aria-labelledby="ledger-title">
            <div class="card-header"><div><h2 id="ledger-title">Ledger</h2><p class="card-sub">Every balance change, newest first</p></div><a class="btn btn-sm" href="{{ route('admin.ledger.index', ['user' => $user->id]) }}">Open in ledger</a></div>
            @if ($ledger->isEmpty())
                <x-admin.empty icon="book" title="No transactions yet" />
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th scope="col">When</th><th scope="col">Transaction</th><th scope="col" class="num">Available Δ</th><th scope="col" class="num">Reserved Δ</th><th scope="col" class="num">Balance after</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                    @foreach ($ledger as $e)
                        <tr>
                            <td class="nowrap">{{ biz_date($e->created_at) }}</td>
                            <td class="cell-wide"><span class="cell-strong">{{ $e->type->label() }}</span>@if ($e->reversal) <x-admin.badge status="reversed" />@endif<span class="sub">{{ $e->description }}</span></td>
                            <td class="num"><x-admin.money :amount="$e->available_delta" :decimals="6" signed :unit="false" /></td>
                            <td class="num"><x-admin.money :amount="$e->reserved_delta" :decimals="6" signed :unit="false" /></td>
                            <td class="num"><x-admin.money :amount="$e->available_after" :decimals="6" :unit="false" /></td>
                            <td class="actions">
                                @if ($e->type->isReversible() && ! $e->reversal)
                                    @adminCan('rewards.reverse')<button type="button" class="btn btn-xs btn-icon" data-dialog-open="reverse-{{ $e->id }}" aria-label="Reverse transaction" data-tooltip="Reverse"><x-admin.icon name="refresh" size="sm" /></button>@endadminCan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                {{ $ledger->links() }}
            @endif
        </section>

        <div class="grid grid-2">
            <section class="card card-flush">
                <div class="card-header"><h2>Recent solo rounds</h2></div>
                @if ($rounds->isEmpty())<x-admin.empty icon="dice" title="No rounds yet" />@else
                <div class="table-wrap"><table class="table table-compact">
                    <thead><tr><th scope="col">When</th><th scope="col">Dice</th><th scope="col">Result</th></tr></thead>
                    <tbody>@foreach ($rounds as $r)<tr><td class="nowrap">{{ biz_date($r->created_at) }}</td><td class="nowrap"><span class="die">{{ $r->die_one }}</span><span class="die">{{ $r->die_two }}</span></td><td>@if ($r->is_win)<x-admin.badge :status="$r->reward_status === 'credited' ? 'won' : 'unfunded'" :label="$r->reward_status === 'credited' ? 'Won '.usdt($r->reward) : 'Won · unfunded'" />@else<span class="muted">Loss</span>@endif</td></tr>@endforeach</tbody>
                </table></div>@endif
            </section>
            <section class="card card-flush">
                <div class="card-header"><h2>Recent matches</h2></div>
                @if ($matches->isEmpty())<x-admin.empty icon="swords" title="No matches yet" />@else
                <div class="table-wrap"><table class="table table-compact">
                    <thead><tr><th scope="col">Match</th><th scope="col">Opponent</th><th scope="col">Status</th></tr></thead>
                    <tbody>@foreach ($matches as $m)@php $opp = $m->creator_id === $user->id ? $m->opponent : $m->creator; @endphp
                        <tr><td><a href="{{ route('admin.games.match', $m) }}">#{{ $m->id }}</a></td><td><x-admin.user :u="$opp" :meta="false" /></td><td><x-admin.badge :status="$m->status" />@if ($m->winner_id === $user->id) <x-admin.badge status="won" />@endif</td></tr>
                    @endforeach</tbody>
                </table></div>@endif
            </section>
        </div>

        <div class="grid grid-2">
            <section class="card card-flush">
                <div class="card-header"><h2>Invited players</h2><span class="muted small">{{ $summary['referrals'] }} total</span></div>
                @if ($referralsList->isEmpty())<x-admin.empty icon="share" title="Nobody invited yet" />@else
                <div class="table-wrap"><table class="table table-compact">
                    <thead><tr><th scope="col">Player</th><th scope="col">Joined</th><th scope="col">Qualified</th></tr></thead>
                    <tbody>@foreach ($referralsList as $r)<tr><td><x-admin.user :u="$r" :meta="false" /></td><td class="nowrap">{{ biz_date($r->created_at) }}</td><td>{!! $r->referral_qualified_at ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-neutral">No</span>' !!}</td></tr>@endforeach</tbody>
                </table></div>@endif
            </section>
            <section class="card card-flush">
                <div class="card-header"><h2>Referral rewards received</h2></div>
                @if ($referralRewards->isEmpty())<x-admin.empty icon="gift" title="No referral rewards" />@else
                <div class="table-wrap"><table class="table table-compact">
                    <thead><tr><th scope="col">From</th><th scope="col">Event</th><th scope="col" class="num">Amount</th><th scope="col">Status</th></tr></thead>
                    <tbody>@foreach ($referralRewards as $r)<tr><td><x-admin.user :u="$r->sourceUser" :meta="false" /></td><td class="nowrap">L{{ $r->level }} · {{ $r->event }}</td><td class="num"><x-admin.money :amount="$r->amount" :decimals="6" :unit="false" /></td><td><x-admin.badge :status="$r->status" /></td></tr>@endforeach</tbody>
                </table></div>@endif
            </section>
        </div>

        <div class="grid grid-2">
            <section class="card card-flush">
                <div class="card-header"><h2>Missions</h2></div>
                @if ($missions->isEmpty())<x-admin.empty icon="target" title="No mission activity" />@else
                <div class="table-wrap"><table class="table table-compact">
                    <thead><tr><th scope="col">Mission</th><th scope="col">Status</th><th scope="col" class="num">Reward</th></tr></thead>
                    <tbody>@foreach ($missions as $c)<tr><td>{{ $c->mission?->title }}<span class="sub">{{ $c->period_key }}</span></td><td><x-admin.badge :status="$c->status" /></td><td class="num"><x-admin.money :amount="$c->reward" :unit="false" /></td></tr>@endforeach</tbody>
                </table></div>@endif
            </section>
            <section class="card card-flush">
                <div class="card-header"><h2>Withdrawals</h2></div>
                @if ($withdrawals->isEmpty())<x-admin.empty icon="banknote" title="No withdrawals" />@else
                <div class="table-wrap"><table class="table table-compact">
                    <thead><tr><th scope="col">Request</th><th scope="col" class="num">Amount</th><th scope="col">Status</th></tr></thead>
                    <tbody>@foreach ($withdrawals as $w)<tr><td><a class="mono" href="{{ route('admin.withdrawals.show', $w) }}">{{ $w->reference }}</a><span class="sub">{{ biz_date($w->created_at) }}</span></td><td class="num"><x-admin.money :amount="$w->amount" :unit="false" /><span class="sub">{{ $w->network }}</span></td><td><x-admin.badge :status="$w->status" /></td></tr>@endforeach</tbody>
                </table></div>@endif
            </section>
        </div>
    </div>

    <aside class="stack" aria-label="Account details">
        <section class="card">
            <div class="card-header"><h2>Profile</h2></div>
            <div class="card-body">
                <dl class="dl">
                    <dt>Language</dt><dd>{{ $user->language_code ?? '—' }}</dd>
                    <dt>Source</dt><dd>{{ $user->registration_source === 'bot' ? 'Telegram bot' : 'Mini App' }}</dd>
                    <dt>Last seen</dt><dd>{{ $user->last_seen_at ? biz_date($user->last_seen_at) : '—' }}</dd>
                    <dt>Referral code</dt><dd class="mono">{{ $user->referral_code }}</dd>
                    <dt>Invited by</dt><dd><x-admin.user :u="$user->referrer" :meta="false" /></dd>
                    <dt>Qualified</dt><dd>{{ $user->referral_qualified_at ? biz_date($user->referral_qualified_at) : 'Not yet' }}</dd>
                </dl>
                @if ($upline)
                    <hr>
                    <div class="small muted mb-2">Upline</div>
                    <ol class="small" style="margin:0;padding-left:18px">@foreach ($upline as $level => $u)<li>L{{ $level }} · <a href="{{ route('admin.users.show', $u) }}">{{ $u->displayName() }}</a> <span class="muted">{{ $u->publicId() }}</span></li>@endforeach</ol>
                @endif
            </div>
        </section>

        <section class="card">
            <div class="card-header"><h2>Fraud signals</h2><span class="muted small">{{ $flags->where('status', 'open')->count() }} open</span></div>
            <div class="card-body">
                @forelse ($flags as $flag)
                    <div class="{{ $loop->last ? '' : 'mb-4' }}">
                        <div class="row"><x-admin.badge :status="$flag->severity" /> <strong>{{ ucfirst(str_replace('_', ' ', $flag->type)) }}</strong> <x-admin.badge :status="$flag->status" class="badge-plain" /></div>
                        <div class="small muted mt-2">{{ biz_date($flag->created_at) }}</div>
                        @if ($flag->details)<x-admin.details :details="$flag->details" class="mt-2" />@endif
                    </div>
                @empty
                    <p class="muted mb-0">No signals for this player.</p>
                @endforelse
            </div>
        </section>

        @adminCan('users.manage')
        <section class="card">
            <div class="card-header"><h2>Administrative note</h2></div>
            <form class="card-body" method="post" action="{{ route('admin.users.note', $user) }}">
                @csrf @method('put')
                <label class="sr-only" for="admin_note">Administrative note</label>
                <textarea id="admin_note" class="textarea" name="admin_note" maxlength="5000" placeholder="Visible to staff only">{{ old('admin_note', $user->admin_note) }}</textarea>
                <div class="row mt-2"><button class="btn btn-sm" type="submit">Save note</button></div>
            </form>
        </section>
        @endadminCan
    </aside>
</div>

{{-- Dialogs --}}
@adminCan('users.manage')
<x-admin.modal id="status-dialog" title="Change account status" description="Restricted: can sign in and view, but cannot play, claim or withdraw. Suspended: signed out and blocked from the app." icon="lock">
    <form method="post" action="{{ route('admin.users.status', $user) }}">
        @csrf @method('put')
        <div class="modal-body">
            <x-admin.form-errors dialog="status-dialog" />
            <label class="field"><span class="field-label">Status</span>
                <select class="select" name="status">@foreach (\App\Enums\UserStatus::cases() as $s)<option value="{{ $s->value }}" @selected(old('status', $user->status->value) === $s->value)>{{ $s->label() }}</option>@endforeach</select>
            </label>
            <label class="field mb-0"><span class="field-label">Reason <span class="muted">(required unless active)</span></span>
                <input class="input" name="reason" value="{{ old('reason', $user->status_reason) }}" maxlength="255" @error('reason') aria-invalid="true" @enderror>
            </label>
        </div>
        <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-primary" type="submit">Update status</button></div>
    </form>
</x-admin.modal>

<x-admin.modal id="flag-dialog" title="Flag for review" description="Flags surface the account in fraud review. They do not block the player by themselves." icon="flag" tone="warning">
    <form method="post" action="{{ route('admin.users.flag', $user) }}">
        @csrf @method('put')
        <input type="hidden" name="flagged" value="1">
        <div class="modal-body">
            <x-admin.form-errors dialog="flag-dialog" />
            <label class="field mb-0"><span class="field-label">Reason</span><input class="input" name="reason" maxlength="255" placeholder="What did you notice?" value="{{ old('reason') }}"></label>
        </div>
        <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-primary" type="submit">Flag player</button></div>
    </form>
</x-admin.modal>
@endadminCan

@adminCan('wallet.adjust')
<x-admin.modal id="adjust-dialog" title="Adjust balance" description="Recorded in the ledger and the audit log. Credits draw from the reward budget; debits return to it." icon="wallet">
    <form method="post" action="{{ route('admin.users.adjust', $user) }}">
        @csrf
        <div class="modal-body">
            <x-admin.form-errors dialog="adjust-dialog" />
            <div class="summary"><dl class="dl"><dt>Current available</dt><dd><x-admin.money :amount="$user->wallet?->available" :decimals="6" /></dd></dl></div>
            <div class="form-grid">
                <label class="field"><span class="field-label">Direction</span>
                    <select class="select" name="direction"><option value="credit" @selected(old('direction') === 'credit')>Credit (add)</option><option value="debit" @selected(old('direction') === 'debit')>Debit (remove)</option></select>
                </label>
                <label class="field"><span class="field-label">Amount (USDT)</span><input class="input" name="amount" inputmode="decimal" required pattern="\d{1,9}(\.\d{1,6})?" value="{{ old('amount') }}" placeholder="0.00" @error('amount') aria-invalid="true" @enderror></label>
            </div>
            <label class="field"><span class="field-label">Reason</span><input class="input" name="reason" required minlength="5" maxlength="255" value="{{ old('reason') }}" placeholder="Shown in the ledger" @error('reason') aria-invalid="true" @enderror></label>
            <x-admin.confirm-password />
        </div>
        <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-primary" type="submit">Apply adjustment</button></div>
    </form>
</x-admin.modal>
@endadminCan

@adminCan('rewards.reverse')
@foreach ($ledger as $e)
    @if ($e->type->isReversible() && ! $e->reversal)
        <x-admin.modal id="reverse-{{ $e->id }}" title="Reverse transaction" description="Removes the credit from the player's available balance and returns it to the reward budget. This cannot be undone." icon="alert" tone="danger">
            <form method="post" action="{{ route('admin.ledger.reverse', $e) }}">
                @csrf
                <div class="modal-body">
                    <x-admin.form-errors dialog="reverse-{{ $e->id }}" />
                    <div class="summary"><dl class="dl"><dt>Transaction</dt><dd>{{ $e->type->label() }}</dd><dt>Amount</dt><dd><x-admin.money :amount="$e->available_delta" :decimals="6" /></dd><dt>Date</dt><dd>{{ biz_date($e->created_at) }}</dd></dl></div>
                    <label class="field"><span class="field-label">Reason</span><input class="input" name="reason" required minlength="3" maxlength="255"></label>
                    <x-admin.confirm-password />
                </div>
                <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-danger" type="submit">Reverse</button></div>
            </form>
        </x-admin.modal>
    @endif
@endforeach
@endadminCan
@endsection
