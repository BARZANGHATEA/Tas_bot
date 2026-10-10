@extends('admin.layout')
@section('title', 'Mission reviews')
@section('content')
<div class="page-header">
    <div><h1>Mission reviews</h1><p class="page-sub">Check each proof before paying. Rejected players are told why and can resubmit.</p></div>
</div>
<nav class="tabs" aria-label="Filter by review state">
    @foreach (['pending_review' => 'Waiting for review', 'rewarded' => 'Approved', 'rejected' => 'Rejected'] as $value => $label)
        <a class="tab" href="{{ route('admin.missions.reviews', ['status' => $value]) }}" @if ($status === $value) aria-current="page" @endif>{{ $label }} <span class="tab-count">{{ number_format($counts[$value] ?? 0) }}</span></a>
    @endforeach
</nav>
<section class="card card-flush">
    @if ($completions->isEmpty())
        <x-admin.empty icon="check-circle" :title="$status === 'pending_review' ? 'Nothing to review' : 'Nothing here yet'" :text="$status === 'pending_review' ? 'New submissions for Instagram and custom missions will appear here.' : null" />
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col">Player</th><th scope="col">Mission</th><th scope="col">Proof</th><th scope="col" class="num">Reward (USDT)</th><th scope="col">Submitted</th><th scope="col">{{ $status === 'pending_review' ? 'Decision' : 'Reviewed' }}</th></tr></thead>
            <tbody>
            @foreach ($completions as $c)
                <tr>
                    <td><x-admin.user :u="$c->user" /></td>
                    <td><span class="cell-strong">{{ $c->mission?->title }}</span><span class="sub">{{ $c->mission?->type->label() }}{{ $c->mission?->target ? ' · '.$c->mission->target : '' }}</span></td>
                    <td class="pre break" style="max-width:320px">{{ $c->proof }}</td>
                    <td class="num"><x-admin.money :amount="$c->reward" :unit="false" /></td>
                    <td class="nowrap">{{ biz_date($c->submitted_at) }}</td>
                    <td class="nowrap">
                        @if ($status === 'pending_review')
                            @adminCan('missions.review')
                            <div class="btn-group">
                                <form method="post" action="{{ route('admin.missions.reviews.approve', $c) }}" data-confirm="Pay {{ usdt($c->reward) }} USDT to {{ $c->user->displayName() }} for “{{ $c->mission?->title }}”?" data-confirm-title="Approve submission" data-confirm-button="Approve and pay">@csrf<button class="btn btn-success btn-xs" type="submit"><x-admin.icon name="check" size="xs" /> Approve</button></form>
                                <button type="button" class="btn btn-xs" data-dialog-open="reject-{{ $c->id }}"><x-admin.icon name="x" size="xs" /> Reject</button>
                            </div>
                            @endadminCan
                        @else
                            {{ biz_date($c->reviewed_at) }}<span class="sub">{{ $c->reviewer?->name }}{{ $c->reject_reason ? ' · '.$c->reject_reason : '' }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $completions->links() }}
    @endif
</section>

@if ($status === 'pending_review')
    @adminCan('missions.review')
    @foreach ($completions as $c)
        <x-admin.modal id="reject-{{ $c->id }}" title="Reject submission" :description="'The player sees this reason and can submit again for “'.$c->mission?->title.'”.'" icon="x-circle" tone="danger">
            <form method="post" action="{{ route('admin.missions.reviews.reject', $c) }}">@csrf
                <div class="modal-body">
                    <x-admin.form-errors dialog="reject-{{ $c->id }}" />
                    <label class="field mb-0"><span class="field-label">Reason <span class="req">*</span></span><input class="input" name="reason" required minlength="3" maxlength="255" placeholder="e.g. Username not found among followers"></label>
                </div>
                <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-danger" type="submit">Reject</button></div>
            </form>
        </x-admin.modal>
    @endforeach
    @endadminCan
@endif
@endsection
