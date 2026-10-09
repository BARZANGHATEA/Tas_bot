@extends('admin.layout')
@section('title', $subject->exists ? 'Edit administrator' : 'New administrator')
@section('content')
<form method="post" action="{{ $subject->exists ? route('admin.admins.update', $subject) : route('admin.admins.store') }}" class="panel" style="max-width:640px" data-confirm="Save this administrator?">
    @csrf @if ($subject->exists) @method('put') @endif
    <label class="field"><span>Name</span><input class="input" name="name" value="{{ old('name', $subject->name) }}" required maxlength="120"></label>
    <label class="field"><span>E-mail</span><input class="input" type="email" name="email" value="{{ old('email', $subject->email) }}" @disabled($subject->exists) required></label>
    <label class="field"><span>Role</span><select name="role">@foreach ($roles as $role)<option value="{{ $role->value }}" @selected(old('role', $subject->role?->value) === $role->value)>{{ $role->label() }}</option>@endforeach</select></label>
    <label class="field"><span>Telegram user ID (enables admin bot commands)</span><input class="input" name="telegram_id" value="{{ old('telegram_id', $subject->telegram_id) }}" inputmode="numeric"></label>
    @if ($subject->exists)
        <input type="hidden" name="is_active" value="0">
        <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $subject->is_active))> Active</label>
    @endif
    <label class="field"><span>{{ $subject->exists ? 'New password (leave empty to keep)' : 'Password' }} – min. 12 characters, letters and numbers</span><input class="input" type="password" name="password" autocomplete="new-password" @required(! $subject->exists)></label>
    <label class="field"><span>Repeat password</span><input class="input" type="password" name="password_confirmation" autocomplete="new-password"></label>
    <x-admin.confirm-password />
    <div class="flex"><button class="btn">Save</button><a class="btn btn-ghost" href="{{ route('admin.admins.index') }}">Cancel</a></div>
</form>
@endsection
