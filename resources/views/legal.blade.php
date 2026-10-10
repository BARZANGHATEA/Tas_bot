<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} – {{ $settings->string('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/css/miniapp.css') }}?v={{ @filemtime(public_path('assets/css/miniapp.css')) }}">
    @include('partials.brand')
</head>
<body>
<main class="page">
    <article class="page-card">
        <h1>{{ $title }}</h1>
        <div class="prose">{!! nl2br(e($body)) !!}</div>
        <p class="small muted">{{ $settings->string('legal.disclaimer') }}</p>
        <nav class="page-links" aria-label="Legal"><a href="{{ url('/') }}">Home</a><a href="{{ route('legal', 'terms') }}">Terms</a><a href="{{ route('legal', 'privacy') }}">Privacy</a></nav>
    </article>
</main>
</body>
</html>
