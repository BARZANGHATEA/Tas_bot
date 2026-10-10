@extends('admin.layout')
@section('title', 'Withdrawals')
@section('content')
@php
    $filterKeys = ['q', 'network', 'min', 'max', 'from', 'to'];
    $applied = array_filter(request()->only($filterKeys), fn ($v) => $v !== null && $v !== '');
    $labels = ['q' => 'Search', 'network' => 'Network', 'min' => 'Min', 'max' => 'Max', 'from' => 'From', 'to' => 'To'];
@endphp
<div class="page-header">
    <div>
        <h1>Withdrawals</h1>
        <p class="page-sub">Approve, pay and settle player payout requests. Payments are made from your own wallet, then recorded here.</p>
    </div>
    <div class="page-actions">
        @adminCan('reports.export')<a class="btn" href="{{ route('admin.withdrawals.export', request()->query()) }}"><x-admin.icon name="download" size="sm" /> Export CSV</a>@endadminCan
    </div>
</div>

<nav class="tabs" aria-label="Filter by status">
    <a class="tab" href="{{ route('admin.withdrawals.index', $applied) }}" @if (! request('status')) aria-current="page" @endif>All <span class="tab-count">{{ number_format($counts->sum()) }}</span></a>
    @foreach ($statuses as $s)
        <a class="tab" href="{{ route('admin.withdrawals.index', ['status' => $s->value] + $applied) }}" @if (request('status') === $s->value) aria-current="page" @endif>{{ $s->label() }} <span class="tab-count">{{ number_format($counts[$s->value] ?? 0) }}</span></a>
    @endforeach
</nav>

<section class="card card-flush">
    <form class="toolbar" method="get">
        <input type="hidden" name="status" value="{{ request('status') }}">
        <label class="field toolbar-search"><span class="field-label">Search</span><input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="Reference, tx hash, address or user"></label>
        <label class="field"><span class="field-label">Network</span>
            <select class="select" name="network"><option value="">Any</option>@foreach ($networks as $n)<option value="{{ $n['code'] }}" @selected(request('network') === $n['code'])>{{ $n['code'] }}</option>@endforeach</select>
        </label>
        <label class="field" style="width:110px"><span class="field-label">Min USDT</span><input class="input" name="min" value="{{ request('min') }}" inputmode="decimal"></label>
        <label class="field" style="width:110px"><span class="field-label">Max USDT</span><input class="input" name="max" value="{{ request('max') }}" inputmode="decimal"></label>
        <label class="field"><span class="field-label">From</span><input class="input" type="date" name="from" value="{{ request('from') }}"></label>
        <label class="field"><span class="field-label">To</span><input class="input" type="date" name="to" value="{{ request('to') }}"></label>
        <button class="btn" type="submit"><x-admin.icon name="filter" size="sm" /> Apply</button>
    </form>
    @if ($applied)
        <div class="filter-chips" aria-label="Active filters">
            <span class="muted">Filters:</span>
            @foreach ($applied as $key => $value)
                <span class="chip">{{ $labels[$key] }}: {{ $value }} <a href="{{ request()->fullUrlWithQuery([$key => null, 'page' => null]) }}" aria-label="Remove {{ $labels[$key] }} filter"><x-admin.icon name="x" size="xs" /></a></span>
            @endforeach
            <a href="{{ route('admin.withdrawals.index', array_filter(['status' => request('status')])) }}">Clear all</a>
        </div>
    @endif

    @if ($withdrawals->isEmpty())
        <x-admin.empty icon="banknote" :title="$applied || request('status') ? 'No withdrawals match' : 'No withdrawals yet'" :text="$applied ? 'Adjust or clear the filters to see more requests.' : 'Requests from players appear here with their status.'" />
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr>
                <th scope="col">Request</th>
                <th scope="col">Player</th>
                <th scope="col">Recipient</th>
                <x-admin.th-sort column="network" :sort="$sort" default="asc">Network</x-admin.th-sort>
                <x-admin.th-sort column="amount" :sort="$sort" class="num">Amount (USDT)</x-admin.th-sort>
                <th scope="col" class="num">To send (USDT)</th>
                <x-admin.th-sort column="status" :sort="$sort" default="asc">Status</x-admin.th-sort>
                <x-admin.th-sort column="requested" :sort="$sort">Requested</x-admin.th-sort>
            </tr></thead>
            <tbody>
            @foreach ($withdrawals as $w)
                <tr data-href="{{ route('admin.withdrawals.show', $w) }}" class="is-clickable">
                    <td><a href="{{ route('admin.withdrawals.show', $w) }}" class="cell-strong mono">{{ $w->reference }}</a></td>
                    <td><x-admin.user :u="$w->user" /></td>
                    <td>{{ $w->full_name }}<span class="sub mono">{{ \Illuminate\Support\Str::limit($w->address, 14, '…') }}</span></td>
                    <td>{{ $w->network }}</td>
                    <td class="num"><x-admin.money :amount="$w->amount" :unit="false" /></td>
                    <td class="num"><x-admin.money :amount="$w->net_amount" :unit="false" /></td>
                    <td><x-admin.badge :status="$w->status" /></td>
                    <td class="nowrap">{{ biz_date($w->created_at) }}<span class="sub">{{ $w->created_at->diffForHumans() }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $withdrawals->links() }}
    @endif
</section>
@endsection
