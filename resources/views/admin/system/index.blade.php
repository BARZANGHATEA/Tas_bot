@extends('admin.layout')
@section('title', 'System & maintenance')
@section('content')
@if ($pending)
    <div class="alert alert-warn"><strong>{{ count($pending) }} database update(s) pending.</strong> After uploading a new version, run “Update database” below.</div>
@endif
@if ($installerPresent)
    <div class="alert alert-info">The web installer is locked, but you can delete <code>public/install.php</code> in your hosting File Manager for extra safety.</div>
@endif
@if ($debug && $environment === 'production')
    <div class="alert alert-danger"><code>APP_DEBUG=true</code> in production exposes error details. Set it to <code>false</code> in <code>.env</code>.</div>
@endif

<div class="grid grid-2">
    <section class="panel">
        <h3>Status</h3>
        <dl class="kv">
            <dt>PHP / Laravel</dt><dd>{{ $php }} / {{ $laravel }}</dd>
            <dt>Database</dt><dd>{{ $database }}</dd>
            <dt>Environment</dt><dd>{{ $environment }}{{ $debug ? ' (debug on)' : '' }}</dd>
            <dt>Installed</dt><dd>{{ $installed['installed_at'] ?? '—' }}</dd>
            <dt>Pending DB updates</dt><dd>{{ count($pending) }}</dd>
            <dt>Scheduler last run</dt>
            <dd>
                @if ($lastRun)
                    {{ biz_date($lastRun) }}
                    @if ($lastRun->lt(now()->subMinutes(10)))<x-admin.badge status="failed" /> not running regularly @else<x-admin.badge status="active" />@endif
                @else
                    never <x-admin.badge status="failed" />
                @endif
            </dd>
            <dt>Queued notifications</dt><dd>{{ $outboxPending }}</dd>
        </dl>
    </section>

    <section class="panel">
        <h3>Scheduled tasks (no terminal needed)</h3>
        <p class="small">Use <strong>one</strong> of these. cPanel → <em>Cron Jobs</em> is usually available even without SSH: choose “Once Per Minute” and paste:</p>
        <p><code class="break">{{ $cronCommand }}</code> <a href="#" class="small" data-copy="{{ $cronCommand }}">Copy</a></p>
        @if ($cronUrl)
            <p class="small">Or let a free external service (e.g. cron-job.org) open this secret URL every minute:</p>
            <p><code class="break">{{ $cronUrl }}</code> <a href="#" class="small" data-copy="{{ $cronUrl }}">Copy</a></p>
        @endif
        <p class="small muted">Traffic-driven fallback (<code>SCHEDULER_FALLBACK</code>): {{ $fallback ? 'enabled' : 'disabled' }}.</p>
    </section>
</div>

<section class="panel">
    <h3>Run a maintenance task</h3>
    <form method="post" action="{{ route('admin.system.run') }}" class="filters" data-confirm="Run this maintenance task now?">
        @csrf
        <label class="field"><span>Task</span>
            <select name="action">
                <option value="migrate">Update database (after uploading a new version)</option>
                <option value="clear-cache">Clear caches (after editing .env)</option>
                <option value="schedule">Run scheduled tasks now</option>
                <option value="dispatch">Send queued Telegram notifications</option>
                <option value="reconcile">Reconcile wallets with the ledger</option>
                <option value="expire-matches">Expire stale matches</option>
            </select>
        </label>
        <label class="field"><span>Your password</span><input type="password" name="confirm_password" class="input" required autocomplete="current-password"></label>
        <button class="btn">Run</button>
    </form>
    @if ($output)
        <h3 class="mt">Output</h3>
        <pre class="mono small" style="background:#f4f5fb;padding:12px;border-radius:8px;white-space:pre-wrap">{{ $output }}</pre>
    @endif
    @if ($pending)
        <details class="mt"><summary class="small">Pending updates</summary><ul class="small mono">@foreach ($pending as $m)<li>{{ $m }}</li>@endforeach</ul></details>
    @endif
</section>

<section class="panel">
    <h3>Updating to a new version without a terminal</h3>
    <ol class="small">
        <li>Download the new release ZIP (it already contains the <code>vendor</code> folder).</li>
        <li>In File Manager, upload it to the application folder and extract it, overwriting files. <strong>Keep</strong> <code>.env</code> and the <code>storage</code> folder.</li>
        <li>Come back here and run <em>Update database</em>, then <em>Clear caches</em>.</li>
    </ol>
</section>
@endsection
