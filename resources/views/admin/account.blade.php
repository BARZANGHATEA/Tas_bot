@extends('admin.layout')
@section('title', 'My account')
@section('content')
<div class="page-header"><div><h1>My account</h1></div></div>
<div class="grid grid-2" style="align-items:start">
    <section class="card">
        <div class="card-header"><h2>Profile</h2></div>
        <div class="card-body">
            <dl class="dl">
                <dt>Name</dt><dd>{{ $admin->name }}</dd>
                <dt>E-mail</dt><dd>{{ $admin->email }}</dd>
                <dt>Role</dt><dd>{{ $admin->role->label() }}</dd>
                <dt>Telegram ID</dt><dd class="mono">{{ $admin->telegram_id ?? '—' }}</dd>
                <dt>Last sign-in</dt><dd>{{ biz_date($admin->last_login_at) }}{{ $admin->last_login_ip ? ' · '.$admin->last_login_ip : '' }}</dd>
            </dl>
        </div>
    </section>
    <form class="card" method="post" action="{{ route('admin.account.password') }}">
        @csrf @method('put')
        <div class="card-header"><div><h2>Change password</h2><p class="card-sub">At least 12 characters with letters and numbers</p></div></div>
        <div class="card-body">
            <x-admin.confirm-password label="Current password" />
            <label class="field"><span class="field-label">New password</span><input class="input" type="password" name="password" required autocomplete="new-password" @error('password') aria-invalid="true" @enderror>@error('password')<span class="field-error">{{ $message }}</span>@enderror</label>
            <label class="field mb-0"><span class="field-label">Repeat new password</span><input class="input" type="password" name="password_confirmation" required autocomplete="new-password"></label>
        </div>
        <div class="card-footer row-between"><span></span><button class="btn btn-primary" type="submit">Update password</button></div>
    </form>
</div>
@endsection
