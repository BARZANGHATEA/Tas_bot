@extends('admin.layout')
@section('title', 'Matches')
@section('content')
<div class="page-header"><div><h1>Two-player matches</h1><p class="page-sub">Free-to-play duels. Rewards, when enabled, are sponsored by the platform.</p></div></div>
<nav class="tabs" aria-label="Filter by status">
    <a class="tab" href="{{ route('admin.games.matches') }}" @if (! request('status')) aria-current="page" @endif>All <span class="tab-count">{{ number_format($counts->sum()) }}</span></a>
    @foreach (['waiting' => 'Waiting', 'ready' => 'Ready', 'playing' => 'Playing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $value => $label)
        <a class="tab" href="{{ route('admin.games.matches', ['status' => $value]) }}" @if (request('status') === $value) aria-current="page" @endif>{{ $label }} <span class="tab-count">{{ number_format($counts[$value] ?? 0) }}</span></a>
    @endforeach
</nav>
<section class="card card-flush">
    @if ($matches->isEmpty())
        <x-admin.empty icon="swords" title="No matches" />
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col">Match</th><th scope="col">Creator</th><th scope="col">Opponent</th><th scope="col" class="num">Score</th><th scope="col">Result</th><x-admin.th-sort column="status" :sort="$sort" default="asc">Status</x-admin.th-sort><th scope="col">Reward</th><x-admin.th-sort column="date" :sort="$sort">Created</x-admin.th-sort></tr></thead>
            <tbody>
            @foreach ($matches as $m)
                <tr data-href="{{ route('admin.games.match', $m) }}" class="is-clickable">
                    <td><a href="{{ route('admin.games.match', $m) }}" class="cell-strong">#{{ $m->id }}</a>@if ($m->visibility === 'private') <span data-tooltip="Private match"><x-admin.icon name="lock" size="xs" /><span class="sr-only">private</span></span>@endif</td>
                    <td><x-admin.user :u="$m->creator" :meta="false" /></td>
                    <td><x-admin.user :u="$m->opponent" :meta="false" /></td>
                    <td class="num">{{ $m->creator_score }} – {{ $m->opponent_score }}</td>
                    <td>{{ $m->is_tie ? 'Draw' : ($m->winner?->displayName() ?? '—') }}</td>
                    <td><x-admin.badge :status="$m->status" />@if ($m->cancel_reason)<span class="sub">{{ $m->cancel_reason }}</span>@endif</td>
                    <td>@if ($m->reward_status !== 'none')<x-admin.badge :status="$m->reward_status" />@else<span class="muted">—</span>@endif</td>
                    <td class="nowrap">{{ biz_date($m->created_at) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $matches->links() }}
    @endif
</section>
@endsection
