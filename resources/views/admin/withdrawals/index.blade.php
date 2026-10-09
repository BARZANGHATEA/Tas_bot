@extends('admin.layout')
@section('title', 'Withdrawals')
@section('content')
<div class="tabs">
    <a href="{{ route('admin.withdrawals.index') }}" class="{{ request('status') ? '' : 'active' }}">All</a>
    @foreach ($statuses as $s)
        <a href="{{ route('admin.withdrawals.index', ['status' => $s->value]) }}" class="{{ request('status') === $s->value ? 'active' : '' }}">{{ $s->label() }} <span class="muted">{{ $counts[$s->value] ?? 0 }}</span></a>
    @endforeach
</div>
<section class="panel">
    <form class="filters" method="get">
        <input type="hidden" name="status" value="{{ request('status') }}">
        <label class="field"><span>Search</span><input class="input" name="q" value="{{ request('q') }}" placeholder="Reference, tx hash, address, user"></label>
        <label class="field"><span>Network</span><select name="network"><option value="">Any</option>@foreach ($networks as $n)<option value="{{ $n['code'] }}" @selected(request('network') === $n['code'])>{{ $n['code'] }}</option>@endforeach</select></label>
        <label class="field"><span>Min amount</span><input class="input" name="min" value="{{ request('min') }}" inputmode="decimal" size="6"></label>
        <label class="field"><span>Max amount</span><input class="input" name="max" value="{{ request('max') }}" inputmode="decimal" size="6"></label>
        <label class="field"><span>From</span><input class="input" type="date" name="from" value="{{ request('from') }}"></label>
        <label class="field"><span>To</span><input class="input" type="date" name="to" value="{{ request('to') }}"></label>
        <button class="btn">Filter</button>
        <span class="spacer"></span>
        @adminCan('reports.export')<a class="btn btn-ghost" href="{{ route('admin.withdrawals.export', request()->query()) }}">Export CSV</a>@endadminCan
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>Reference</th><th>User</th><th>Recipient</th><th>Network</th><th class="num">Amount</th><th class="num">Net</th><th>Status</th><th>Requested</th></tr></thead>
        <tbody>
        @forelse ($withdrawals as $w)
            <tr>
                <td><a href="{{ route('admin.withdrawals.show', $w) }}"><strong>{{ $w->reference }}</strong></a></td>
                <td>@include('admin.partials.user-link', ['u' => $w->user])</td>
                <td>{{ $w->full_name }}<br><span class="mono small muted">{{ \Illuminate\Support\Str::limit($w->address, 18) }}</span></td>
                <td>{{ $w->network }}</td>
                <td class="num">{{ usdt($w->amount) }}</td>
                <td class="num">{{ usdt($w->net_amount) }}</td>
                <td><x-admin.badge :status="$w->status" /></td>
                <td class="small nowrap">{{ biz_date($w->created_at) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">No withdrawals match.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $withdrawals->links() }}
</section>
@endsection
