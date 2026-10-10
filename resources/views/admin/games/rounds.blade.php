@extends('admin.layout')
@section('title', 'Game rounds')
@section('content')
<div class="page-header">
    <div><h1>Solo game rounds</h1><p class="page-sub">Settled rounds are immutable – results can be inspected, never edited.</p></div>
</div>
<div class="kpis mb-4">
    <x-admin.stat label="Rounds played" icon="dice" :value="number_format($totals['all'])" />
    <x-admin.stat label="Wins (doubles)" icon="gift" :value="number_format($totals['wins'])" :hint="$totals['all'] ? round($totals['wins'] / $totals['all'] * 100, 2).'% · expected 16.67%' : 'Expected rate 16.67%'" />
    <x-admin.stat label="Unfunded wins" icon="wallet" :value="number_format($totals['unfunded'])" hint="Won after a daily cap or empty budget" />
</div>
<section class="card card-flush">
    <form class="toolbar" method="get">
        <label class="field"><span class="field-label">Result</span><select class="select" name="result"><option value="">All results</option><option value="win" @selected(request('result') === 'win')>Wins</option><option value="loss" @selected(request('result') === 'loss')>Losses</option></select></label>
        <label class="field"><span class="field-label">Player ID</span><input class="input" name="user" value="{{ request('user') }}" placeholder="U000123"></label>
        <button class="btn" type="submit"><x-admin.icon name="filter" size="sm" /> Apply</button>
        @if (request('result') || request('user'))<a class="btn btn-ghost" href="{{ route('admin.games.rounds') }}">Clear</a>@endif
    </form>
    @if ($rounds->isEmpty())
        <x-admin.empty icon="dice" title="No rounds found" />
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col">Round</th><th scope="col">Player</th><th scope="col">Dice</th><th scope="col">Result</th><x-admin.th-sort column="reward" :sort="$sort" class="num">Reward (USDT)</x-admin.th-sort><x-admin.th-sort column="date" :sort="$sort">Played</x-admin.th-sort></tr></thead>
            <tbody>
            @foreach ($rounds as $r)
                <tr>
                    <td class="mono small" title="{{ $r->uuid }}">{{ \Illuminate\Support\Str::limit($r->uuid, 8, '') }}</td>
                    <td><x-admin.user :u="$r->user" /></td>
                    <td class="nowrap"><span class="die">{{ $r->die_one }}</span><span class="die">{{ $r->die_two }}</span></td>
                    <td>@if ($r->is_win)<x-admin.badge :status="$r->reward_status === 'unfunded' ? 'unfunded' : 'won'" :label="$r->reward_status === 'unfunded' ? 'Won · unfunded' : 'Won'" />@else<span class="muted">Loss</span>@endif</td>
                    <td class="num"><x-admin.money :amount="$r->reward" :unit="false" /></td>
                    <td class="nowrap">{{ biz_date($r->created_at) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $rounds->links() }}
    @endif
</section>
@endsection
