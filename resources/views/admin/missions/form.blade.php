@extends('admin.layout')
@section('title', $mission->exists ? 'Edit mission' : 'New mission')
@section('content')
@php
    $tz = app(\App\Services\Settings::class)->timezone();
    $dt = fn ($d) => $d ? \Carbon\CarbonImmutable::instance($d)->setTimezone($tz)->format('Y-m-d\TH:i') : '';
    $v = fn ($key, $default = null) => old($key, $mission->{$key} instanceof \BackedEnum ? $mission->{$key}->value : ($mission->{$key} ?? $default));
@endphp
<form method="post" action="{{ $mission->exists ? route('admin.missions.update', $mission) : route('admin.missions.store') }}">
    @csrf @if ($mission->exists) @method('put') @endif
    <div class="grid grid-sidebar">
        <section class="panel">
            <h2>Mission</h2>
            <div class="form-grid">
                <label class="field"><span>Title</span><input class="input" name="title" value="{{ $v('title') }}" required maxlength="120"></label>
                <label class="field"><span>Icon (emoji)</span><input class="input" name="icon" value="{{ $v('icon') }}" maxlength="16" placeholder="📣"></label>
            </div>
            <label class="field"><span>Description</span><textarea name="description" maxlength="1000">{{ $v('description') }}</textarea></label>
            <label class="field"><span>Image URL (optional, https)</span><input class="input" name="image_url" value="{{ $v('image_url') }}" maxlength="512"></label>

            <div class="form-grid">
                <label class="field"><span>Type</span>
                    <select name="type" data-mission-type>@foreach ($types as $t)<option value="{{ $t->value }}" @selected($v('type') === $t->value)>{{ $t->label() }}</option>@endforeach</select>
                </label>
                <label class="field"><span>Verification</span>
                    <select name="verification">@foreach ($verifications as $ver)<option value="{{ $ver->value }}" @selected($v('verification') === $ver->value)>{{ $ver->label() }}</option>@endforeach</select>
                    <span class="help">Telegram: membership check (the bot must be an admin of the chat). Instagram/custom: manual review only. Website: visit timer or review. Invites/daily: automatic.</span>
                </label>
            </div>

            <label class="field" data-for-types="telegram_channel,telegram_group,instagram_follow,website_visit,custom"><span>Target</span>
                <input class="input" name="target" value="{{ $v('target') }}" maxlength="255" placeholder="@channel, -100123…, https://t.me/…, instagram username, https://site">
            </label>
            <label class="field" data-for-types="invite_users,daily_activity"><span>Required count (qualified friends / games today)</span>
                <input class="input" type="number" name="target_count" value="{{ $v('target_count') }}" min="1">
            </label>
        </section>

        <section class="panel">
            <h2>Reward & limits</h2>
            <label class="field"><span>Reward (USDT)</span><input class="input" name="reward" value="{{ $v('reward') !== null ? \App\Support\Money::format($v('reward'), 6) : '' }}" required inputmode="decimal"></label>
            <label class="field"><span>Repeat</span><select name="repeat"><option value="once" @selected($v('repeat') === 'once')>Once per user</option><option value="daily" @selected($v('repeat') === 'daily')>Once per user per day</option></select></label>
            <div class="form-grid">
                <label class="field"><span>Starts</span><input class="input" type="datetime-local" name="starts_at" value="{{ old('starts_at', $dt($mission->starts_at)) }}"></label>
                <label class="field"><span>Ends</span><input class="input" type="datetime-local" name="ends_at" value="{{ old('ends_at', $dt($mission->ends_at)) }}"></label>
                <label class="field"><span>Max completions per day</span><input class="input" type="number" name="daily_limit" value="{{ $v('daily_limit') }}" min="1"></label>
                <label class="field"><span>Max completions total</span><input class="input" type="number" name="total_limit" value="{{ $v('total_limit') }}" min="1"></label>
                <label class="field"><span>Reward budget (USDT)</span><input class="input" name="budget" value="{{ $v('budget') !== null ? \App\Support\Money::format($v('budget'), 6) : '' }}" inputmode="decimal"></label>
                <label class="field"><span>Sort order</span><input class="input" type="number" name="sort_order" value="{{ $v('sort_order', 0) }}"></label>
                <label class="field"><span>Eligibility: min. games played</span><input class="input" type="number" name="min_games" value="{{ $v('min_games') }}" min="1"></label>
                <label class="field"><span>Eligibility: min. account age (hours)</span><input class="input" type="number" name="min_account_age_hours" value="{{ $v('min_account_age_hours') }}" min="1"></label>
            </div>
            <p class="small muted">Times are in {{ $tz }}.</p>
            <label class="field"><span>Status</span><select name="status">@foreach (['active', 'paused', 'completed'] as $s)<option value="{{ $s }}" @selected($v('status') === $s)>{{ ucfirst($s) }}</option>@endforeach</select></label>
            <div class="flex"><button class="btn">Save mission</button><a class="btn btn-ghost" href="{{ route('admin.missions.index') }}">Cancel</a></div>
        </section>
    </div>
</form>
@endsection
