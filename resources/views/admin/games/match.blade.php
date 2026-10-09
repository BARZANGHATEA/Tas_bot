@extends('admin.layout')
@section('title', 'Match #'.$match->id)
@section('content')
<div class="grid grid-2">
    <section class="panel">
        <div class="panel-head"><h2>Match #{{ $match->id }}</h2><x-admin.badge :status="$match->status" /></div>
        <dl class="kv">
            <dt>UUID</dt><dd class="mono small">{{ $match->uuid }}</dd>
            <dt>Visibility</dt><dd>{{ $match->visibility }}</dd>
            <dt>Creator</dt><dd>@include('admin.partials.user-link', ['u' => $match->creator])</dd>
            <dt>Opponent</dt><dd>@include('admin.partials.user-link', ['u' => $match->opponent])</dd>
            <dt>Score</dt><dd>{{ $match->creator_score }} – {{ $match->opponent_score }}</dd>
            <dt>Result</dt><dd>{{ $match->is_tie ? 'Draw' : ($match->winner?->publicId() ?? '—') }}</dd>
            <dt>Rounds</dt><dd>{{ $match->total_rounds }} ({{ $match->base_rounds }} + {{ $match->total_rounds - $match->base_rounds }} extra) · {{ $match->dice_count }} dice</dd>
            <dt>Rules snapshot</dt><dd class="mono small break">{{ json_encode($match->rules) }}</dd>
            <dt>Reward status</dt><dd><x-admin.badge :status="$match->reward_status" /></dd>
            <dt>Created / joined</dt><dd>{{ biz_date($match->created_at) }} / {{ biz_date($match->joined_at) }}</dd>
            <dt>Completed / settled</dt><dd>{{ biz_date($match->completed_at) }} / {{ biz_date($match->settled_at) }}</dd>
            @if ($match->cancelled_at)<dt>Cancelled</dt><dd>{{ biz_date($match->cancelled_at) }} ({{ $match->cancel_reason }})</dd>@endif
        </dl>
    </section>
    <section class="panel">
        <h3>Rolls (immutable)</h3>
        <div class="table-wrap"><table>
            <thead><tr><th>Round</th><th>Player</th><th>Dice</th><th class="num">Total</th><th>When</th></tr></thead>
            <tbody>@forelse ($match->rolls->sortBy(['round_no', 'id']) as $roll)
                <tr><td>{{ $roll->round_no }}</td><td>{{ $roll->user->publicId() }}</td><td>@foreach ($roll->dice as $d)<span class="die">{{ $d }}</span>@endforeach</td><td class="num">{{ $roll->total }}</td><td class="small">{{ biz_date($roll->created_at) }}</td></tr>
            @empty<tr><td colspan="5" class="muted">No rolls yet.</td></tr>@endforelse</tbody>
        </table></div>
    </section>
</div>
@endsection
