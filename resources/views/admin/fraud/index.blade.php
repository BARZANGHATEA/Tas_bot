@extends('admin.layout')
@section('title', 'Fraud review')
@section('content')
<div class="page-header"><div><h1>Fraud review</h1><p class="page-sub">Signals are hints for a human decision, not proof. Shared networks are common on mobile carriers; shared payout wallets and sign-up bursts are stronger.</p></div></div>
<nav class="tabs" aria-label="Filter by state">
    @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'] as $value => $label)
        <a class="tab" href="{{ route('admin.fraud.index', ['status' => $value]) }}" @if ($status === $value) aria-current="page" @endif>{{ $label }} <span class="tab-count">{{ number_format($counts[$value] ?? 0) }}</span></a>
    @endforeach
</nav>
<section class="card card-flush">
    @if ($flags->isEmpty())
        <x-admin.empty icon="shield" :title="$status === 'open' ? 'No open signals' : 'Nothing here'" :text="$status === 'open' ? 'Suspicious patterns are flagged automatically and appear here for review.' : null" />
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col">Player</th><th scope="col">Signal</th><th scope="col">Severity</th><th scope="col">Details</th><th scope="col">Raised</th><th scope="col">{{ $status === 'open' ? 'Decision' : 'Resolution' }}</th></tr></thead>
            <tbody>
            @foreach ($flags as $flag)
                <tr>
                    <td><x-admin.user :u="$flag->user" /></td>
                    <td class="cell-strong">{{ ucfirst(str_replace('_', ' ', $flag->type)) }}</td>
                    <td><x-admin.badge :status="$flag->severity" /></td>
                    <td class="cell-wide"><x-admin.details :details="$flag->details" /></td>
                    <td class="nowrap">{{ biz_date($flag->created_at) }}</td>
                    <td class="nowrap">
                        @if ($status === 'open')
                            @adminCan('fraud.manage')<button type="button" class="btn btn-xs" data-dialog-open="resolve-{{ $flag->id }}">Resolve…</button>@endadminCan
                        @else
                            {{ $flag->resolver?->name }} · {{ biz_date($flag->resolved_at) }}<span class="sub" style="white-space:normal">{{ $flag->resolution_note }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $flags->links() }}
    @endif
</section>

@if ($status === 'open')
    @adminCan('fraud.manage')
    @foreach ($flags as $flag)
        <x-admin.modal id="resolve-{{ $flag->id }}" title="Resolve signal" :description="ucfirst(str_replace('_', ' ', $flag->type)).' on '.($flag->user?->displayName() ?? 'player').'. To pause payouts while investigating, restrict the account on the player page.'" icon="shield">
            <form method="post" action="{{ route('admin.fraud.resolve', $flag) }}">@csrf
                <div class="modal-body">
                    <x-admin.form-errors dialog="resolve-{{ $flag->id }}" />
                    <label class="field"><span class="field-label">Decision note <span class="req">*</span></span><input class="input" name="note" required minlength="3" maxlength="255" placeholder="What did you find?"></label>
                    <label class="check mb-0"><input type="checkbox" name="clear_user_flag" value="1"><span class="check-text">Clear the player's flag<small>Only if no other signals remain open.</small></span></label>
                </div>
                <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn" name="status" value="dismissed" type="submit">Dismiss</button><button class="btn btn-primary" name="status" value="resolved" type="submit">Mark resolved</button></div>
            </form>
        </x-admin.modal>
    @endforeach
    @endadminCan
@endif
@endsection
