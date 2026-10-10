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
@include('partials.icons')
<div id="app" class="app is-booting">
    <section id="boot" class="boot" aria-live="polite">
        <div class="boot-logo">@include('partials.logo')</div>
        <h1 class="boot-title">{{ $settings->string('app.name') }}</h1>
        <p class="boot-text" id="boot-text">Connecting securely…</p>
        <div class="spinner" aria-hidden="true"></div>
    </section>

    <header class="topbar" id="topbar" hidden>
        <button class="topbar-user" type="button" data-action="go" data-tab="home" aria-label="Home">
            <span class="avatar avatar-sm" id="topbar-avatar"></span>
            <span class="topbar-name" id="topbar-name"></span>
        </button>
        <button class="balance-chip" type="button" data-action="go" data-tab="withdraw" aria-label="Available balance – open withdrawals">
            <span class="usdt-dot" aria-hidden="true">₮</span>
            <span id="topbar-balance">0.00</span><span class="unit">USDT</span>
        </button>
    </header>

    <main id="view" class="view" tabindex="-1"></main>

    <nav class="tabbar" id="tabbar" hidden aria-label="Main">
        @foreach (['home' => 'home', 'games' => 'dice', 'missions' => 'target', 'withdraw' => 'wallet'] as $tab => $icon)
            <button type="button" class="tab" data-action="go" data-tab="{{ $tab }}">
                <svg class="icon" aria-hidden="true"><use href="#i-{{ $icon }}"/></svg>
                <span>{{ $settings->string('nav.'.$tab) }}</span>
            </button>
        @endforeach
    </nav>

    <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

    <dialog class="sheet" id="sheet" aria-labelledby="sheet-title" aria-describedby="sheet-text">
        <div class="sheet-handle" aria-hidden="true"></div>
        <h2 class="sheet-title" id="sheet-title"></h2>
        <p class="sheet-text" id="sheet-text"></p>
        <div class="sheet-body" id="sheet-body"></div>
        <div class="sheet-actions">
            <button type="button" class="btn btn-ghost" data-sheet="cancel">Cancel</button>
            <button type="button" class="btn btn-primary" data-sheet="ok">Confirm</button>
        </div>
    </dialog>
</div>

<script type="application/json" id="app-config">@json($config)</script>
<script src="{{ asset('assets/js/miniapp.js') }}?v={{ @filemtime(public_path('assets/js/miniapp.js')) }}" defer></script>
</body>
</html>
