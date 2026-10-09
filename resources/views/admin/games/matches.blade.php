@extends('admin.layout')
@section('title', 'Two-player matches')
@section('content')
<div class="tabs">
    @foreach (['' => 'All', 'waiting' => 'Waiting', 'ready' => 'Ready', 'playing' => 'Playing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $value => $label)
        <a href="{{ route('admin.games.matches', array_filter(['status' => $value])) }}" class="{{ (string) request('status') === $value ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>
<section class="panel">
    <div class="table-wrap"><table>
        <thead><tr><th>#</th><th>Creator</th><th>Opponent</th><th>Score</th><th>Winner</th><th>Status</th><th>Reward</th><th>Created</th></tr></thead>
        <tbody>
        @forelse ($matches as $m)
            <tr><td><a href="{{ route('admin.games.match', $m) }}">#{{ $m->id }}</a> @if ($m->visibility === 'private')🔒@endif</td>
                <td>@include('admin.partials.user-link', ['u' => $m->creator])</td><td>@include('admin.partials.user-link', ['u' => $m->opponent])</td>
                <td>{{ $m->creator_score }}–{{ $m->opponent_score }}</td>
                <td>{{ $m->is_tie ? 'Draw' : ($m->winner?->publicId() ?? '—') }}</td>
                <td><x-admin.badge :status="$m->status" /> @if ($m->cancel_reason)<span class="small muted">{{ $m->cancel_reason }}</span>@endif</td>
                <td><x-admin.badge :status="$m->reward_status" /></td>
                <td class="small">{{ biz_date($m->created_at) }}</td></tr>
        @empty
            <tr><td colspan="8" class="muted">No matches.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $matches->links() }}
</section>
@endsection
