<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · {{ \App\Models\Setting::get('company_name', 'Fashion Industries INC') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>try{var t=localStorage.getItem('theme')||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-bs-theme',t)}catch(e){}</script>
    @include('partials.glass')
</head>
<body>
<button type="button" class="theme-toggle auth-theme" aria-label="Toggle dark mode" onclick="var d=document.documentElement,n=d.getAttribute('data-bs-theme')==='dark'?'light':'dark';d.setAttribute('data-bs-theme',n);try{localStorage.setItem('theme',n)}catch(e){}"><i class="bi bi-circle-half"></i></button>
<div class="auth-shell">
    <div class="card auth-card">
        <div class="card-body p-4 p-sm-5">
            <div class="auth-logo"><i class="bi bi-bag-check"></i></div>
            <h1 class="h4 text-center mb-1">{{ \App\Models\Setting::get('company_name', 'Fashion Industries INC') }}</h1>
            <p class="text-center text-muted mb-4">Accounting &amp; Inventory</p>

            <form method="POST" action="{{ route('login') }}" novalidate>
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                           class="form-control @error('email') is-invalid @enderror">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input id="password" type="password" name="password" required autocomplete="current-password"
                           class="form-control @error('password') is-invalid @enderror">
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember">
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>
                <button class="btn btn-primary w-100">Sign in</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
