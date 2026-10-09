@extends('admin.layout')
@section('title', 'Administrators')
@section('content')
<div class="flex mb"><span class="spacer"></span><a class="btn" href="{{ route('admin.admins.create') }}">+ New administrator</a></div>
<section class="panel">
    <div class="table-wrap"><table>
        <thead><tr><th>Name</th><th>E-mail</th><th>Role</th><th>Telegram ID</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
        <tbody>
        @foreach ($admins as $a)
            <tr><td>{{ $a->name }}</td><td>{{ $a->email }}</td><td>{{ $a->role->label() }}</td><td class="mono small">{{ $a->telegram_id ?? '—' }}</td>
                <td><x-admin.badge :status="$a->is_active ? 'active' : 'suspended'" /></td><td class="small">{{ biz_date($a->last_login_at) }}</td>
                <td><a class="btn btn-ghost btn-sm" href="{{ route('admin.admins.edit', $a) }}">Edit</a></td></tr>
        @endforeach
        </tbody>
    </table></div>
</section>
<section class="panel">
    <h3>Role permissions</h3>
    <div class="table-wrap"><table>
        <thead><tr><th>Permission</th>@foreach (config('dicegame.roles') as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
        <tbody>@foreach (config('dicegame.permissions') as $perm => $roles)<tr><td class="mono small">{{ $perm }}</td>@foreach (array_keys(config('dicegame.roles')) as $role)<td>{{ in_array($role, $roles, true) ? '✓' : '' }}</td>@endforeach</tr>@endforeach</tbody>
    </table></div>
</section>
@endsection
