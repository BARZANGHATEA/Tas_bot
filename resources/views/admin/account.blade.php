@extends('admin.layout')
@section('title', 'My account')
@section('content')
<div class="grid grid-2">
    <section class="panel">
        <h2>Profile</h2>
        <dl class="kv">
            <dt>Name</dt><dd>{{ $admin->name }}</dd>
            <dt>E-mail</dt><dd>{{ $admin->email }}</dd>
            <dt>Role</dt><dd>{{ $admin->role->label() }}</dd>
            <dt>Telegram ID</dt><dd>{{ $admin->telegram_id ?? '—' }}</dd>
            <dt>Last sign-in</dt><dd>{{ biz_date($admin->last_login_at) }}</dd>
        </dl>
    </section>
    <section class="panel">
        <h2>Change password</h2>
        <form method="post" action="{{ route('admin.account.password') }}">
            @csrf @method('put')
            <x-admin.confirm-password label="Current password" />
            <label class="field"><span>New password (min. 12 characters, letters and numbers)</span><input class="input" type="password" name="password" required autocomplete="new-password"></label>
            <label class="field"><span>Repeat new password</span><input class="input" type="password" name="password_confirmation" required autocomplete="new-password"></label>
            <button class="btn" type="submit">Update password</button>
        </form>
    </section>
</div>
@endsection
