@extends('admin.layout')
@section('title', 'Ledger')
@section('content')
<section class="panel">
    <p class="small muted">The ledger is append-only. Entries cannot be edited or deleted; corrections are new reversal entries.</p>
    <form class="filters" method="get">
        <label class="field"><span>Type</span><select name="type"><option value="">Any</option>@foreach ($types as $t)<option value="{{ $t->value }}" @selected(request('type') === $t->value)>{{ $t->label() }}</option>@endforeach</select></label>
        <label class="field"><span>User</span><input class="input" name="user" value="{{ request('user') }}" placeholder="U000123"></label>
        <label class="field"><span>From</span><input class="input" type="date" name="from" value="{{ request('from') }}"></label>
        <label class="field"><span>To</span><input class="input" type="date" name="to" value="{{ request('to') }}"></label>
        <button class="btn">Filter</button>
        <span class="spacer"></span>
        @adminCan('reports.export')<a class="btn btn-ghost" href="{{ route('admin.ledger.export', request()->query()) }}">Export CSV</a>@endadminCan
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>When</th><th>User</th><th>Type</th><th>Description</th><th class="num">Available Δ</th><th class="num">Reserved Δ</th><th class="num">Available after</th><th>By</th><th>Ref</th></tr></thead>
        <tbody>
        @forelse ($entries as $e)
            <tr><td class="small nowrap">{{ biz_date($e->created_at) }}</td><td>@include('admin.partials.user-link', ['u' => $e->user])</td><td>{{ $e->type->label() }}</td>
                <td class="small">{{ $e->description }} @if ($e->reversal)<x-admin.badge status="reversed" />@endif</td>
                <td class="num {{ str_starts_with($e->available_delta, '-') ? 'neg' : ((float) $e->available_delta ? 'pos' : '') }}">{{ usdt($e->available_delta, 6) }}</td>
                <td class="num">{{ usdt($e->reserved_delta, 6) }}</td><td class="num">{{ usdt($e->available_after, 6) }}</td>
                <td class="small">{{ $e->admin?->name ?? 'system' }}</td><td class="mono small" title="{{ $e->uuid }}">{{ \Illuminate\Support\Str::limit($e->uuid, 8, '') }}</td></tr>
        @empty
            <tr><td colspan="9" class="muted">No entries.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $entries->links() }}
</section>
@endsection
