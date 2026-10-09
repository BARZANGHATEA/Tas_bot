@extends('admin.layout')
@section('title', 'Missions')
@section('content')
<div class="flex mb">
    <div class="tabs" style="margin:0;border:0">
        @foreach (['' => 'All', 'active' => 'Active', 'paused' => 'Paused', 'completed' => 'Completed'] as $value => $label)
            <a href="{{ route('admin.missions.index', array_filter(['status' => $value])) }}" class="{{ (string) request('status') === $value ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </div>
    <span class="spacer"></span>
    <a class="btn btn-ghost" href="{{ route('admin.missions.reviews') }}">Review queue ({{ $pendingReviews }})</a>
    @adminCan('missions.manage')<a class="btn" href="{{ route('admin.missions.create') }}">+ New mission</a>@endadminCan
</div>
<section class="panel">
    <div class="table-wrap"><table>
        <thead><tr><th>Mission</th><th>Type / verification</th><th class="num">Reward</th><th>Schedule</th><th class="num">Completed</th><th class="num">Pending</th><th class="num">Budget used</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($missions as $m)
            <tr>
                <td><strong>{{ $m->icon }} {{ $m->title }}</strong><br><span class="small muted">{{ $m->target }} {{ $m->repeat === 'daily' ? '· daily' : '' }}</span></td>
                <td class="small">{{ $m->type->label() }}<br><span class="muted">{{ $m->verification->label() }}</span></td>
                <td class="num">{{ usdt($m->reward) }}</td>
                <td class="small">{{ $m->starts_at ? biz_date($m->starts_at) : 'now' }} → {{ $m->ends_at ? biz_date($m->ends_at) : '∞' }}</td>
                <td class="num">{{ $m->rewarded_count }}@if ($m->total_limit) / {{ $m->total_limit }}@endif</td>
                <td class="num">{{ $m->pending_count }}</td>
                <td class="num">{{ usdt($m->budget_used) }}@if ($m->budget !== null) / {{ usdt($m->budget) }}@endif</td>
                <td><x-admin.badge :status="$m->status" /></td>
                <td>
                    @adminCan('missions.manage')
                    <div class="actions">
                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.missions.edit', $m) }}">Edit</a>
                        <form method="post" action="{{ route('admin.missions.status', $m) }}">@csrf
                            <input type="hidden" name="status" value="{{ $m->status === 'active' ? 'paused' : 'active' }}">
                            <button class="btn btn-ghost btn-sm">{{ $m->status === 'active' ? 'Pause' : 'Resume' }}</button>
                        </form>
                        <form method="post" action="{{ route('admin.missions.duplicate', $m) }}">@csrf<button class="btn btn-ghost btn-sm">Duplicate</button></form>
                        <form method="post" action="{{ route('admin.missions.destroy', $m) }}" data-confirm="Delete mission &quot;{{ $m->title }}&quot;? History is kept.">@csrf @method('delete')<button class="btn btn-ghost btn-sm">Delete</button></form>
                    </div>
                    @endadminCan
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">No missions yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $missions->links() }}
</section>
@endsection
