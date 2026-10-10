@php $appName = app(\App\Services\Settings::class)->string('app.name'); @endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · {{ $appName }} Admin</title>
    <link rel="stylesheet" href="{{ asset('assets/css/admin.css') }}?v={{ @filemtime(public_path('assets/css/admin.css')) }}">
    <script src="{{ asset('assets/js/admin.js') }}?v={{ @filemtime(public_path('assets/js/admin.js')) }}" defer></script>
</head>
<body>
@include('admin.partials.icons')
<main class="auth">
    <div class="auth-card">
        <div class="auth-brand"><span class="brand-mark"><x-admin.icon name="dice" /></span>{{ $appName }}</div>
        <form class="card" method="post" action="{{ route('admin.login.attempt') }}">
            @csrf
            <div class="card-body">
                <h1 style="font-size:20px">Sign in to the admin console</h1>
                <p class="muted text-sm">Staff accounts only.</p>
                @if ($errors->any())
                    <div class="alert alert-danger" role="alert"><x-admin.icon name="alert" /><div class="alert-body">{{ $errors->first() }}</div></div>
                @endif
                <label class="field"><span class="field-label">E-mail</span><input class="input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" @error('email') aria-invalid="true" @enderror></label>
                <label class="field"><span class="field-label">Password</span><input class="input" type="password" name="password" required autocomplete="current-password"></label>
                <button class="btn btn-primary btn-block" type="submit">Sign in</button>
            </div>
        </form>
        <p class="auth-foot">Too many failed attempts temporarily lock sign-in.</p>
    </div>
</main>
</body>
</html>
