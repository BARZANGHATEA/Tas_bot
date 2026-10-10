@extends('admin.layout')
@section('title', 'Users')
@section('content')
@php
    $active = ['q' => request('q'), 'status' => request('status'), 'flagged' => request('flagged')];
    $hasFilters = array_filter($active);
@endphp
<div class="page-header">
    <div>
        <h1>Users</h1>
        <p class="page-sub">{{ number_format($users->total()) }} {{ $hasFilters ? 'matching' : 'registered' }} players</p>
    </div>
    <div class="page-actions">
        @adminCan('reports.export')<a class="btn" href="{{ route('admin.users.export') }}"><x-admin.icon name="download" size="sm" /> Export CSV</a>@endadminCan
    </div>
</div>

<nav class="tabs" aria-label="Filter by status">
    <a class="tab" href="{{ route('admin.users.index', array_filter(['q' => request('q')])) }}" @if (! request('status') && ! request('flagged')) aria-current="page" @endif>All</a>
    @foreach (\App\Enums\UserStatus::cases() as $s)
        <a class="tab" href="{{ route('admin.users.index', array_filter(['status' => $s->value, 'q' => request('q')])) }}" @if (request('status') === $s->value) aria-current="page" @endif>{{ $s->label() }} <span class="tab-count">{{ number_format($statusCounts[$s->value] ?? 0) }}</span></a>
    @endforeach
    <a class="tab" href="{{ route('admin.users.index', array_filter(['flagged' => 1, 'q' => request('q')])) }}" @if (request('flagged')) aria-current="page" @endif><x-admin.icon name="flag" size="xs" /> Flagged <span class="tab-count">{{ number_format($flaggedCount) }}</span></a>
</nav>

<section class="card card-flush">
    <form class="toolbar" method="get" role="search">
        @if (request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
        @if (request('flagged'))<input type="hidden" name="flagged" value="1">@endif
        <input type="hidden" name="sort" value="{{ $sort['sort'] }}"><input type="hidden" name="dir" value="{{ $sort['dir'] }}">
        <label class="field toolbar-search"><span class="field-label">Search</span>
            <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="Telegram ID, U000123, @username, name or referral code">
        </label>
        <button class="btn" type="submit"><x-admin.icon name="search" size="sm" /> Search</button>
        @if ($hasFilters)<a class="btn btn-ghost" href="{{ route('admin.users.index') }}">Clear</a>@endif
    </form>

    @if ($users->isEmpty())
        <x-admin.empty icon="users" title="No users found" :text="$hasFilters ? 'Try a different search term or clear the filters.' : 'Players appear here after they start the bot or open the Mini App.'">
            @if ($hasFilters)<a class="btn btn-sm" href="{{ route('admin.users.index') }}">Clear filters</a>@endif
        </x-admin.empty>
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr>
                <x-admin.th-sort column="name" :sort="$sort" default="asc">Player</x-admin.th-sort>
                <th scope="col">Telegram ID</th>
                <th scope="col">Status</th>
                <x-admin.th-sort column="balance" :sort="$sort" class="num">Available (USDT)</x-admin.th-sort>
                <th scope="col" class="num">Earned (USDT)</th>
                <x-admin.th-sort column="referrals" :sort="$sort" class="num">Referrals</x-admin.th-sort>
                <x-admin.th-sort column="joined" :sort="$sort">Joined</x-admin.th-sort>
                <x-admin.th-sort column="last_seen" :sort="$sort">Last seen</x-admin.th-sort>
            </tr></thead>
            <tbody>
            @foreach ($users as $u)
                <tr data-href="{{ route('admin.users.show', $u) }}" class="is-clickable">
                    <td><x-admin.user :u="$u" /></td>
                    <td class="mono">{{ $u->telegram_id }}</td>
                    <td><x-admin.badge :status="$u->status" /></td>
                    <td class="num"><x-admin.money :amount="$u->wallet?->available" :unit="false" /></td>
                    <td class="num"><x-admin.money :amount="$u->wallet?->total_earned" :unit="false" /></td>
                    <td class="num">{{ number_format($u->referrals_count) }}</td>
                    <td class="nowrap">{{ biz_date($u->created_at) }}</td>
                    <td class="nowrap">{{ $u->last_seen_at ? $u->last_seen_at->diffForHumans() : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $users->links() }}
    @endif
</section>
@endsection
