@extends('admin.layout')
@section('title', 'Match #'.$match->id)
@section('content')
<nav class="breadcrumb" aria-label="Breadcrumb"><a href="{{ route('admin.games.matches') }}">Matches</a><x-admin.icon name="chevron-right" size="xs" /><span aria-current="page">#{{ $match->id }}</span></nav>
<div class="page-header">
    <div><h1>Match #{{ $match->id }} <x-admin.badge :status="$match->status" /></h1><p class="page-sub">{{ ucfirst($match->visibility) }} · {{ $match->dice_count }} dice · {{ $match->base_rounds }} rounds{{ $match->total_rounds > $match->base_rounds ? ' + '.($match->total_rounds - $match->base_rounds).' extra' : '' }}</p></div>
</div>
<div class="grid grid-main">
    <section class="card card-flush">
        <div class="card-header"><div><h2>Rolls</h2><p class="card-sub">Immutable, rolled on the server</p></div></div>
        @if ($match->rolls->isEmpty())
            <x-admin.empty icon="dice" title="No rolls yet" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th scope="col">Round</th><th scope="col">Player</th><th scope="col">Dice</th><th scope="col" class="num">Total</th><th scope="col">When</th></tr></thead>
                <tbody>@foreach ($match->rolls->sortBy(['round_no', 'id']) as $roll)
                    <tr><td>{{ $roll->round_no }}</td><td><x-admin.user :u="$roll->user" :meta="false" /></td><td class="nowrap">@foreach ($roll->dice as $d)<span class="die">{{ $d }}</span>@endforeach</td><td class="num">{{ $roll->total }}</td><td class="nowrap">{{ biz_date($roll->created_at) }}</td></tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </section>
    <aside class="card">
        <div class="card-header"><h2>Summary</h2></div>
        <div class="card-body">
            <dl class="dl">
                <dt>Creator</dt><dd><x-admin.user :u="$match->creator" /></dd>
                <dt>Opponent</dt><dd><x-admin.user :u="$match->opponent" /></dd>
                <dt>Score</dt><dd class="tabular">{{ $match->creator_score }} – {{ $match->opponent_score }}</dd>
                <dt>Result</dt><dd>{{ $match->is_tie ? 'Draw' : ($match->winner?->displayName() ?? '—') }}</dd>
                <dt>Reward</dt><dd>@if ($match->reward_status !== 'none')<x-admin.badge :status="$match->reward_status" />@else<span class="muted">None</span>@endif</dd>
                <dt>Created</dt><dd>{{ biz_date($match->created_at) }}</dd>
                <dt>Joined</dt><dd>{{ biz_date($match->joined_at) }}</dd>
                <dt>Completed</dt><dd>{{ biz_date($match->completed_at) }}</dd>
                @if ($match->cancelled_at)<dt>Cancelled</dt><dd>{{ biz_date($match->cancelled_at) }} · {{ $match->cancel_reason }}</dd>@endif
            </dl>
            <hr>
            <div class="small muted mb-2">Rules at creation</div>
            <code class="small break" style="display:block">{{ json_encode($match->rules) }}</code>
            <div class="small muted mt-4">UUID <span class="mono">{{ $match->uuid }}</span></div>
        </div>
    </aside>
</div>
@endsection
