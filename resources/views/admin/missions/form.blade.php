@extends('admin.layout')
@section('title', $mission->exists ? 'Edit mission' : 'New mission')
@section('content')
@php
    $tz = app(\App\Services\Settings::class)->timezone();
    $dt = fn ($d) => $d ? \Carbon\CarbonImmutable::instance($d)->setTimezone($tz)->format('Y-m-d\TH:i') : '';
    $v = fn ($key, $default = null) => old($key, $mission->{$key} instanceof \BackedEnum ? $mission->{$key}->value : ($mission->{$key} ?? $default));
    $invalid = fn ($key) => $errors->has($key) ? 'aria-invalid=true' : '';
@endphp
<nav class="breadcrumb" aria-label="Breadcrumb"><a href="{{ route('admin.missions.index') }}">Missions</a><x-admin.icon name="chevron-right" size="xs" /><span aria-current="page">{{ $mission->exists ? $mission->title : 'New mission' }}</span></nav>
<div class="page-header">
    <div><h1>{{ $mission->exists ? 'Edit mission' : 'New mission' }}</h1><p class="page-sub">Fields marked <span class="req">*</span> are required.</p></div>
</div>

<form method="post" action="{{ $mission->exists ? route('admin.missions.update', $mission) : route('admin.missions.store') }}" novalidate>
    @csrf @if ($mission->exists) @method('put') @endif
    <div class="grid grid-main">
        <div class="stack">
            <section class="card">
                <div class="card-header"><div><h2>Content</h2><p class="card-sub">What players see in the Missions tab</p></div></div>
                <div class="card-body">
                    <div class="form-grid">
                        <label class="field"><span class="field-label">Title <span class="req">*</span></span><input class="input" name="title" value="{{ $v('title') }}" required maxlength="120" {{ $invalid('title') }}>@error('title')<span class="field-error">{{ $message }}</span>@enderror</label>
                        <label class="field"><span class="field-label">Icon</span><input class="input" name="icon" value="{{ $v('icon') }}" maxlength="16" placeholder="📣"><span class="field-help">One emoji.</span></label>
                        <label class="field span-2"><span class="field-label">Description</span><textarea class="textarea" name="description" maxlength="1000">{{ $v('description') }}</textarea></label>
                        <label class="field span-2"><span class="field-label">Image URL</span><input class="input" name="image_url" value="{{ $v('image_url') }}" maxlength="512" placeholder="https://…" {{ $invalid('image_url') }}>@error('image_url')<span class="field-error">{{ $message }}</span>@else<span class="field-help">Optional. Shown instead of the icon.</span>@enderror</label>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-header"><div><h2>Task & verification</h2><p class="card-sub">Rewards are paid only after real verification</p></div></div>
                <div class="card-body">
                    <div class="form-grid">
                        <label class="field"><span class="field-label">Type <span class="req">*</span></span>
                            <select class="select" name="type" data-mission-type>@foreach ($types as $t)<option value="{{ $t->value }}" @selected($v('type') === $t->value)>{{ $t->label() }}</option>@endforeach</select>
                        </label>
                        <label class="field"><span class="field-label">Verification <span class="req">*</span></span>
                            <select class="select" name="verification" {{ $invalid('verification') }}>@foreach ($verifications as $ver)<option value="{{ $ver->value }}" @selected($v('verification') === $ver->value)>{{ $ver->label() }}</option>@endforeach</select>
                            @error('verification')<span class="field-error">{{ $message }}</span>@enderror
                        </label>
                    </div>
                    <div class="alert alert-info small"><x-admin.icon name="info" size="sm" /><div class="alert-body">Telegram channel/group: membership check (the bot must be an admin of the chat). Instagram and custom: manual review only. Website: visit timer or review. Invites and daily activity: automatic from platform data.</div></div>
                    <label class="field" data-for-types="telegram_channel,telegram_group,instagram_follow,website_visit,custom"><span class="field-label">Target</span>
                        <input class="input" name="target" value="{{ $v('target') }}" maxlength="255" placeholder="@channel, -100…, https://t.me/…, Instagram username or https://site" {{ $invalid('target') }}>
                        @error('target')<span class="field-error">{{ $message }}</span>@enderror
                    </label>
                    <label class="field" data-for-types="invite_users,daily_activity"><span class="field-label">Required count</span>
                        <input class="input" type="number" name="target_count" value="{{ $v('target_count') }}" min="1" {{ $invalid('target_count') }}>
                        @error('target_count')<span class="field-error">{{ $message }}</span>@else<span class="field-help">Qualified friends to invite, or games to play today.</span>@enderror
                    </label>
                </div>
            </section>

            <section class="card">
                <div class="card-header"><div><h2>Eligibility & limits</h2><p class="card-sub">Leave empty for no limit</p></div></div>
                <div class="card-body">
                    <div class="form-grid">
                        <label class="field"><span class="field-label">Starts</span><input class="input" type="datetime-local" name="starts_at" value="{{ old('starts_at', $dt($mission->starts_at)) }}"></label>
                        <label class="field"><span class="field-label">Ends</span><input class="input" type="datetime-local" name="ends_at" value="{{ old('ends_at', $dt($mission->ends_at)) }}" {{ $invalid('ends_at') }}>@error('ends_at')<span class="field-error">{{ $message }}</span>@enderror</label>
                        <label class="field"><span class="field-label">Max completions per day</span><input class="input" type="number" name="daily_limit" value="{{ $v('daily_limit') }}" min="1"></label>
                        <label class="field"><span class="field-label">Max completions in total</span><input class="input" type="number" name="total_limit" value="{{ $v('total_limit') }}" min="1"></label>
                        <label class="field"><span class="field-label">Minimum games played</span><input class="input" type="number" name="min_games" value="{{ $v('min_games') }}" min="1"></label>
                        <label class="field"><span class="field-label">Minimum account age (hours)</span><input class="input" type="number" name="min_account_age_hours" value="{{ $v('min_account_age_hours') }}" min="1"></label>
                    </div>
                    <p class="small muted mb-0">Dates use the business time zone ({{ $tz }}).</p>
                </div>
            </section>
        </div>

        <aside class="stack">
            <section class="card">
                <div class="card-header"><h2>Reward</h2></div>
                <div class="card-body">
                    <label class="field"><span class="field-label">Reward (USDT) <span class="req">*</span></span><input class="input" name="reward" value="{{ usdt_input($v('reward')) }}" required inputmode="decimal" {{ $invalid('reward') }}>@error('reward')<span class="field-error">{{ $message }}</span>@enderror</label>
                    <label class="field"><span class="field-label">Repeat</span><select class="select" name="repeat"><option value="once" @selected($v('repeat') === 'once')>Once per player</option><option value="daily" @selected($v('repeat') === 'daily')>Once per player per day</option></select></label>
                    <label class="field"><span class="field-label">Mission budget (USDT)</span><input class="input" name="budget" value="{{ usdt_input($v('budget')) }}" inputmode="decimal" {{ $invalid('budget') }}><span class="field-help">The mission closes automatically when it is used up.</span></label>
                </div>
            </section>
            <section class="card">
                <div class="card-header"><h2>Publishing</h2></div>
                <div class="card-body">
                    <label class="field"><span class="field-label">Status</span><select class="select" name="status">@foreach (['active' => 'Active – visible to players', 'paused' => 'Paused – hidden', 'completed' => 'Completed – closed'] as $s => $label)<option value="{{ $s }}" @selected($v('status') === $s)>{{ $label }}</option>@endforeach</select></label>
                    <label class="field"><span class="field-label">Sort order</span><input class="input" type="number" name="sort_order" value="{{ $v('sort_order', 0) }}"><span class="field-help">Lower numbers appear first.</span></label>
                    <div class="row"><button class="btn btn-primary grow" type="submit">{{ $mission->exists ? 'Save changes' : 'Create mission' }}</button><a class="btn" href="{{ route('admin.missions.index') }}">Cancel</a></div>
                </div>
            </section>
        </aside>
    </div>
</form>
@endsection
