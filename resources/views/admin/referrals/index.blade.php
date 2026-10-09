@extends('admin.layout')
@section('title', 'Referrals')
@section('content')
<div class="grid grid-3">
    <section class="panel">
        <div class="panel-head"><h3>Current rules</h3><x-admin.badge :status="$active ? 'active' : 'paused'" /></div>
        <table><thead><tr><th>Level</th><th class="num">Qualification bonus</th><th class="num">Reward share</th></tr></thead>
            <tbody>@foreach ($levels as $level => $rule)<tr><td>L{{ $level }}</td><td class="num">{{ usdt($rule['fixed'], 6) }}</td><td class="num">{{ $rule['percent'] }}%</td></tr>@endforeach</tbody></table>
        @adminCan('settings.manage')<a class="btn btn-ghost btn-sm mt" href="{{ route('admin.settings.edit', 'referral') }}">Edit referral rules</a>@endadminCan
    </section>
    <section class="panel">
        <h3>Top inviters</h3>
        <table><thead><tr><th>User</th><th class="num">Invited</th><th class="num">Qualified</th></tr></thead>
            <tbody>@forelse ($topReferrers as $u)<tr><td>@include('admin.partials.user-link', ['u' => $u])</td><td class="num">{{ $u->referrals_count }}</td><td class="num">{{ $u->qualified_count }}</td></tr>@empty<tr><td colspan="3" class="muted">No referrals yet.</td></tr>@endforelse</tbody></table>
    </section>
    <section class="panel">
        <h3>Suspicious referral activity</h3>
        <table><tbody>@forelse ($suspicious as $flag)<tr><td>@include('admin.partials.user-link', ['u' => $flag->user])</td><td>{{ str_replace('_', ' ', $flag->type) }}</td><td><x-admin.badge :status="$flag->severity" /></td></tr>@empty<tr><td class="muted">No open signals.</td></tr>@endforelse</tbody></table>
        <a class="btn btn-ghost btn-sm mt" href="{{ route('admin.fraud.index') }}">Fraud review</a>
    </section>
</div>

<section class="panel">
    <div class="panel-head"><h3>Referral reward history</h3></div>
    <form class="filters" method="get">
        <label class="field"><span>Status</span><select name="status"><option value="">Any</option>@foreach (['credited', 'reversed', 'skipped'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>@endforeach</select></label>
        <label class="field"><span>User</span><input class="input" name="user" value="{{ request('user') }}" placeholder="U000123"></label>
        <button class="btn">Filter</button>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>When</th><th>Beneficiary</th><th>From</th><th>Level</th><th>Event</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($rewards as $r)
            <tr><td class="small nowrap">{{ biz_date($r->created_at) }}</td><td>@include('admin.partials.user-link', ['u' => $r->beneficiary])</td><td>@include('admin.partials.user-link', ['u' => $r->sourceUser])</td>
                <td>L{{ $r->level }}</td><td>{{ $r->event }}@if ($r->base_amount)<span class="small muted"> of {{ usdt($r->base_amount, 6) }}</span>@endif</td>
                <td class="num">{{ usdt($r->amount, 6) }}</td>
                <td><x-admin.badge :status="$r->status" />@if ($r->skip_reason)<br><span class="small muted">{{ $r->skip_reason }}</span>@endif @if ($r->reverse_reason)<br><span class="small muted">{{ $r->reverse_reason }}</span>@endif</td>
                <td>
                    @if ($r->status === 'credited')
                        @adminCan('rewards.reverse')
                        <details class="action-box"><summary class="small">Reverse</summary>
                            <form method="post" action="{{ route('admin.referrals.reverse', $r) }}" data-confirm="Reverse this referral reward?">@csrf
                                <label class="field"><span>Reason</span><input class="input" name="reason" required minlength="3"></label>
                                <x-admin.confirm-password />
                                <button class="btn btn-danger btn-sm">Reverse</button>
                            </form>
                        </details>
                        @endadminCan
                    @endif
                </td></tr>
        @empty
            <tr><td colspan="8" class="muted">No referral rewards.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $rewards->links() }}
</section>
@endsection
