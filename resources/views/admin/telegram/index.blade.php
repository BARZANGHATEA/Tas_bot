@extends('admin.layout')
@section('title', 'Telegram bot')
@section('content')
@if (! $configured)
    <div class="alert alert-warn">Set <code>TELEGRAM_BOT_TOKEN</code>, <code>TELEGRAM_BOT_USERNAME</code> and <code>TELEGRAM_WEBHOOK_SECRET</code> in the server's <code>.env</code> file. Credentials are never stored in the database or shown here.</div>
@endif
@if ($error)<div class="alert alert-danger">{{ $error }}</div>@endif

<div class="grid grid-2">
    <section class="panel">
        <h3>Bot</h3>
        <dl class="kv">
            <dt>Token</dt><dd>{{ $tokenHint ? 'configured ('.$tokenHint.')' : 'missing' }}</dd>
            <dt>Webhook secret</dt><dd>{{ $secretSet ? 'configured' : 'missing or too short (16+ chars)' }}</dd>
            <dt>Bot</dt><dd>{{ $bot ? '@'.($bot['username'] ?? '?').' ('.($bot['first_name'] ?? '').')' : '—' }}</dd>
            <dt>Mini App URL</dt><dd class="mono small break">{{ $miniAppUrl }}</dd>
            <dt>Webhook URL</dt><dd class="mono small break">{{ $webhookUrl }}</dd>
        </dl>
        @if ($configured)
            <form method="post" action="{{ route('admin.telegram.setup') }}" class="mt" data-confirm="Register the webhook, commands and menu button with Telegram?">
                @csrf
                <x-admin.confirm-password />
                <button class="btn">Configure webhook & menu</button>
            </form>
            <p class="small muted">Equivalent CLI: <code>php artisan telegram:setup</code></p>
        @endif
    </section>

    <section class="panel">
        <h3>Webhook status</h3>
        @if ($info)
            <dl class="kv">
                <dt>Registered URL</dt><dd class="mono small break">{{ ($info['url'] ?? '') ?: '—' }} @if (($info['url'] ?? '') === $webhookUrl)<x-admin.badge status="active" />@else<x-admin.badge status="failed" />@endif</dd>
                <dt>Pending updates</dt><dd>{{ $info['pending_update_count'] ?? 0 }}</dd>
                <dt>Last error</dt><dd>{{ isset($info['last_error_date']) ? date('Y-m-d H:i', $info['last_error_date']).' – '.($info['last_error_message'] ?? '') : 'none' }}</dd>
                <dt>Max connections</dt><dd>{{ $info['max_connections'] ?? '—' }}</dd>
            </dl>
        @else
            <p class="muted">Not available.</p>
        @endif
        <p class="small muted mt">Last update received: {{ $lastUpdate ? biz_date($lastUpdate->created_at).' ('.$lastUpdate->type.')' : 'never' }}</p>
    </section>
</div>

<div class="grid grid-2">
    <section class="panel">
        <h3>Notification outbox</h3>
        <dl class="kv">
            @foreach (['pending', 'sent', 'failed'] as $s)<dt>{{ ucfirst($s) }}</dt><dd>{{ $outbox[$s] ?? 0 }}</dd>@endforeach
        </dl>
        @if ($failed->isNotEmpty())
            <h3 class="mt">Recent failures</h3>
            <table><tbody>@foreach ($failed as $m)<tr><td class="mono small">{{ $m->chat_id }}</td><td class="small">{{ $m->last_error }}</td><td class="small">{{ biz_date($m->updated_at) }}</td></tr>@endforeach</tbody></table>
        @endif
    </section>
    <section class="panel">
        <h3>Send a test message</h3>
        <p class="small muted">Use your own Telegram user ID (after pressing Start on the bot) or a channel the bot is an admin of.</p>
        <form method="post" action="{{ route('admin.telegram.test') }}">
            @csrf
            <label class="field"><span>Chat ID or @channel</span><input class="input" name="chat_id" required></label>
            <button class="btn btn-ghost">Send test</button>
        </form>
        <p class="small muted mt">Channels for public payout confirmations and the private review feed are set in <a href="{{ route('admin.settings.edit', 'telegram') }}">Settings → Telegram channels</a>.</p>
    </section>
</div>
@endsection
