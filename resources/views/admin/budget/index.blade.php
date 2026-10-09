@extends('admin.layout')
@section('title', 'Reward budget')
@section('content')
<div class="alert alert-info">The reward budget is an <strong>accounting limit</strong>, not a crypto wallet. Fund it with the amount of USDT you have actually set aside for rewards. Every reward draws from it; when it is empty, games pause their rewards and missions/referrals stop paying. Payouts are always made manually from your own wallet.</div>
<div class="stats">
    <x-admin.stat label="Available budget" :value="usdt($budget->balance).' USDT'" tone="ok" />
    <x-admin.stat label="Total funded" :value="usdt($budget->total_funded).' USDT'" />
    <x-admin.stat label="Total issued" :value="usdt($budget->total_issued).' USDT'" />
    <x-admin.stat label="Returned (reversals)" :value="usdt($budget->total_returned).' USDT'" />
    <x-admin.stat label="Issued today" :value="usdt($issuedToday).' USDT'" :hint="'Platform cap: '.($platformCap->isPositive() ? usdt($platformCap) : 'none').' · per user: '.($userCap->isPositive() ? usdt($userCap) : 'none')" />
</div>
<div class="grid grid-sidebar">
    <section class="panel">
        <h3>Budget history</h3>
        <div class="table-wrap"><table>
            <thead><tr><th>When</th><th>Type</th><th class="num">Amount</th><th class="num">Balance after</th><th>By</th><th>Note</th></tr></thead>
            <tbody>@forelse ($transactions as $t)<tr><td class="small">{{ biz_date($t->created_at) }}</td><td>{{ $t->type }}</td><td class="num {{ str_starts_with($t->amount, '-') ? 'neg' : 'pos' }}">{{ usdt($t->amount, 6) }}</td><td class="num">{{ usdt($t->balance_after, 6) }}</td><td>{{ $t->admin?->name }}</td><td class="small">{{ $t->note }}</td></tr>@empty<tr><td colspan="6" class="muted">No funding yet.</td></tr>@endforelse</tbody>
        </table></div>
        {{ $transactions->links() }}
    </section>
    @adminCan('budget.manage')
    <section class="panel">
        <h3>Fund or reduce</h3>
        <form method="post" action="{{ route('admin.budget.store') }}" data-confirm="Update the reward budget?">
            @csrf
            <label class="field"><span>Action</span><select name="direction"><option value="fund">Add funds</option><option value="defund">Remove funds</option></select></label>
            <label class="field"><span>Amount (USDT)</span><input class="input" name="amount" required inputmode="decimal" pattern="\d{1,12}(\.\d{1,6})?"></label>
            <label class="field"><span>Note</span><input class="input" name="note" required minlength="3" maxlength="255" placeholder="e.g. March rewards top-up"></label>
            <x-admin.confirm-password />
            <button class="btn">Save</button>
        </form>
    </section>
    @endadminCan
</div>
@endsection
