@extends('admin.layout')
@section('title', 'Audit log')
@section('content')
<div class="page-header"><div><h1>Audit log</h1><p class="page-sub">Immutable record of administrative and security-relevant actions.</p></div></div>
<section class="card card-flush">
    <form class="toolbar" method="get">
        <label class="field" style="min-width:240px"><span class="field-label">Action</span><select class="select" name="action" data-autosubmit><option value="">All actions</option>@foreach ($actions as $a)<option value="{{ $a }}" @selected(request('action') === $a)>{{ $a }}</option>@endforeach</select></label>
        <noscript><button class="btn" type="submit">Apply</button></noscript>
        @if (request('action'))<a class="btn btn-ghost" href="{{ route('admin.audit.index') }}">Clear</a>@endif
    </form>
    @if ($logs->isEmpty())
        <x-admin.empty icon="list" title="No entries" />
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col">When</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Subject</th><th scope="col">Player</th><th scope="col">Details</th><th scope="col">IP</th></tr></thead>
            <tbody>
            @foreach ($logs as $log)
                <tr>
                    <td class="nowrap">{{ biz_date($log->created_at) }}</td>
                    <td>{{ $log->admin?->name ?? ucfirst($log->actor_type) }}</td>
                    <td><code>{{ $log->action }}</code></td>
                    <td class="small nowrap">{{ $log->subject_type }}{{ $log->subject_id ? ' #'.$log->subject_id : '' }}</td>
                    <td><x-admin.user :u="$log->user" :meta="false" /></td>
                    <td class="cell-wide"><x-admin.details :details="$log->data" /></td>
                    <td class="small mono">{{ $log->ip }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $logs->links() }}
    @endif
</section>
@endsection
