@extends('admin.layout')
@section('title', $subject->exists ? 'Edit administrator' : 'New administrator')
@section('content')
<nav class="breadcrumb" aria-label="Breadcrumb"><a href="{{ route('admin.admins.index') }}">Administrators</a><x-admin.icon name="chevron-right" size="xs" /><span aria-current="page">{{ $subject->exists ? $subject->name : 'New' }}</span></nav>
<div class="page-header"><div><h1>{{ $subject->exists ? 'Edit administrator' : 'New administrator' }}</h1></div></div>
<form method="post" action="{{ $subject->exists ? route('admin.admins.update', $subject) : route('admin.admins.store') }}" class="card" style="max-width:720px">
    @csrf @if ($subject->exists) @method('put') @endif
    <div class="card-body">
        <div class="form-grid">
            <label class="field"><span class="field-label">Name <span class="req">*</span></span><input class="input" name="name" value="{{ old('name', $subject->name) }}" required maxlength="120" @error('name') aria-invalid="true" @enderror>@error('name')<span class="field-error">{{ $message }}</span>@enderror</label>
            <label class="field"><span class="field-label">E-mail <span class="req">*</span></span><input class="input" type="email" name="email" value="{{ old('email', $subject->email) }}" @disabled($subject->exists) required @error('email') aria-invalid="true" @enderror>@error('email')<span class="field-error">{{ $message }}</span>@elseif ($subject->exists)<span class="field-help">Used to sign in; cannot be changed.</span>@enderror</label>
            <label class="field"><span class="field-label">Role</span><select class="select" name="role">@foreach ($roles as $role)<option value="{{ $role->value }}" @selected(old('role', $subject->role?->value) === $role->value)>{{ $role->label() }}</option>@endforeach</select></label>
            <label class="field"><span class="field-label">Telegram user ID</span><input class="input" name="telegram_id" value="{{ old('telegram_id', $subject->telegram_id) }}" inputmode="numeric" @error('telegram_id') aria-invalid="true" @enderror>@error('telegram_id')<span class="field-error">{{ $message }}</span>@else<span class="field-help">Enables /stats, /pending and /budget in the bot.</span>@enderror</label>
        </div>
        @if ($subject->exists)
            <input type="hidden" name="is_active" value="0">
            <label class="switch"><input type="checkbox" name="is_active" value="1" role="switch" @checked(old('is_active', $subject->is_active))><span class="switch-track" aria-hidden="true"></span><span class="check-text">Active – can sign in</span></label>
        @endif
        <hr>
        <div class="fieldset-title">{{ $subject->exists ? 'Change password' : 'Password' }}</div>
        <p class="fieldset-sub">At least 12 characters with letters and numbers.{{ $subject->exists ? ' Leave empty to keep the current password.' : '' }}</p>
        <div class="form-grid">
            <label class="field"><span class="field-label">Password @unless ($subject->exists)<span class="req">*</span>@endunless</span><input class="input" type="password" name="password" autocomplete="new-password" @required(! $subject->exists) @error('password') aria-invalid="true" @enderror>@error('password')<span class="field-error">{{ $message }}</span>@enderror</label>
            <label class="field"><span class="field-label">Repeat password</span><input class="input" type="password" name="password_confirmation" autocomplete="new-password"></label>
        </div>
        <hr>
        <div style="max-width:340px"><x-admin.confirm-password label="Your password" /></div>
    </div>
    <div class="card-footer row-between"><a class="btn" href="{{ route('admin.admins.index') }}">Cancel</a><button class="btn btn-primary" type="submit">{{ $subject->exists ? 'Save changes' : 'Create administrator' }}</button></div>
</form>
@endsection
