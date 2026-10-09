@extends('admin.layout')
@section('title', 'Mission reviews')
@section('content')
<div class="tabs">
    @foreach (['pending_review' => 'Waiting for review', 'rewarded' => 'Approved', 'rejected' => 'Rejected'] as $value => $label)
        <a href="{{ route('admin.missions.reviews', ['status' => $value]) }}" class="{{ $status === $value ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>
<section class="panel">
    <div class="table-wrap"><table>
        <thead><tr><th>User</th><th>Mission</th><th>Proof submitted</th><th class="num">Reward</th><th>Submitted</th><th>{{ $status === 'pending_review' ? 'Decision' : 'Reviewed' }}</th></tr></thead>
        <tbody>
        @forelse ($completions as $c)
            <tr>
                <td>@include('admin.partials.user-link', ['u' => $c->user])</td>
                <td><strong>{{ $c->mission?->title }}</strong><br><span class="small muted">{{ $c->mission?->type->label() }} · {{ $c->mission?->target }}</span></td>
                <td class="pre break" style="max-width:320px">{{ $c->proof }}</td>
                <td class="num">{{ usdt($c->reward) }}</td>
                <td class="small nowrap">{{ biz_date($c->submitted_at) }}</td>
                <td>
                    @if ($status === 'pending_review')
                        @adminCan('missions.review')
                        <div class="actions">
                            <form method="post" action="{{ route('admin.missions.reviews.approve', $c) }}" data-confirm="Approve and pay {{ usdt($c->reward) }} USDT?">@csrf<button class="btn btn-ok btn-sm">Approve</button></form>
                        </div>
                        <details class="action-box mt"><summary class="small">Reject</summary>
                            <form method="post" action="{{ route('admin.missions.reviews.reject', $c) }}">@csrf
                                <input class="input" name="reason" required minlength="3" maxlength="255" placeholder="Reason shown to the user">
                                <button class="btn btn-danger btn-sm mt">Reject</button>
                            </form>
                        </details>
                        @endadminCan
                    @else
                        <span class="small">{{ biz_date($c->reviewed_at) }} {{ $c->reviewer?->name }}</span>
                        @if ($c->reject_reason)<br><span class="small muted">{{ $c->reject_reason }}</span>@endif
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Nothing here.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $completions->links() }}
</section>
@endsection
