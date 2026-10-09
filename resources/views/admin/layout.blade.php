@php
    $admin = auth('admin')->user();
    $settings = app(\App\Services\Settings::class);
    $pendingWithdrawals = $admin?->hasPermission('withdrawals.view') ? \App\Models\Withdrawal::query()->where('status', 'pending')->count() : 0;
    $pendingReviews = $admin?->hasPermission('missions.review') ? \App\Models\MissionCompletion::query()->where('status', 'pending_review')->count() : 0;
    $openFlags = $admin?->hasPermission('users.view') ? \App\Models\FraudFlag::query()->where('status', 'open')->count() : 0;
    $nav = [
        'Overview' => [
            ['admin.dashboard', 'Dashboard', 'dashboard.view', null, 'admin.dashboard'],
            ['admin.budget.index', 'Reward budget', 'dashboard.view', null, 'admin.budget.*'],
        ],
        'Players' => [
            ['admin.users.index', 'Users', 'users.view', null, 'admin.users.*'],
            ['admin.fraud.index', 'Fraud review', 'users.view', $openFlags, 'admin.fraud.*'],
            ['admin.referrals.index', 'Referrals', 'referrals.view', null, 'admin.referrals.*'],
        ],
        'Finance' => [
            ['admin.withdrawals.index', 'Withdrawals', 'withdrawals.view', $pendingWithdrawals, 'admin.withdrawals.*'],
            ['admin.ledger.index', 'Ledger', 'users.view', null, 'admin.ledger.*'],
        ],
        'Engagement' => [
            ['admin.missions.index', 'Missions', 'missions.view', null, 'admin.missions.index|admin.missions.create|admin.missions.edit'],
            ['admin.missions.reviews', 'Mission reviews', 'missions.view', $pendingReviews, 'admin.missions.reviews'],
            ['admin.games.rounds', 'Game rounds', 'games.view', null, 'admin.games.rounds'],
            ['admin.games.matches', 'Matches', 'games.view', null, 'admin.games.match*'],
        ],
        'System' => [
            ['admin.settings.edit', 'Settings', 'settings.manage', null, 'admin.settings.*'],
            ['admin.telegram.index', 'Telegram bot', 'telegram.manage', null, 'admin.telegram.*'],
            ['admin.admins.index', 'Administrators', 'admins.manage', null, 'admin.admins.*'],
            ['admin.audit.index', 'Audit log', 'audit.view', null, 'admin.audit.*'],
        ],
    ];
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
<body>
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="{{ route('admin.dashboard') }}">
            <span class="brand-mark">🎲</span>
            <span>{{ $settings->string('app.name') }}</span>
        </a>
        <nav>
            @foreach ($nav as $section => $items)
                @php $visible = array_filter($items, fn ($i) => $admin->hasPermission($i[2])); @endphp
                @if ($visible)
                    <div class="nav-section">{{ $section }}</div>
                    @foreach ($visible as [$route, $label, $perm, $badge, $pattern])
                        <a href="{{ route($route) }}" class="nav-link {{ request()->routeIs(...explode('|', $pattern)) ? 'active' : '' }}">
                            <span>{{ $label }}</span>
                            @if ($badge)<span class="nav-badge">{{ $badge }}</span>@endif
                        </a>
                    @endforeach
                @endif
            @endforeach
        </nav>
    </aside>

    <div class="main">
        <header class="topbar">
            <button type="button" class="icon-btn" data-toggle-sidebar aria-label="Menu">☰</button>
            <div class="topbar-title">@yield('title', 'Dashboard')</div>
            <div class="topbar-user">
                <a href="{{ route('admin.account') }}" class="muted">{{ $admin->name }} · {{ $admin->role->label() }}</a>
                <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="btn btn-sm btn-ghost">Sign out</button></form>
            </div>
        </header>

        <main class="content">
            @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
            @if ($errors->any())
                <div class="alert alert-danger">
                    <strong>Please fix the following:</strong>
                    <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
