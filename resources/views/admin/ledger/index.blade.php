@extends('admin.layout')
@section('title', 'Ledger')
@section('content')
<div class="page-header">
    <div><h1>Ledger</h1><p class="page-sub">Append-only record of every balance change. Corrections are new reversal entries, never edits.</p></div>
    <div class="page-actions">@adminCan('reports.export')<a class="btn" href="{{ route('admin.ledger.export', request()->query()) }}"><x-admin.icon name="download" size="sm" /> Export CSV</a>@endadminCan</div>
</div>
<section class="card card-flush">
    <form class="toolbar" method="get">
        <label class="field"><span class="field-label">Type</span><select class="select" name="type"><option value="">All types</option>@foreach ($types as $t)<option value="{{ $t->value }}" @selected(request('type') === $t->value)>{{ $t->label() }}</option>@endforeach</select></label>
        <label class="field"><span class="field-label">Player ID</span><input class="input" name="user" value="{{ request('user') }}" placeholder="U000123"></label>
        <label class="field"><span class="field-label">From</span><input class="input" type="date" name="from" value="{{ request('from') }}"></label>
        <label class="field"><span class="field-label">To</span><input class="input" type="date" name="to" value="{{ request('to') }}"></label>
        <button class="btn" type="submit"><x-admin.icon name="filter" size="sm" /> Apply</button>
        @if (request()->hasAny(['type', 'user', 'from', 'to']))<a class="btn btn-ghost" href="{{ route('admin.ledger.index') }}">Clear</a>@endif
    </form>
    @if ($entries->isEmpty())
        <x-admin.empty icon="book" title="No entries" text="No ledger entries match these filters." />
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr><x-admin.th-sort column="date" :sort="$sort">Date</x-admin.th-sort><th scope="col">Player</th><x-admin.th-sort column="type" :sort="$sort" default="asc">Type</x-admin.th-sort><th scope="col">Description</th><x-admin.th-sort column="amount" :sort="$sort" class="num">Available Δ (USDT)</x-admin.th-sort><th scope="col" class="num">Reserved Δ</th><th scope="col" class="num">Available after</th><th scope="col">By</th></tr></thead>
            <tbody>
            @foreach ($entries as $e)
                <tr>
                    <td class="nowrap">{{ biz_date($e->created_at) }}<span class="sub mono" title="{{ $e->uuid }}">{{ \Illuminate\Support\Str::limit($e->uuid, 8, '') }}</span></td>
                    <td><x-admin.user :u="$e->user" /></td>
                    <td class="nowrap">{{ $e->type->label() }}@if ($e->reversal) <x-admin.badge status="reversed" />@endif</td>
                    <td class="small cell-wide">{{ $e->description }}</td>
                    <td class="num"><x-admin.money :amount="$e->available_delta" :decimals="6" signed :unit="false" /></td>
                    <td class="num"><x-admin.money :amount="$e->reserved_delta" :decimals="6" signed :unit="false" /></td>
                    <td class="num"><x-admin.money :amount="$e->available_after" :decimals="6" :unit="false" /></td>
                    <td class="small">{{ $e->admin?->name ?? 'System' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $entries->links() }}
    @endif
</section>
@endsection
