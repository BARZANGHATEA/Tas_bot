@extends('admin.layout')
@section('title', 'Users')
@section('content')
<section class="panel">
    <form class="filters" method="get">
        <label class="field"><span>Search</span><input class="input" name="q" value="{{ request('q') }}" placeholder="Telegram ID, U000123, @username, name, referral code"></label>
        <label class="field"><span>Status</span>
            <select name="status"><option value="">Any</option>@foreach (\App\Enums\UserStatus::cases() as $s)<option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>@endforeach</select>
        </label>
        <label class="check"><input type="checkbox" name="flagged" value="1" @checked(request('flagged'))> Flagged only</label>
        <button class="btn">Filter</button>
        <span class="spacer"></span>
        @adminCan('reports.export')<a class="btn btn-ghost" href="{{ route('admin.users.export') }}">Export CSV</a>@endadminCan
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>User</th><th>Telegram</th><th>Status</th><th class="num">Available</th><th class="num">Reserved</th><th class="num">Earned</th><th class="num">Referrals</th><th>Joined</th><th>Last seen</th></tr></thead>
        <tbody>
        @forelse ($users as $u)
            <tr>
                <td><a href="{{ route('admin.users.show', $u) }}"><strong>{{ $u->publicId() }}</strong></a> {{ $u->displayName() }} @if ($u->is_flagged)<span class="badge badge-danger">⚑ flagged</span>@endif</td>
                <td class="small">{{ $u->telegram_id }}<br>{{ $u->username ? '@'.$u->username : '' }}</td>
                <td><x-admin.badge :status="$u->status" /></td>
                <td class="num">{{ usdt($u->wallet?->available) }}</td>
                <td class="num">{{ usdt($u->wallet?->reserved) }}</td>
                <td class="num">{{ usdt($u->wallet?->total_earned) }}</td>
                <td class="num">{{ $u->referrals_count }}</td>
                <td class="small nowrap">{{ biz_date($u->created_at) }}</td>
                <td class="small nowrap">{{ biz_date($u->last_seen_at) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">No users found.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $users->links() }}
</section>
@endsection
