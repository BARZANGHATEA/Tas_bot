@extends('admin.layout')
@section('title', 'Referrals')
@section('content')
<div class="page-header">
    <div><h1>Referrals <x-admin.badge :status="$active ? 'active' : 'paused'" :label="$active ? 'Campaign active' : 'Campaign paused'" /></h1><p class="page-sub">Multi-level rewards for inviting players who qualify. Every reward can be reversed with a reason.</p></div>
    @adminCan('settings.manage')<div class="page-actions"><a class="btn" href="{{ route('admin.settings.edit', 'referral') }}"><x-admin.icon name="sliders" size="sm" /> Edit rules</a></div>@endadminCan
</div>

<div class="grid grid-3 mb-4">
    <section class="card card-flush">
        <div class="card-header"><h2>Reward rules</h2></div>
        <div class="table-wrap"><table class="table table-compact">
            <thead><tr><th scope="col">Level</th><th scope="col" class="num">Qualification bonus</th><th scope="col" class="num">Share</th></tr></thead>
            <tbody>@foreach ($levels as $level => $rule)<tr><td>Level {{ $level }}</td><td class="num"><x-admin.money :amount="$rule['fixed']" :decimals="6" :unit="false" /></td><td class="num">{{ $rule['percent'] }}%</td></tr>@endforeach</tbody>
        </table></div>
    </section>
    <section class="card card-flush">
        <div class="card-header"><h2>Top inviters</h2></div>
        @if ($topReferrers->isEmpty())<x-admin.empty icon="share" title="No referrals yet" />@else
        <div class="table-wrap"><table class="table table-compact">
            <thead><tr><th scope="col">Player</th><th scope="col" class="num">Invited</th><th scope="col" class="num">Qualified</th></tr></thead>
            <tbody>@foreach ($topReferrers as $u)<tr><td><x-admin.user :u="$u" :meta="false" /></td><td class="num">{{ $u->referrals_count }}</td><td class="num">{{ $u->qualified_count }}</td></tr>@endforeach</tbody>
        </table></div>@endif
    </section>
    <section class="card card-flush">
        <div class="card-header"><h2>Suspicious activity</h2><a class="btn btn-xs" href="{{ route('admin.fraud.index') }}">Fraud review</a></div>
        @if ($suspicious->isEmpty())<x-admin.empty icon="shield" title="No open signals" />@else
        <div class="table-wrap"><table class="table table-compact">
            <tbody>@foreach ($suspicious as $flag)<tr><td><x-admin.user :u="$flag->user" :meta="false" /></td><td>{{ str_replace('_', ' ', $flag->type) }}</td><td><x-admin.badge :status="$flag->severity" /></td></tr>@endforeach</tbody>
        </table></div>@endif
    </section>
</div>

<section class="card card-flush">
    <div class="card-header"><div><h2>Reward history</h2></div></div>
    <form class="toolbar" method="get">
        <label class="field"><span class="field-label">Status</span><select class="select" name="status"><option value="">All</option>@foreach (['credited', 'reversed', 'skipped'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>@endforeach</select></label>
        <label class="field"><span class="field-label">Player ID</span><input class="input" name="user" value="{{ request('user') }}" placeholder="U000123"></label>
        <button class="btn" type="submit"><x-admin.icon name="filter" size="sm" /> Apply</button>
        @if (request('status') || request('user'))<a class="btn btn-ghost" href="{{ route('admin.referrals.index') }}">Clear</a>@endif
    </form>
    @if ($rewards->isEmpty())
        <x-admin.empty icon="gift" title="No referral rewards" text="Rewards appear when invited players qualify or earn." />
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr><x-admin.th-sort column="date" :sort="$sort">Date</x-admin.th-sort><th scope="col">Beneficiary</th><th scope="col">From</th><x-admin.th-sort column="level" :sort="$sort" default="asc">Level</x-admin.th-sort><th scope="col">Event</th><x-admin.th-sort column="amount" :sort="$sort" class="num">Amount (USDT)</x-admin.th-sort><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            @foreach ($rewards as $r)
                <tr>
                    <td class="nowrap">{{ biz_date($r->created_at) }}</td>
                    <td><x-admin.user :u="$r->beneficiary" /></td>
                    <td><x-admin.user :u="$r->sourceUser" :meta="false" /></td>
                    <td>L{{ $r->level }}</td>
                    <td>{{ ucfirst($r->event) }}@if ($r->base_amount)<span class="sub">{{ usdt($r->base_amount, 6) }} base</span>@endif</td>
                    <td class="num"><x-admin.money :amount="$r->amount" :decimals="6" :unit="false" /></td>
                    <td><x-admin.badge :status="$r->status" />@if ($r->skip_reason || $r->reverse_reason)<span class="sub">{{ $r->skip_reason ?? $r->reverse_reason }}</span>@endif</td>
                    <td class="actions">@if ($r->status === 'credited')@adminCan('rewards.reverse')<button type="button" class="btn btn-xs" data-dialog-open="reverse-ref-{{ $r->id }}">Reverse</button>@endadminCan @endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $rewards->links() }}
    @endif
</section>

@adminCan('rewards.reverse')
@foreach ($rewards as $r)
    @if ($r->status === 'credited')
        <x-admin.modal id="reverse-ref-{{ $r->id }}" title="Reverse referral reward" description="The amount is removed from the beneficiary's available balance and returned to the reward budget." icon="alert" tone="danger">
            <form method="post" action="{{ route('admin.referrals.reverse', $r) }}">@csrf
                <div class="modal-body">
                    <x-admin.form-errors dialog="reverse-ref-{{ $r->id }}" />
                    <div class="summary"><dl class="dl"><dt>Beneficiary</dt><dd>{{ $r->beneficiary?->displayName() }}</dd><dt>Amount</dt><dd><x-admin.money :amount="$r->amount" :decimals="6" /></dd></dl></div>
                    <label class="field"><span class="field-label">Reason <span class="req">*</span></span><input class="input" name="reason" required minlength="3" maxlength="255"></label>
                    <x-admin.confirm-password />
                </div>
                <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-danger" type="submit">Reverse reward</button></div>
            </form>
        </x-admin.modal>
    @endif
@endforeach
@endadminCan
@endsection
