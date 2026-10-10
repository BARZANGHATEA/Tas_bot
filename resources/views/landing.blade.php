<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $settings->string('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/css/miniapp.css') }}?v={{ @filemtime(public_path('assets/css/miniapp.css')) }}">
    @include('partials.brand')
</head>
<body>
<main class="page">
    <div class="page-card center">
        <div class="boot-logo">@include('partials.logo')</div>
        <h1>{{ $settings->string('app.name') }}</h1>
        <p class="muted">{{ $settings->string('app.tagline') }}</p>
        @if ($botUrl)
            <a class="btn btn-primary btn-block" href="{{ $botUrl }}" rel="noopener">Open in Telegram</a>
        @else
            <p class="muted">This app runs inside Telegram.</p>
        @endif
        <p class="small muted">{{ $settings->string('legal.disclaimer') }}</p>
        <nav class="page-links" aria-label="Legal"><a href="{{ route('legal', 'terms') }}">Terms</a><a href="{{ route('legal', 'privacy') }}">Privacy</a></nav>
    </div>
</main>
</body>
</html>
