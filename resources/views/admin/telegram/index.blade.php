@extends('admin.layout')
@section('title', 'Telegram bot')
@section('content')
@php $registered = $info && ($info['url'] ?? '') === $webhookUrl; @endphp
<div class="page-header">
    <div><h1>Telegram bot</h1><p class="page-sub">Connection health, webhook registration and notification delivery.</p></div>
    @if ($configured)<div class="page-actions"><button type="button" class="btn btn-primary" data-dialog-open="setup-dialog"><x-admin.icon name="refresh" size="sm" /> Configure webhook & menu</button></div>@endif
</div>
@if (! $configured)
    <div class="alert alert-warning"><x-admin.icon name="alert" /><div class="alert-body">Set <code>TELEGRAM_BOT_TOKEN</code>, <code>TELEGRAM_BOT_USERNAME</code> and <code>TELEGRAM_WEBHOOK_SECRET</code> in the server's <code>.env</code> file. Credentials are never stored in the database or shown here.</div></div>
@endif
@if ($error)<div class="alert alert-danger"><x-admin.icon name="x-circle" /><div class="alert-body">{{ $error }}</div></div>@endif

<div class="kpis mb-4">
    <x-admin.stat label="Bot token" icon="key" :value="$tokenHint ? 'Configured' : 'Missing'" :hint="$tokenHint" />
    <x-admin.stat label="Webhook" icon="send" :value="$registered ? 'Registered' : 'Not registered'" :hint="$info ? ($info['pending_update_count'] ?? 0).' pending updates' : null" />
    <x-admin.stat label="Last update received" icon="clock" :value="$lastUpdate ? $lastUpdate->created_at->diffForHumans() : 'Never'" :hint="$lastUpdate?->type" />
    <x-admin.stat label="Notifications" icon="message" :value="number_format($outbox['sent'] ?? 0).' sent'" :hint="number_format($outbox['pending'] ?? 0).' queued · '.number_format($outbox['failed'] ?? 0).' failed'" />
</div>

<div class="grid grid-2">
    <section class="card">
        <div class="card-header"><h2>Connection</h2></div>
        <div class="card-body">
            <dl class="dl">
                <dt>Bot</dt><dd>{{ $bot ? '@'.($bot['username'] ?? '?').' · '.($bot['first_name'] ?? '') : '—' }}</dd>
                <dt>Webhook secret</dt><dd>@if ($secretSet)<x-admin.badge status="ok" label="Configured" />@else<x-admin.badge status="failed" label="Missing or under 16 characters" />@endif</dd>
                <dt>Mini App URL</dt><dd><div class="copy-field"><code>{{ $miniAppUrl }}</code><button type="button" class="btn btn-xs" data-copy="{{ $miniAppUrl }}"><x-admin.icon name="copy" size="xs" /> Copy</button></div></dd>
                <dt>Expected webhook</dt><dd><div class="copy-field"><code>{{ $webhookUrl }}</code><button type="button" class="btn btn-xs" data-copy="{{ $webhookUrl }}"><x-admin.icon name="copy" size="xs" /> Copy</button></div></dd>
                @if ($info)
                    <dt>Registered webhook</dt><dd class="mono small break">{{ ($info['url'] ?? '') ?: '—' }} @if ($registered)<x-admin.badge status="active" label="Matches" />@else<x-admin.badge status="failed" label="Differs" />@endif</dd>
                    <dt>Last error</dt><dd>{{ isset($info['last_error_date']) ? date('Y-m-d H:i', $info['last_error_date']).' – '.($info['last_error_message'] ?? '') : 'None' }}</dd>
                @endif
            </dl>
            <p class="small muted mt-4 mb-0">CLI equivalent: <code>php artisan telegram:setup</code></p>
        </div>
    </section>
    <section class="card">
        <div class="card-header"><h2>Send a test message</h2></div>
        <form class="card-body" method="post" action="{{ route('admin.telegram.test') }}">@csrf
            <label class="field"><span class="field-label">Chat ID or @channel</span><input class="input" name="chat_id" required placeholder="123456789 or @mychannel" value="{{ old('chat_id') }}" @error('chat_id') aria-invalid="true" @enderror>
                @error('chat_id')<span class="field-error">{{ $message }}</span>@else<span class="field-help">Your own user ID (after pressing Start on the bot) or a channel where the bot is an admin.</span>@enderror
            </label>
            <div class="row-between"><a class="small" href="{{ route('admin.settings.edit', 'telegram') }}">Payout & review channel settings</a><button class="btn" type="submit"><x-admin.icon name="send" size="sm" /> Send test</button></div>
        </form>
    </section>
</div>

<section class="card card-flush section-gap">
    <div class="card-header"><div><h2>Recent delivery failures</h2><p class="card-sub">Messages are retried automatically with backoff before they are marked failed</p></div></div>
    @if ($failed->isEmpty())
        <x-admin.empty icon="check-circle" title="No failed notifications" />
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col">Chat</th><th scope="col">Error</th><th scope="col">Last attempt</th></tr></thead>
            <tbody>@foreach ($failed as $m)<tr><td class="mono">{{ $m->chat_id }}</td><td class="small">{{ $m->last_error }}</td><td class="nowrap">{{ biz_date($m->updated_at) }}</td></tr>@endforeach</tbody>
        </table></div>
    @endif
</section>

@if ($configured)
<x-admin.modal id="setup-dialog" title="Configure webhook & menu" description="Registers the webhook (with the secret token), bot commands and the Mini App menu button with Telegram." icon="send">
    <form method="post" action="{{ route('admin.telegram.setup') }}">@csrf
        <div class="modal-body"><x-admin.form-errors dialog="setup-dialog" /><x-admin.confirm-password /></div>
        <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-primary" type="submit">Configure</button></div>
    </form>
</x-admin.modal>
@endif
@endsection
