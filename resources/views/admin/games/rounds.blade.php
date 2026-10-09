@extends('admin.layout')
@section('title', 'Game rounds')
@section('content')
<div class="stats">
    <x-admin.stat label="Rounds" :value="number_format($totals['all'])" />
    <x-admin.stat label="Wins" :value="number_format($totals['wins'])" :hint="$totals['all'] ? round($totals['wins'] / $totals['all'] * 100, 2).'% (expected 16.67%)' : null" />
    <x-admin.stat label="Unfunded wins" :value="number_format($totals['unfunded'])" hint="Won after a daily cap / empty budget" />
</div>
<section class="panel">
    <p class="small muted">Settled rounds are immutable and cannot be edited by anyone.</p>
    <form class="filters" method="get">
        <label class="field"><span>Result</span><select name="result"><option value="">Any</option><option value="win" @selected(request('result') === 'win')>Wins</option><option value="loss" @selected(request('result') === 'loss')>Losses</option></select></label>
        <label class="field"><span>User ID</span><input class="input" name="user" value="{{ request('user') }}" placeholder="U000123"></label>
        <button class="btn">Filter</button>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>Round</th><th>User</th><th>Dice</th><th>Result</th><th class="num">Reward</th><th>When</th></tr></thead>
        <tbody>
        @forelse ($rounds as $r)
            <tr><td class="mono small">{{ \Illuminate\Support\Str::limit($r->uuid, 13, '') }}</td><td>@include('admin.partials.user-link', ['u' => $r->user])</td>
                <td><span class="die">{{ $r->die_one }}</span><span class="die">{{ $r->die_two }}</span></td>
                <td>@if ($r->is_win)<x-admin.badge :status="$r->reward_status === 'unfunded' ? 'unfunded' : 'won'" />@else<span class="muted">Loss</span>@endif</td>
                <td class="num">{{ usdt($r->reward) }}</td><td class="small">{{ biz_date($r->created_at) }}</td></tr>
        @empty
            <tr><td colspan="6" class="muted">No rounds.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $rounds->links() }}
</section>
@endsection
