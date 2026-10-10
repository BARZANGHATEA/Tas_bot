@php
    $admin = auth('admin')->user();
    $settings = app(\App\Services\Settings::class);
    $counts = [
        'withdrawals' => $admin?->hasPermission('withdrawals.view') ? \App\Models\Withdrawal::query()->where('status', 'pending')->count() : 0,
        'reviews' => $admin?->hasPermission('missions.review') ? \App\Models\MissionCompletion::query()->where('status', 'pending_review')->count() : 0,
        'fraud' => $admin?->hasPermission('users.view') ? \App\Models\FraudFlag::query()->where('status', 'open')->count() : 0,
    ];
    // [route, label, permission, icon, active-route patterns, badge count, badge is alert]
    $nav = [
        'Overview' => [
            ['admin.dashboard', 'Dashboard', 'dashboard.view', 'grid', ['admin.dashboard'], 0, false],
            ['admin.budget.index', 'Reward budget', 'dashboard.view', 'wallet', ['admin.budget.*'], 0, false],
        ],
        'Players' => [
            ['admin.users.index', 'Users', 'users.view', 'users', ['admin.users.*'], 0, false],
            ['admin.fraud.index', 'Fraud review', 'users.view', 'shield', ['admin.fraud.*'], $counts['fraud'], true],
            ['admin.referrals.index', 'Referrals', 'referrals.view', 'share', ['admin.referrals.*'], 0, false],
        ],
        'Finance' => [
            ['admin.withdrawals.index', 'Withdrawals', 'withdrawals.view', 'banknote', ['admin.withdrawals.*'], $counts['withdrawals'], true],
            ['admin.ledger.index', 'Ledger', 'users.view', 'book', ['admin.ledger.*'], 0, false],
        ],
        'Engagement' => [
            ['admin.missions.index', 'Missions', 'missions.view', 'target', ['admin.missions.index', 'admin.missions.create', 'admin.missions.edit'], 0, false],
            ['admin.missions.reviews', 'Mission reviews', 'missions.view', 'inbox', ['admin.missions.reviews'], $counts['reviews'], false],
            ['admin.games.rounds', 'Game rounds', 'games.view', 'dice', ['admin.games.rounds'], 0, false],
            ['admin.games.matches', 'Matches', 'games.view', 'swords', ['admin.games.matches', 'admin.games.match'], 0, false],
        ],
        'System' => [
            ['admin.settings.edit', 'Settings', 'settings.manage', 'sliders', ['admin.settings.*'], 0, false],
            ['admin.telegram.index', 'Telegram bot', 'telegram.manage', 'send', ['admin.telegram.*'], 0, false],
            ['admin.admins.index', 'Administrators', 'admins.manage', 'key', ['admin.admins.*'], 0, false],
            ['admin.system.index', 'System & updates', 'system.manage', 'server', ['admin.system.*'], 0, false],
            ['admin.audit.index', 'Audit log', 'audit.view', 'list', ['admin.audit.*'], 0, false],
        ],
    ];
    $reopen = old('_dialog');
    $reopen = is_string($reopen) && preg_match('/^[a-z0-9-]{1,40}$/', $reopen) ? $reopen : null;
    $initials = collect(preg_split('/\s+/', trim($admin->name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · {{ $settings->string('app.name') }} Admin</title>
    <link rel="stylesheet" href="{{ asset('assets/css/admin.css') }}?v={{ @filemtime(public_path('assets/css/admin.css')) }}">
    <script src="{{ asset('assets/js/admin.js') }}?v={{ @filemtime(public_path('assets/js/admin.js')) }}" defer></script>
</head>
<body @if ($reopen) data-reopen-dialog="{{ $reopen }}" @endif>
<a href="#main" class="skip-link">Skip to content</a>
@include('partials.icons')

<div class="shell">
    <aside class="sidebar" id="sidebar" aria-label="Main navigation">
        <a class="sidebar-brand" href="{{ route('admin.dashboard') }}">
            <span class="brand-mark"><x-admin.icon name="dice" /></span>
            <span class="truncate">{{ $settings->string('app.name') }}<span class="brand-sub">Admin console</span></span>
        </a>
        <nav class="sidebar-nav">
            @foreach ($nav as $section => $items)
                @php $visible = array_filter($items, fn ($i) => $admin->hasPermission($i[2])); @endphp
                @if ($visible)
                    <div class="nav-section" id="nav-{{ \Illuminate\Support\Str::slug($section) }}">{{ $section }}</div>
                    <ul role="list" aria-labelledby="nav-{{ \Illuminate\Support\Str::slug($section) }}" style="list-style:none;margin:0;padding:0">
                        @foreach ($visible as [$route, $label, $perm, $icon, $patterns, $badge, $alert])
                            <li>
                                <a href="{{ route($route) }}" class="nav-link" @if (request()->routeIs(...$patterns)) aria-current="page" @endif>
                                    <x-admin.icon :name="$icon" />
                                    <span>{{ $label }}</span>
                                    @if ($badge)<span class="nav-count {{ $alert ? 'is-alert' : '' }}" aria-label="{{ $badge }} pending">{{ $badge > 99 ? '99+' : $badge }}</span>@endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        </nav>
    </aside>
    <div class="overlay" id="overlay" aria-hidden="true"></div>

    <div class="main">
        <header class="topbar">
            <button type="button" class="btn btn-ghost btn-icon menu-toggle" data-menu-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open navigation"><x-admin.icon name="menu" /></button>
            @if ($admin->hasPermission('users.view'))
                <form class="topbar-search" method="get" action="{{ route('admin.users.index') }}" role="search">
                    <x-admin.icon name="search" size="sm" />
                    <label for="global-search" class="sr-only">Search users</label>
                    <input id="global-search" class="input" type="search" name="q" value="{{ request()->routeIs('admin.users.index') ? request('q') : '' }}" placeholder="Search users…" title="Search by name, @username, Telegram ID or U-number" autocomplete="off">
                    <kbd aria-hidden="true">/</kbd>
                </form>
            @endif
            <div class="topbar-spacer"></div>
            <div class="dropdown" data-dropdown>
                <button type="button" class="user-btn" data-dropdown-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="user-menu">
                    <span class="avatar" aria-hidden="true">{{ $initials }}</span>
                    <span class="user-btn-name">{{ $admin->name }}</span>
                    <x-admin.icon name="chevron-down" size="sm" />
                </button>
                <div class="dropdown-menu" id="user-menu" role="menu" hidden>
                    <div class="dropdown-header">
                        <div class="truncate" style="font-weight:600">{{ $admin->name }}</div>
                        <div class="small muted truncate">{{ $admin->email }} · {{ $admin->role->label() }}</div>
                    </div>
                    <a href="{{ route('admin.account') }}" class="dropdown-item" role="menuitem"><x-admin.icon name="user" size="sm" /> My account</a>
                    <form method="post" action="{{ route('admin.logout') }}">@csrf
                        <button type="submit" class="dropdown-item is-danger" role="menuitem"><x-admin.icon name="logout" size="sm" /> Sign out</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="content" id="main" tabindex="-1">
            @if (session('error'))
                <div class="alert alert-danger" role="alert"><x-admin.icon name="x-circle" /><div class="alert-body">{{ session('error') }}</div></div>
            @endif
            @if ($errors->any() && ! $reopen)
                <div class="alert alert-danger" role="alert">
                    <x-admin.icon name="alert" />
                    <div class="alert-body">
                        <strong>Please check the highlighted fields.</strong>
                        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

<div class="toasts" id="toasts" aria-live="polite"></div>
@if (session('success'))<span hidden data-flash="{{ session('success') }}" data-flash-type="success"></span>@endif

<x-admin.modal id="confirm-dialog" title="Are you sure?" description=" " icon="alert" tone="warning">
    <div class="modal-footer">
        <button type="button" class="btn" data-dialog-close>Cancel</button>
        <button type="button" class="btn btn-primary" data-confirm-ok>Confirm</button>
    </div>
</x-admin.modal>
</body>
</html>
