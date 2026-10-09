<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · {{ app(\App\Services\Settings::class)->string('app.name') }} Admin</title>
    <link rel="stylesheet" href="{{ asset('assets/css/admin.css') }}?v={{ @filemtime(public_path('assets/css/admin.css')) }}">
</head>
<body>
<main class="auth">
    <form class="auth-card" method="post" action="{{ route('admin.login.attempt') }}">
        @csrf
        <h1 style="font-size:22px">🎲 Admin sign in</h1>
        <p class="muted">{{ app(\App\Services\Settings::class)->string('app.name') }} dashboard</p>
        @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
        <label class="field"><span>E-mail</span><input class="input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
        <label class="field"><span>Password</span><input class="input" type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn" style="width:100%;justify-content:center" type="submit">Sign in</button>
    </form>
</main>
</body>
</html>
