@extends('admin.layout')
@section('title', 'Audit log')
@section('content')
<section class="panel">
    <p class="small muted">Immutable record of administrative and security-relevant actions.</p>
    <form class="filters" method="get">
        <label class="field"><span>Action</span><select name="action"><option value="">Any</option>@foreach ($actions as $a)<option value="{{ $a }}" @selected(request('action') === $a)>{{ $a }}</option>@endforeach</select></label>
        <button class="btn">Filter</button>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Subject</th><th>User</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        @forelse ($logs as $log)
            <tr><td class="small nowrap">{{ biz_date($log->created_at) }}</td>
                <td>{{ $log->admin?->name ?? ucfirst($log->actor_type) }}</td>
                <td class="mono small">{{ $log->action }}</td>
                <td class="small">{{ $log->subject_type }} {{ $log->subject_id ? '#'.$log->subject_id : '' }}</td>
                <td>@include('admin.partials.user-link', ['u' => $log->user])</td>
                <td class="mono small break" style="max-width:380px">{{ $log->data ? \Illuminate\Support\Str::limit(json_encode($log->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 400) : '' }}</td>
                <td class="small">{{ $log->ip }}</td></tr>
        @empty
            <tr><td colspan="7" class="muted">No entries.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $logs->links() }}
</section>
@endsection
