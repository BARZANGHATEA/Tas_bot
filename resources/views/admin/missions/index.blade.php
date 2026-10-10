@extends('admin.layout')
@section('title', 'Missions')
@section('content')
<div class="page-header">
    <div>
        <h1>Missions</h1>
        <p class="page-sub">Tasks players complete for rewards. Telegram missions are checked with the Bot API, Instagram and custom ones by a moderator.</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="{{ route('admin.missions.reviews') }}"><x-admin.icon name="inbox" size="sm" /> Review queue @if ($pendingReviews)<span class="tab-count">{{ $pendingReviews }}</span>@endif</a>
        @adminCan('missions.manage')<a class="btn btn-primary" href="{{ route('admin.missions.create') }}"><x-admin.icon name="plus" size="sm" /> New mission</a>@endadminCan
    </div>
</div>

<nav class="tabs" aria-label="Filter by status">
    <a class="tab" href="{{ route('admin.missions.index') }}" @if (! request('status')) aria-current="page" @endif>All <span class="tab-count">{{ $counts->sum() }}</span></a>
    @foreach (['active' => 'Active', 'paused' => 'Paused', 'completed' => 'Completed'] as $value => $label)
        <a class="tab" href="{{ route('admin.missions.index', ['status' => $value]) }}" @if (request('status') === $value) aria-current="page" @endif>{{ $label }} <span class="tab-count">{{ $counts[$value] ?? 0 }}</span></a>
    @endforeach
</nav>

<section class="card card-flush">
    @if ($missions->isEmpty())
        <x-admin.empty icon="target" title="No missions here" text="Create a mission to give players something to do between games.">
            @adminCan('missions.manage')<a class="btn btn-primary btn-sm" href="{{ route('admin.missions.create') }}"><x-admin.icon name="plus" size="sm" /> New mission</a>@endadminCan
        </x-admin.empty>
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr>
                <x-admin.th-sort column="title" :sort="$sort" default="asc">Mission</x-admin.th-sort>
                <th scope="col">Verification</th>
                <x-admin.th-sort column="reward" :sort="$sort" class="num">Reward (USDT)</x-admin.th-sort>
                <x-admin.th-sort column="completed" :sort="$sort" class="num">Completed</x-admin.th-sort>
                <x-admin.th-sort column="pending" :sort="$sort" class="num">In review</x-admin.th-sort>
                <th scope="col" class="num">Budget used (USDT)</th>
                <th scope="col">Status</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr></thead>
            <tbody>
            @foreach ($missions as $m)
                <tr>
                    <td>
                        <span class="row" style="flex-wrap:nowrap"><span aria-hidden="true" style="font-size:18px;width:24px;text-align:center">{{ $m->icon ?: '•' }}</span>
                        <span class="truncate" style="max-width:280px"><span class="cell-strong">{{ $m->title }}</span><span class="sub truncate">{{ $m->type->label() }}{{ $m->target ? ' · '.$m->target : '' }}{{ $m->repeat === 'daily' ? ' · daily' : '' }}@if ($m->starts_at && $m->starts_at->isFuture()) · starts {{ biz_date($m->starts_at) }}@endif @if ($m->ends_at) · ends {{ biz_date($m->ends_at) }}@endif</span></span></span>
                    </td>
                    <td class="nowrap">{{ $m->verification->label() }}</td>
                    <td class="num"><x-admin.money :amount="$m->reward" :unit="false" /></td>
                    <td class="num">{{ number_format($m->rewarded_count) }}@if ($m->total_limit)<span class="muted"> / {{ number_format($m->total_limit) }}</span>@endif</td>
                    <td class="num">@if ($m->pending_count)<a href="{{ route('admin.missions.reviews') }}">{{ $m->pending_count }}</a>@else<span class="muted">0</span>@endif</td>
                    <td class="num"><x-admin.money :amount="$m->budget_used" :unit="false" />@if ($m->budget !== null)<span class="sub">of {{ usdt($m->budget) }}</span>@endif</td>
                    <td><x-admin.badge :status="$m->status" /></td>
                    <td class="actions">
                        @adminCan('missions.manage')
                        <div class="dropdown" data-dropdown style="display:inline-block">
                            <button type="button" class="btn btn-ghost btn-icon btn-sm" data-dropdown-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Actions for {{ $m->title }}"><x-admin.icon name="more" /></button>
                            <div class="dropdown-menu" role="menu" hidden>
                                <a class="dropdown-item" role="menuitem" href="{{ route('admin.missions.edit', $m) }}"><x-admin.icon name="edit" size="sm" /> Edit</a>
                                <form method="post" action="{{ route('admin.missions.status', $m) }}">@csrf
                                    <input type="hidden" name="status" value="{{ $m->status === 'active' ? 'paused' : 'active' }}">
                                    <button class="dropdown-item" role="menuitem" type="submit"><x-admin.icon :name="$m->status === 'active' ? 'pause' : 'play'" size="sm" /> {{ $m->status === 'active' ? 'Pause' : 'Activate' }}</button>
                                </form>
                                <form method="post" action="{{ route('admin.missions.duplicate', $m) }}">@csrf<button class="dropdown-item" role="menuitem" type="submit"><x-admin.icon name="duplicate" size="sm" /> Duplicate</button></form>
                                <form method="post" action="{{ route('admin.missions.destroy', $m) }}" data-confirm="“{{ $m->title }}” will be removed from the app. Completion history is kept." data-confirm-title="Delete mission?" data-confirm-button="Delete" data-confirm-variant="danger">@csrf @method('delete')
                                    <button class="dropdown-item is-danger" role="menuitem" type="submit"><x-admin.icon name="trash" size="sm" /> Delete</button>
                                </form>
                            </div>
                        </div>
                        @endadminCan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $missions->links() }}
    @endif
</section>
@endsection
