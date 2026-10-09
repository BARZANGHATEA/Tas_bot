<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
    <meta name="color-scheme" content="dark light">
    <meta name="robots" content="noindex">
    <title>{{ $settings->string('app.name') }}</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link rel="stylesheet" href="{{ asset('assets/css/miniapp.css') }}?v={{ @filemtime(public_path('assets/css/miniapp.css')) }}">
    @include('partials.brand')
</head>
<body>
<div id="app" class="app is-booting">
    <section id="boot" class="boot" aria-live="polite">
        <div class="boot-logo">@include('partials.logo')</div>
        <h1 class="boot-title">{{ $settings->string('app.name') }}</h1>
        <p class="boot-text" id="boot-text">Connecting securely…</p>
        <div class="spinner" aria-hidden="true"></div>
    </section>

    <header class="topbar" id="topbar" hidden>
        <button class="topbar-user" type="button" data-action="go" data-tab="home" aria-label="Profile">
            <span class="avatar avatar-sm" id="topbar-avatar"></span>
            <span class="topbar-name" id="topbar-name"></span>
        </button>
        <button class="balance-chip" type="button" data-action="go" data-tab="withdraw" aria-label="Balance">
            <span class="usdt-dot" aria-hidden="true">₮</span>
            <span id="topbar-balance">0.00</span>
        </button>
    </header>

    <main id="view" class="view" tabindex="-1"></main>

    <nav class="tabbar" id="tabbar" hidden aria-label="Main">
        @foreach (['home' => 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z',
                   'games' => 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm3.5 4a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm7 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zM12 10.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zM8.5 14a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm7 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z',
                   'missions' => 'M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm0 4a6 6 0 1 0 0 12 6 6 0 0 0 0-12zm0 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5z',
                   'withdraw' => 'M4 6h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2zm12 5.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zM6 3h11v2H6z'] as $tab => $path)
            <button type="button" class="tab" data-action="go" data-tab="{{ $tab }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $path }}" fill="currentColor" fill-rule="evenodd"/></svg>
                <span>{{ $settings->string('nav.'.$tab) }}</span>
            </button>
        @endforeach
    </nav>

    <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
</div>

<script type="application/json" id="app-config">@json($config)</script>
<script src="{{ asset('assets/js/miniapp.js') }}?v={{ @filemtime(public_path('assets/js/miniapp.js')) }}" defer></script>
</body>
</html>
