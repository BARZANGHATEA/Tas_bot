@extends('admin.layout')
@section('title', 'Reward budget')
@section('content')
@php
    $capPct = $platformCap->isPositive() ? min(100, (int) round((float) (string) $issuedToday->dividedBy($platformCap, 4, \Brick\Math\RoundingMode::DOWN) * 100)) : null;
@endphp
<div class="page-header">
    <div><h1>Reward budget</h1><p class="page-sub">An accounting limit for rewards – not a crypto wallet. Fund it with the USDT you have actually set aside.</p></div>
    @adminCan('budget.manage')<div class="page-actions"><button type="button" class="btn btn-primary" data-dialog-open="budget-dialog"><x-admin.icon name="plus" size="sm" /> Fund or reduce</button></div>@endadminCan
</div>
<div class="alert alert-info"><x-admin.icon name="info" /><div class="alert-body">Every reward draws from this budget. When it is empty, solo games pause their rewards and missions and referrals stop paying. Payouts to players are always made manually from your own wallet.</div></div>
<div class="kpis mb-4">
    <x-admin.stat label="Available" icon="wallet" :value="usdt($budget->balance)" unit="USDT" />
    <x-admin.stat label="Issued today" icon="gift" :value="usdt($issuedToday)" unit="USDT" :hint="$capPct !== null ? $capPct.'% of the '.usdt($platformCap).' USDT daily cap' : 'No platform daily cap'">
        @if ($capPct !== null)<div class="meter {{ $capPct >= 90 ? 'is-danger' : ($capPct >= 70 ? 'is-warning' : '') }}" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $capPct }}" aria-label="Daily cap used"><span style="width: {{ $capPct }}%"></span></div>@endif
    </x-admin.stat>
    <x-admin.stat label="Total funded" icon="trending" :value="usdt($budget->total_funded)" unit="USDT" />
    <x-admin.stat label="Total issued" icon="banknote" :value="usdt($budget->total_issued)" unit="USDT" />
    <x-admin.stat label="Returned by reversals" icon="refresh" :value="usdt($budget->total_returned)" unit="USDT" :hint="'Per-player cap: '.($userCap->isPositive() ? usdt($userCap).' USDT/day' : 'none')" />
</div>
<section class="card card-flush">
    <div class="card-header"><h2>Budget history</h2></div>
    @if ($transactions->isEmpty())
        <x-admin.empty icon="wallet" title="The budget has never been funded" text="Rewards cannot be paid until you add funds.">
            @adminCan('budget.manage')<button type="button" class="btn btn-primary btn-sm" data-dialog-open="budget-dialog">Fund budget</button>@endadminCan
        </x-admin.empty>
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col" class="num">Amount (USDT)</th><th scope="col" class="num">Balance after</th><th scope="col">By</th><th scope="col">Note</th></tr></thead>
            <tbody>@foreach ($transactions as $t)
                <tr><td class="nowrap">{{ biz_date($t->created_at) }}</td><td><x-admin.badge :status="$t->type === 'fund' ? 'ok' : 'neutral'" :label="$t->type === 'fund' ? 'Funded' : 'Reduced'" /></td><td class="num"><x-admin.money :amount="$t->amount" :decimals="6" signed :unit="false" /></td><td class="num"><x-admin.money :amount="$t->balance_after" :decimals="6" :unit="false" /></td><td>{{ $t->admin?->name }}</td><td class="small">{{ $t->note }}</td></tr>
            @endforeach</tbody>
        </table></div>
        {{ $transactions->links() }}
    @endif
</section>

@adminCan('budget.manage')
<x-admin.modal id="budget-dialog" title="Fund or reduce the budget" description="Changes are recorded with your name and note in the budget history and the audit log." icon="wallet">
    <form method="post" action="{{ route('admin.budget.store') }}">@csrf
        <div class="modal-body">
            <x-admin.form-errors dialog="budget-dialog" />
            <div class="form-grid">
                <label class="field"><span class="field-label">Action</span><select class="select" name="direction"><option value="fund" @selected(old('direction') !== 'defund')>Add funds</option><option value="defund" @selected(old('direction') === 'defund')>Remove funds</option></select></label>
                <label class="field"><span class="field-label">Amount (USDT) <span class="req">*</span></span><input class="input" name="amount" required inputmode="decimal" pattern="\d{1,12}(\.\d{1,6})?" value="{{ old('amount') }}" placeholder="0.00"></label>
            </div>
            <label class="field"><span class="field-label">Note <span class="req">*</span></span><input class="input" name="note" required minlength="3" maxlength="255" placeholder="e.g. October rewards top-up" value="{{ old('note') }}"></label>
            <x-admin.confirm-password />
        </div>
        <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
</x-admin.modal>
@endadminCan
@endsection
