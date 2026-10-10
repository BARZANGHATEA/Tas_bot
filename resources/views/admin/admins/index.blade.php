@extends('admin.layout')
@section('title', 'Administrators')
@section('content')
<div class="page-header">
    <div><h1>Administrators</h1><p class="page-sub">Give each person the least privileged role they need.</p></div>
    <div class="page-actions"><a class="btn btn-primary" href="{{ route('admin.admins.create') }}"><x-admin.icon name="plus" size="sm" /> New administrator</a></div>
</div>
<section class="card card-flush mb-4">
    <div class="table-wrap"><table class="table">
        <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Telegram ID</th><th scope="col">Status</th><th scope="col">Last sign-in</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
        @foreach ($admins as $a)
            <tr>
                <td><span class="user-cell"><span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($a->name, 0, 1)) }}</span><span><span class="user-cell-name">{{ $a->name }}</span>@if ($a->is(auth('admin')->user())) <span class="badge badge-neutral badge-plain">You</span>@endif<span class="user-cell-meta">{{ $a->email }}</span></span></span></td>
                <td>{{ $a->role->label() }}</td>
                <td class="mono">{{ $a->telegram_id ?? '—' }}</td>
                <td><x-admin.badge :status="$a->is_active ? 'active' : 'suspended'" :label="$a->is_active ? 'Active' : 'Deactivated'" /></td>
                <td class="nowrap">{{ $a->last_login_at ? $a->last_login_at->diffForHumans() : 'Never' }}</td>
                <td class="actions"><a class="btn btn-xs" href="{{ route('admin.admins.edit', $a) }}"><x-admin.icon name="edit" size="xs" /> Edit</a></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</section>
<section class="card card-flush">
    <div class="card-header"><div><h2>Role permissions</h2><p class="card-sub">Defined in config/dicegame.php</p></div></div>
    <div class="table-wrap"><table class="table table-compact matrix">
        <thead><tr><th scope="col">Permission</th>@foreach (config('dicegame.roles') as $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead>
        <tbody>@foreach (config('dicegame.permissions') as $perm => $roles)<tr><th scope="row" class="mono small" style="text-transform:none;letter-spacing:0;font-weight:500;color:var(--text-2);background:none">{{ $perm }}</th>@foreach (array_keys(config('dicegame.roles')) as $role)<td>@if (in_array($role, $roles, true))<x-admin.icon name="check" size="sm" class="yes" /><span class="sr-only">allowed</span>@else<span class="muted" aria-label="not allowed">–</span>@endif</td>@endforeach</tr>@endforeach</tbody>
    </table></div>
</section>
@endsection
