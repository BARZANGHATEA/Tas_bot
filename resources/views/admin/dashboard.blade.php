@extends('admin.layout')
@section('title', 'Dashboard')
@section('content')
@php
    $s = $stats;
    $attention = array_filter([
        $s['withdrawals_pending'] ? ['href' => route('admin.withdrawals.index', ['status' => 'pending']), 'icon' => 'banknote', 'tone' => 'warning', 'count' => $s['withdrawals_pending'], 'title' => 'Withdrawals to review', 'text' => $s['withdrawals_pending_amount'].' USDT waiting for a decision'] : null,
        $s['missions_pending'] ? ['href' => route('admin.missions.reviews'), 'icon' => 'inbox', 'tone' => 'info', 'count' => $s['missions_pending'], 'title' => 'Mission submissions', 'text' => 'Proofs waiting for a moderator'] : null,
        $s['fraud_high'] ? ['href' => route('admin.fraud.index'), 'icon' => 'shield', 'tone' => 'danger', 'count' => $s['fraud_high'], 'title' => 'Fraud signals', 'text' => 'Medium or high severity, unresolved'] : null,
        $s['budget_low'] ? ['href' => route('admin.budget.index'), 'icon' => 'wallet', 'tone' => 'danger', 'count' => $s['budget_balance'], 'title' => 'Reward budget is low', 'text' => 'USDT left – rewards stop at zero'] : null,
    ]);
    $capRaw = \App\Support\Money::of($s['platform_cap']);
    $usedRaw = \App\Support\Money::of($s['rewards_today_raw']);
    $capPct = $capRaw->isPositive() ? min(100, (int) round((float) (string) $usedRaw->dividedBy($capRaw, 4, \Brick\Math\RoundingMode::DOWN) * 100)) : null;
@endphp

<div class="page-header">
    <div>
        <h1>Overview</h1>
        <p class="page-sub">{{ \Carbon\CarbonImmutable::now(app(\App\Services\Settings::class)->timezone())->format('l, j F Y · H:i') }} ({{ app(\App\Services\Settings::class)->timezone() }})</p>
    </div>
    <div class="page-actions">
        @adminCan('withdrawals.view')<a class="btn" href="{{ route('admin.withdrawals.index') }}"><x-admin.icon name="banknote" size="sm" /> Withdrawals</a>@endadminCan
        @adminCan('budget.manage')<a class="btn btn-primary" href="{{ route('admin.budget.index') }}"><x-admin.icon name="plus" size="sm" /> Fund budget</a>@endadminCan
    </div>
</div>

<section aria-labelledby="attention-title" class="mb-4">
    <h2 id="attention-title" class="sr-only">Needs attention</h2>
    @if ($attention)
        <div class="attention">
            @foreach ($attention as $item)
                <a class="attention-item is-{{ $item['tone'] }}" href="{{ $item['href'] }}">
                    <span class="attention-icon"><x-admin.icon :name="$item['icon']" /></span>
                    <span class="grow">
                        <span class="row-between"><span class="attention-title">{{ $item['title'] }}</span><span class="attention-count">{{ $item['count'] }}</span></span>
                        <span class="attention-text">{{ $item['text'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    @else
        <div class="alert alert-success mb-0"><x-admin.icon name="check-circle" /><div class="alert-body"><strong>All clear.</strong> No withdrawals, submissions or fraud signals are waiting, and the reward budget is healthy.</div></div>
    @endif
</section>

<section aria-label="Today" class="kpis mb-4">
    <x-admin.stat label="New users today" icon="users" :value="number_format($s['users_today'])" :delta="$s['users_today'] - $s['users_yesterday']" :href="route('admin.users.index')" />
    <x-admin.stat label="Solo rounds today" icon="dice" :value="number_format($s['rounds_today'])" :delta="$s['rounds_today'] - $s['rounds_yesterday']" :href="route('admin.games.rounds')" />
    <x-admin.stat label="Rewards issued today" icon="gift" :value="$s['rewards_today']" unit="USDT" :hint="'Yesterday '.$s['rewards_yesterday'].' USDT'">
        @if ($capPct !== null)
            <div class="meter {{ $capPct >= 90 ? 'is-danger' : ($capPct >= 70 ? 'is-warning' : '') }}" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $capPct }}" aria-label="Daily reward cap used"><span style="width: {{ $capPct }}%"></span></div>
            <div class="stat-hint">{{ $capPct }}% of the {{ usdt($capRaw) }} USDT daily cap</div>
        @endif
    </x-admin.stat>
    <x-admin.stat label="Active users (24 h)" icon="activity" :value="number_format($s['users_active_24h'])" :hint="number_format($s['users_active_7d']).' in the last 7 days'" />
    <x-admin.stat label="Reward budget" icon="wallet" :value="$s['budget_balance']" unit="USDT" :hint="$s['budget_low'] ? 'Below the alert threshold' : 'Available for rewards'" :href="route('admin.budget.index')" />
</section>

<div class="grid grid-2 mb-4">
    <section class="card" aria-labelledby="chart-reg-title">
        <div class="card-header"><div><h2 id="chart-reg-title">New registrations</h2><p class="card-sub">Last 14 days · today highlighted</p></div></div>
        <div class="card-body"><x-admin.chart id="chart-reg" :series="$s['registrations']" title="New registrations, last 14 days" unit=" users" /></div>
    </section>
    <section class="card" aria-labelledby="chart-rounds-title">
        <div class="card-header"><div><h2 id="chart-rounds-title">Solo rounds played</h2><p class="card-sub">Last 14 days · today highlighted</p></div></div>
        <div class="card-body"><x-admin.chart id="chart-rounds" :series="$s['rounds_series']" title="Solo rounds played, last 14 days" unit=" rounds" /></div>
    </section>
</div>

<div class="grid grid-2 mb-4">
    <section class="card card-flush" aria-labelledby="queue-title">
        <div class="card-header">
            <div><h2 id="queue-title">Oldest pending withdrawals</h2><p class="card-sub">First in, first reviewed</p></div>
            <a class="btn btn-sm" href="{{ route('admin.withdrawals.index', ['status' => 'pending']) }}">View queue</a>
        </div>
        @if ($pending->isEmpty())
            <x-admin.empty icon="check-circle" title="Queue is empty" text="New withdrawal requests appear here as soon as players submit them." />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th scope="col">Request</th><th scope="col">Player</th><th scope="col" class="num">Amount</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                @foreach ($pending as $w)
                    <tr data-href="{{ route('admin.withdrawals.show', $w) }}" class="is-clickable">
                        <td><a href="{{ route('admin.withdrawals.show', $w) }}" class="cell-strong mono">{{ $w->reference }}</a><span class="sub">{{ $w->created_at->diffForHumans() }}</span></td>
                        <td><x-admin.user :u="$w->user" /></td>
                        <td class="num"><x-admin.money :amount="$w->amount" /><span class="sub">{{ $w->network }}</span></td>
                        <td class="actions"><a class="btn btn-xs" href="{{ route('admin.withdrawals.show', $w) }}">Review</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </section>

    <section class="card card-flush" aria-labelledby="alerts-title">
        <div class="card-header">
            <div><h2 id="alerts-title">Recent fraud signals</h2><p class="card-sub">Hints for a human decision, not proof</p></div>
            <a class="btn btn-sm" href="{{ route('admin.fraud.index') }}">Open review</a>
        </div>
        @if ($alerts->isEmpty())
            <x-admin.empty icon="shield" title="No open signals" text="Sign-up bursts, shared payout wallets and similar patterns are flagged here automatically." />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th scope="col">Player</th><th scope="col">Signal</th><th scope="col">Severity</th></tr></thead>
                <tbody>
                @foreach ($alerts as $flag)
                    <tr>
                        <td><x-admin.user :u="$flag->user" /></td>
                        <td>{{ ucfirst(str_replace('_', ' ', $flag->type)) }}<span class="sub">{{ $flag->created_at->diffForHumans() }}</span></td>
                        <td><x-admin.badge :status="$flag->severity" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
</div>

<section class="card" aria-labelledby="totals-title">
    <div class="card-header"><div><h2 id="totals-title">All-time totals</h2></div></div>
    <div class="card-body">
        <dl class="dl-stacked">
            <div><dt>Registered users</dt><dd>{{ number_format($s['users_total']) }}</dd></div>
            <div><dt>Games played</dt><dd>{{ number_format($s['rounds_total'] + $s['matches_completed']) }}</dd></div>
            <div><dt>Solo win rate</dt><dd>{{ $s['win_rate'] }}% <span class="muted small">(expected 16.7%)</span></dd></div>
            <div><dt>Rewards issued</dt><dd>{{ $s['rewards_total'] }} <span class="unit">USDT</span></dd></div>
            <div><dt>Withdrawals paid</dt><dd>{{ $s['withdrawals_paid_amount'] }} <span class="unit">USDT</span> <span class="muted small">· {{ number_format($s['withdrawals_paid_count']) }}</span></dd></div>
            <div><dt>Referred users</dt><dd>{{ number_format($s['referred_users']) }} <span class="muted small">· {{ number_format($s['qualified_referrals']) }} qualified</span></dd></div>
            <div><dt>Referral rewards</dt><dd>{{ $s['referral_rewards'] }} <span class="unit">USDT</span></dd></div>
            <div><dt>Missions completed</dt><dd>{{ number_format($s['missions_completed']) }}</dd></div>
            <div><dt>Matches</dt><dd>{{ number_format($s['matches_completed']) }} <span class="muted small">· {{ number_format($s['matches_open']) }} open</span></dd></div>
        </dl>
    </div>
</section>
@endsection
