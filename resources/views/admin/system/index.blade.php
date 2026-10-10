@extends('admin.layout')
@section('title', 'System & updates')
@section('content')
@php $schedulerOk = $lastRun && $lastRun->gte(now()->subMinutes(10)); @endphp
<div class="page-header">
    <div><h1>System & updates</h1><p class="page-sub">Maintenance without a terminal. Every task asks for your password and is written to the audit log.</p></div>
    <div class="page-actions"><button type="button" class="btn btn-primary" data-dialog-open="task-dialog"><x-admin.icon name="play" size="sm" /> Run a task</button></div>
</div>
@if ($pending)
    <div class="alert alert-warning"><x-admin.icon name="alert" /><div class="alert-body"><strong>{{ count($pending) }} database update(s) pending.</strong> Run “Update database” after uploading a new version. <button type="button" class="link-btn" data-dialog-open="task-dialog">Run it now</button></div></div>
@endif
@if ($debug && $environment === 'production')
    <div class="alert alert-danger"><x-admin.icon name="x-circle" /><div class="alert-body"><code>APP_DEBUG=true</code> in production exposes error details. Set it to <code>false</code> in <code>.env</code>.</div></div>
@endif
@if ($installerPresent)
    <div class="alert alert-info"><x-admin.icon name="info" /><div class="alert-body">The web installer is locked. For extra safety you can delete <code>public/install.php</code> in your hosting File Manager.</div></div>
@endif

<div class="kpis mb-4">
    <x-admin.stat label="Scheduler" icon="clock" :value="$schedulerOk ? 'Running' : ($lastRun ? 'Stalled' : 'Never ran')" :hint="$lastRun ? 'Last run '.$lastRun->diffForHumans() : 'Set up one of the options below'" />
    <x-admin.stat label="Pending DB updates" icon="server" :value="count($pending)" />
    <x-admin.stat label="Queued notifications" icon="message" :value="number_format($outboxPending)" />
    <x-admin.stat label="Environment" icon="activity" :value="ucfirst($environment)" :hint="'PHP '.$php.' · Laravel '.$laravel" />
</div>

<div class="grid grid-2">
    <section class="card">
        <div class="card-header"><div><h2>Scheduled tasks</h2><p class="card-sub">Use one of these – no terminal needed</p></div></div>
        <div class="card-body stack">
            <div>
                <div class="fieldset-title">cPanel → Cron Jobs (recommended)</div>
                <p class="fieldset-sub mb-2">Choose “Once Per Minute” and paste:</p>
                <div class="copy-field"><code>{{ $cronCommand }}</code><button type="button" class="btn btn-xs" data-copy="{{ $cronCommand }}"><x-admin.icon name="copy" size="xs" /> Copy</button></div>
            </div>
            @if ($cronUrl)
                <div>
                    <div class="fieldset-title">External cron service</div>
                    <p class="fieldset-sub mb-2">Let a service such as cron-job.org open this secret URL every minute:</p>
                    <div class="copy-field"><code>{{ $cronUrl }}</code><button type="button" class="btn btn-xs" data-copy="{{ $cronUrl }}"><x-admin.icon name="copy" size="xs" /> Copy</button></div>
                </div>
            @endif
            <p class="small muted mb-0">Traffic-driven fallback (<code>SCHEDULER_FALLBACK</code>): {{ $fallback ? 'enabled' : 'disabled' }}.</p>
        </div>
    </section>
    <section class="card">
        <div class="card-header"><h2>Status</h2></div>
        <div class="card-body">
            <dl class="dl">
                <dt>Database</dt><dd>{{ $database }}</dd>
                <dt>Installed</dt><dd>{{ $installed['installed_at'] ?? '—' }}</dd>
                <dt>Debug mode</dt><dd>{{ $debug ? 'On' : 'Off' }}</dd>
                <dt>Scheduler last run</dt><dd>{{ $lastRun ? biz_date($lastRun) : 'Never' }} @if ($lastRun)<x-admin.badge :status="$schedulerOk ? 'active' : 'failed'" :label="$schedulerOk ? 'Healthy' : 'Not running regularly'" />@endif</dd>
            </dl>
            @if ($pending)
                <details class="mt-4"><summary class="small">Pending updates ({{ count($pending) }})</summary><ul class="small mono">@foreach ($pending as $m)<li>{{ $m }}</li>@endforeach</ul></details>
            @endif
        </div>
    </section>
</div>

@if ($output)
<section class="card section-gap">
    <div class="card-header"><h2>Last task output</h2></div>
    <div class="card-body"><pre class="output">{{ $output }}</pre></div>
</section>
@endif

<section class="card section-gap">
    <div class="card-header"><h2>Updating to a new version</h2></div>
    <div class="card-body">
        <ol class="mb-0" style="padding-left:18px">
            <li>Download the new release ZIP (it already contains the <code>vendor</code> folder).</li>
            <li>In File Manager, upload it to the application folder and extract it, overwriting files. Keep <code>.env</code> and the <code>storage</code> folder.</li>
            <li>Come back here and run <em>Update database</em>, then <em>Clear caches</em>.</li>
        </ol>
    </div>
</section>

<x-admin.modal id="task-dialog" title="Run a maintenance task" description="Runs immediately on the server. The output is shown on this page." icon="server">
    <form method="post" action="{{ route('admin.system.run') }}">@csrf
        <div class="modal-body">
            <x-admin.form-errors dialog="task-dialog" />
            <label class="field"><span class="field-label">Task</span>
                <select class="select" name="action">
                    <option value="migrate" @selected($pending)>Update database (after uploading a new version)</option>
                    <option value="clear-cache">Clear caches (after editing .env)</option>
                    <option value="schedule">Run scheduled tasks now</option>
                    <option value="dispatch">Send queued Telegram notifications</option>
                    <option value="reconcile">Reconcile wallets with the ledger</option>
                    <option value="expire-matches">Expire stale matches</option>
                </select>
            </label>
            <x-admin.confirm-password />
        </div>
        <div class="modal-footer"><button type="button" class="btn" data-dialog-close>Cancel</button><button class="btn btn-primary" type="submit">Run task</button></div>
    </form>
</x-admin.modal>
@endsection
