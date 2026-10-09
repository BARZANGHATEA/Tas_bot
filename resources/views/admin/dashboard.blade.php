@extends('admin.layout')
@section('title', 'Dashboard')
@section('content')
@if ($stats['budget_low'])
    <div class="alert alert-warn"><strong>Reward budget is low:</strong> {{ $stats['budget_balance'] }} USDT left. Rewards stop automatically when it reaches zero. <a href="{{ route('admin.budget.index') }}">Fund the budget →</a></div>
@endif
@if ($stats['fraud_high'] > 0)
    <div class="alert alert-danger"><strong>{{ $stats['fraud_high'] }} suspicious activity alert(s)</strong> need review. <a href="{{ route('admin.fraud.index') }}">Open fraud review →</a></div>
@endif

<div class="stats">
    <x-admin.stat label="Registered users" :value="number_format($stats['users_total'])" :hint="'+'.$stats['users_today'].' today'" :href="route('admin.users.index')" />
    <x-admin.stat label="Active users" :value="number_format($stats['users_active_24h'])" :hint="number_format($stats['users_active_7d']).' in 7 days'" />
    <x-admin.stat label="Games played" :value="number_format($stats['rounds_total'] + $stats['matches_completed'])" :hint="$stats['rounds_today'].' solo rounds today'" :href="route('admin.games.rounds')" />
    <x-admin.stat label="Win / loss (solo)" :value="number_format($stats['wins_total']).' / '.number_format($stats['losses_total'])" :hint="$stats['win_rate'].'% win rate (expected 16.7%)'" />
    <x-admin.stat label="Rewards issued" :value="$stats['rewards_total'].' USDT'" :hint="$stats['rewards_today'].' USDT today'" :href="route('admin.ledger.index')" />
    <x-admin.stat label="Reward budget" :value="$stats['budget_balance'].' USDT'" :tone="$stats['budget_low'] ? 'warn' : 'ok'" :href="route('admin.budget.index')" />
    <x-admin.stat label="Pending withdrawals" :value="$stats['withdrawals_pending']" :hint="$stats['withdrawals_open_amount'].' USDT in progress'" :tone="$stats['withdrawals_pending'] ? 'warn' : null" :href="route('admin.withdrawals.index', ['status' => 'pending'])" />
    <x-admin.stat label="Withdrawals paid" :value="$stats['withdrawals_paid_amount'].' USDT'" :hint="$stats['withdrawals_paid_count'].' payments'" />
    <x-admin.stat label="Referrals" :value="number_format($stats['referred_users'])" :hint="$stats['qualified_referrals'].' qualified · '.$stats['referral_rewards'].' USDT paid'" :href="route('admin.referrals.index')" />
    <x-admin.stat label="Missions" :value="number_format($stats['missions_completed'])" :hint="$stats['missions_pending'].' waiting for review'" :tone="$stats['missions_pending'] ? 'warn' : null" :href="route('admin.missions.reviews')" />
    <x-admin.stat label="Matches" :value="number_format($stats['matches_completed'])" :hint="$stats['matches_open'].' open now'" :href="route('admin.games.matches')" />
    <x-admin.stat label="Fraud alerts" :value="$stats['fraud_open']" :tone="$stats['fraud_open'] ? 'danger' : 'ok'" :href="route('admin.fraud.index')" />
</div>

<div class="grid grid-2">
    @foreach (['registrations' => 'New registrations (14 days)', 'rounds_series' => 'Solo rounds played (14 days)'] as $key => $title)
        @php $max = max(1, max(array_column($stats[$key], 'count'))); @endphp
        <section class="panel">
            <div class="panel-head"><h3>{{ $title }}</h3><span class="muted small">max {{ $max }}/day</span></div>
            <div class="bars">
                @foreach ($stats[$key] as $point)
                    <div class="bar" style="height: {{ max(1, round($point['count'] / $max * 100)) }}%" data-label="{{ $point['date'] }}: {{ $point['count'] }}"></div>
                @endforeach
            </div>
            <div class="bar-labels">@foreach ($stats[$key] as $i => $point)<span>{{ $i % 2 === 0 ? $point['date'] : '' }}</span>@endforeach</div>
        </section>
    @endforeach
</div>

<div class="grid grid-2">
    <section class="panel">
        <div class="panel-head"><h3>Oldest pending withdrawals</h3><a href="{{ route('admin.withdrawals.index', ['status' => 'pending']) }}">View all</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Reference</th><th>User</th><th class="num">Amount</th><th>Requested</th></tr></thead>
            <tbody>
            @forelse ($pending as $w)
                <tr><td><a href="{{ route('admin.withdrawals.show', $w) }}">{{ $w->reference }}</a></td><td>@include('admin.partials.user-link', ['u' => $w->user])</td><td class="num">{{ usdt($w->amount) }} {{ $w->network }}</td><td class="small">{{ biz_date($w->created_at) }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">Nothing waiting. 🎉</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
    <section class="panel">
        <div class="panel-head"><h3>Suspicious activity</h3><a href="{{ route('admin.fraud.index') }}">View all</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>User</th><th>Signal</th><th>Severity</th><th>When</th></tr></thead>
            <tbody>
            @forelse ($alerts as $flag)
                <tr><td>@include('admin.partials.user-link', ['u' => $flag->user])</td><td>{{ str_replace('_', ' ', $flag->type) }}</td><td><x-admin.badge :status="$flag->severity" /></td><td class="small">{{ biz_date($flag->created_at) }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">No open alerts.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</div>
@endsection
