@extends('admin.layout')
@section('title', 'Fraud review')
@section('content')
<div class="tabs">
    @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'] as $value => $label)
        <a href="{{ route('admin.fraud.index', ['status' => $value]) }}" class="{{ $status === $value ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>
<div class="alert alert-info">Signals are hints for a human decision, not proof. Shared networks are common on mobile carriers; shared payout wallets and bursts of sign-ups are stronger indicators. Use <em>restrict</em> on the user page to pause payouts while you investigate.</div>
<section class="panel">
    <div class="table-wrap"><table>
        <thead><tr><th>User</th><th>Signal</th><th>Severity</th><th>Details</th><th>Raised</th><th>{{ $status === 'open' ? 'Resolve' : 'Resolution' }}</th></tr></thead>
        <tbody>
        @forelse ($flags as $flag)
            <tr><td>@include('admin.partials.user-link', ['u' => $flag->user])</td><td>{{ str_replace('_', ' ', $flag->type) }}</td><td><x-admin.badge :status="$flag->severity" /></td>
                <td class="mono small break" style="max-width:300px">{{ $flag->details ? json_encode($flag->details) : '' }}</td><td class="small">{{ biz_date($flag->created_at) }}</td>
                <td>
                    @if ($status === 'open')
                        @adminCan('fraud.manage')
                        <form method="post" action="{{ route('admin.fraud.resolve', $flag) }}">@csrf
                            <input class="input" name="note" required minlength="3" maxlength="255" placeholder="What did you decide?">
                            <label class="check small mt"><input type="checkbox" name="clear_user_flag" value="1"> Clear the user's flag if no other signals</label>
                            <div class="actions"><button class="btn btn-sm btn-ok" name="status" value="resolved">Resolved</button><button class="btn btn-sm btn-ghost" name="status" value="dismissed">Dismiss</button></div>
                        </form>
                        @endadminCan
                    @else
                        <span class="small">{{ $flag->resolution_note }}<br><span class="muted">{{ $flag->resolver?->name }} · {{ biz_date($flag->resolved_at) }}</span></span>
                    @endif
                </td></tr>
        @empty
            <tr><td colspan="6" class="muted">Nothing here.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $flags->links() }}
</section>
@endsection
