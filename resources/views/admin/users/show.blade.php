@extends('admin.layout')
@section('title', 'User '.$user->publicId())
@section('content')
@if ($user->is_flagged)<div class="alert alert-danger"><strong>Flagged for review.</strong> See the fraud signals below before approving payouts.</div>@endif

<div class="grid grid-sidebar">
    <div>
        <section class="panel">
            <div class="panel-head">
                <h2>{{ $user->displayName() }} <span class="muted">{{ $user->publicId() }}</span></h2>
                <x-admin.badge :status="$user->status" />
            </div>
            <div class="grid grid-2">
                <dl class="kv">
                    <dt>Telegram ID</dt><dd class="mono">{{ $user->telegram_id }}</dd>
                    <dt>Username</dt><dd>{{ $user->username ? '@'.$user->username : '—' }}</dd>
                    <dt>Language</dt><dd>{{ $user->language_code ?? '—' }}</dd>
                    <dt>Registered</dt><dd>{{ biz_date($user->created_at) }} via {{ $user->registration_source }}</dd>
                    <dt>Last seen</dt><dd>{{ biz_date($user->last_seen_at) }}</dd>
                    <dt>Referral code</dt><dd class="mono">{{ $user->referral_code }}</dd>
                    <dt>Invited by</dt><dd>@include('admin.partials.user-link', ['u' => $user->referrer])</dd>
                    <dt>Qualified</dt><dd>{{ $user->referral_qualified_at ? biz_date($user->referral_qualified_at) : 'Not yet' }}</dd>
                    @if ($user->status_reason)<dt>Status reason</dt><dd>{{ $user->status_reason }}</dd>@endif
                </dl>
                <dl class="kv">
                    <dt>Available</dt><dd>{{ usdt($user->wallet?->available, 6) }} USDT</dd>
                    <dt>Reserved</dt><dd>{{ usdt($user->wallet?->reserved, 6) }} USDT</dd>
                    <dt>Pending rewards</dt><dd>{{ $summary['pending_rewards'] }} USDT</dd>
                    <dt>Total earned</dt><dd>{{ usdt($user->wallet?->total_earned, 6) }} USDT</dd>
                    <dt>Total withdrawn</dt><dd>{{ usdt($user->wallet?->total_withdrawn, 6) }} USDT</dd>
                    <dt>Games played</dt><dd>{{ $gamesPlayed }} ({{ $summary['games_won'] }} won)</dd>
                    <dt>Direct referrals</dt><dd>{{ $summary['referrals'] }}</dd>
                </dl>
            </div>
            @if ($upline)
                <p class="small muted mt">Upline:
                    @foreach ($upline as $level => $u) L{{ $level }} <a href="{{ route('admin.users.show', $u) }}">{{ $u->publicId() }}</a>@if (! $loop->last) → @endif @endforeach
                </p>
            @endif
        </section>

        <section class="panel">
            <div class="panel-head"><h3>Ledger</h3><a href="{{ route('admin.ledger.index', ['user' => $user->id]) }}">Open in ledger</a></div>
            <div class="table-wrap"><table>
                <thead><tr><th>When</th><th>Type</th><th>Description</th><th class="num">Available Δ</th><th class="num">Reserved Δ</th><th class="num">Balance after</th><th></th></tr></thead>
                <tbody>
                @forelse ($ledger as $e)
                    <tr>
                        <td class="small nowrap">{{ biz_date($e->created_at) }}</td>
                        <td>{{ $e->type->label() }}</td>
                        <td class="small">{{ $e->description }} @if ($e->reversal)<x-admin.badge status="reversed" />@endif</td>
                        <td class="num {{ str_starts_with($e->available_delta, '-') ? 'neg' : ((float) $e->available_delta ? 'pos' : '') }}">{{ usdt($e->available_delta, 6) }}</td>
                        <td class="num">{{ usdt($e->reserved_delta, 6) }}</td>
                        <td class="num">{{ usdt($e->available_after, 6) }}</td>
                        <td>
                            @if ($e->type->isReversible() && ! $e->reversal)
                                @adminCan('rewards.reverse')
                                <details class="action-box"><summary class="small">Reverse</summary>
                                    <form method="post" action="{{ route('admin.ledger.reverse', $e) }}" data-confirm="Reverse this credit of {{ usdt($e->available_delta, 6) }} USDT?">
                                        @csrf
                                        <label class="field"><span>Reason</span><input class="input" name="reason" required minlength="3"></label>
                                        <x-admin.confirm-password />
                                        <button class="btn btn-danger btn-sm">Reverse</button>
                                    </form>
                                </details>
                                @endadminCan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No transactions.</td></tr>
                @endforelse
                </tbody>
            </table></div>
            {{ $ledger->links() }}
        </section>

        <div class="grid grid-2">
            <section class="panel">
                <h3>Recent solo rounds</h3>
                <div class="table-wrap"><table>
                    <thead><tr><th>When</th><th>Dice</th><th>Result</th></tr></thead>
                    <tbody>@forelse ($rounds as $r)<tr><td class="small">{{ biz_date($r->created_at) }}</td><td><span class="die">{{ $r->die_one }}</span><span class="die">{{ $r->die_two }}</span></td><td>@if ($r->is_win)<x-admin.badge :status="$r->reward_status === 'credited' ? 'won' : 'unfunded'" /> {{ usdt($r->reward) }}@else<span class="muted">Loss</span>@endif</td></tr>@empty<tr><td colspan="3" class="muted">None.</td></tr>@endforelse</tbody>
                </table></div>
            </section>
            <section class="panel">
                <h3>Recent matches</h3>
                <div class="table-wrap"><table>
                    <thead><tr><th>Match</th><th>Opponent</th><th>Status</th></tr></thead>
                    <tbody>@forelse ($matches as $m)
                        @php $opp = $m->creator_id === $user->id ? $m->opponent : $m->creator; @endphp
                        <tr><td><a href="{{ route('admin.games.match', $m) }}">#{{ $m->id }}</a></td><td>@include('admin.partials.user-link', ['u' => $opp])</td><td><x-admin.badge :status="$m->status" />@if ($m->winner_id === $user->id) <x-admin.badge status="won" />@endif</td></tr>
                    @empty<tr><td colspan="3" class="muted">None.</td></tr>@endforelse</tbody>
                </table></div>
            </section>
        </div>

        <div class="grid grid-2">
            <section class="panel">
                <h3>Referred users</h3>
                <div class="table-wrap"><table>
                    <thead><tr><th>User</th><th>Joined</th><th>Qualified</th></tr></thead>
                    <tbody>@forelse ($referralsList as $r)<tr><td>@include('admin.partials.user-link', ['u' => $r])</td><td class="small">{{ biz_date($r->created_at) }}</td><td>{{ $r->referral_qualified_at ? 'Yes' : 'No' }}</td></tr>@empty<tr><td colspan="3" class="muted">None.</td></tr>@endforelse</tbody>
                </table></div>
            </section>
            <section class="panel">
                <h3>Referral rewards received</h3>
                <div class="table-wrap"><table>
                    <thead><tr><th>From</th><th>Level / event</th><th class="num">Amount</th><th>Status</th></tr></thead>
                    <tbody>@forelse ($referralRewards as $r)<tr><td>@include('admin.partials.user-link', ['u' => $r->sourceUser])</td><td>L{{ $r->level }} {{ $r->event }}</td><td class="num">{{ usdt($r->amount, 6) }}</td><td><x-admin.badge :status="$r->status" /></td></tr>@empty<tr><td colspan="4" class="muted">None.</td></tr>@endforelse</tbody>
                </table></div>
            </section>
        </div>

        <div class="grid grid-2">
            <section class="panel">
                <h3>Missions</h3>
                <div class="table-wrap"><table>
                    <thead><tr><th>Mission</th><th>Period</th><th>Status</th><th class="num">Reward</th></tr></thead>
                    <tbody>@forelse ($missions as $c)<tr><td>{{ $c->mission?->title }}</td><td class="small">{{ $c->period_key }}</td><td><x-admin.badge :status="$c->status" /></td><td class="num">{{ usdt($c->reward) }}</td></tr>@empty<tr><td colspan="4" class="muted">None.</td></tr>@endforelse</tbody>
                </table></div>
            </section>
            <section class="panel">
                <h3>Withdrawals</h3>
                <div class="table-wrap"><table>
                    <thead><tr><th>Reference</th><th class="num">Amount</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>@forelse ($withdrawals as $w)<tr><td><a href="{{ route('admin.withdrawals.show', $w) }}">{{ $w->reference }}</a></td><td class="num">{{ usdt($w->amount) }} {{ $w->network }}</td><td><x-admin.badge :status="$w->status" /></td><td class="small">{{ biz_date($w->created_at) }}</td></tr>@empty<tr><td colspan="4" class="muted">None.</td></tr>@endforelse</tbody>
                </table></div>
            </section>
        </div>
    </div>

    <aside>
        @adminCan('users.manage')
        <section class="panel">
            <h3>Account status</h3>
            <form method="post" action="{{ route('admin.users.status', $user) }}" data-confirm="Change this account's status?">
                @csrf @method('put')
                <label class="field"><span>Status</span>
                    <select name="status">@foreach (\App\Enums\UserStatus::cases() as $s)<option value="{{ $s->value }}" @selected($user->status === $s)>{{ $s->label() }}</option>@endforeach</select>
                    <span class="help">Restricted: can view but not play, claim or withdraw. Suspended: cannot use the app.</span>
                </label>
                <label class="field"><span>Reason</span><input class="input" name="reason" value="{{ $user->status_reason }}" maxlength="255"></label>
                <button class="btn">Update status</button>
            </form>
        </section>

        <section class="panel">
            <h3>Review flag</h3>
            <form method="post" action="{{ route('admin.users.flag', $user) }}">
                @csrf @method('put')
                <input type="hidden" name="flagged" value="{{ $user->is_flagged ? 0 : 1 }}">
                @unless ($user->is_flagged)<label class="field"><span>Reason</span><input class="input" name="reason" maxlength="255"></label>@endunless
                <button class="btn {{ $user->is_flagged ? 'btn-ghost' : 'btn-warn' }}">{{ $user->is_flagged ? 'Remove flag' : 'Flag as suspicious' }}</button>
            </form>
        </section>

        <section class="panel">
            <h3>Administrative note</h3>
            <form method="post" action="{{ route('admin.users.note', $user) }}">
                @csrf @method('put')
                <textarea name="admin_note" maxlength="5000">{{ $user->admin_note }}</textarea>
                <button class="btn btn-ghost mt">Save note</button>
            </form>
        </section>
        @endadminCan

        @adminCan('wallet.adjust')
        <section class="panel">
            <h3>Manual balance adjustment</h3>
            <p class="small muted">Recorded in the ledger and the audit log. Credits draw from the reward budget; debits return to it.</p>
            <form method="post" action="{{ route('admin.users.adjust', $user) }}" data-confirm="Apply this balance adjustment?">
                @csrf
                <label class="field"><span>Direction</span><select name="direction"><option value="credit">Credit (add)</option><option value="debit">Debit (remove)</option></select></label>
                <label class="field"><span>Amount (USDT)</span><input class="input" name="amount" inputmode="decimal" required pattern="\d{1,9}(\.\d{1,6})?"></label>
                <label class="field"><span>Reason</span><input class="input" name="reason" required minlength="5" maxlength="255"></label>
                <x-admin.confirm-password />
                <button class="btn btn-warn">Apply adjustment</button>
            </form>
        </section>
        @endadminCan

        <section class="panel">
            <h3>Fraud signals</h3>
            @forelse ($flags as $flag)
                <div class="mb"><x-admin.badge :status="$flag->severity" /> <strong>{{ str_replace('_', ' ', $flag->type) }}</strong> <x-admin.badge :status="$flag->status" />
                    <div class="small muted">{{ biz_date($flag->created_at) }}</div>
                    @if ($flag->details)<code class="small break">{{ json_encode($flag->details) }}</code>@endif
                </div>
            @empty
                <p class="muted">No signals.</p>
            @endforelse
        </section>
    </aside>
</div>
@endsection
