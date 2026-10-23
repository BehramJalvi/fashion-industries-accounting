<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ \App\Models\Setting::get('company_name', 'Fashion Industries INC') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>try{var t=localStorage.getItem('theme')||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-bs-theme',t)}catch(e){}</script>
    @include('partials.glass')
    <style>@media print { .sidebar, header.navbar, .no-print, .alert, .sidebar-scrim { display: none !important; } }</style>
    @stack('styles')
</head>
<body>
<div class="sidebar-scrim no-print" onclick="document.body.classList.remove('nav-open')"></div>
<nav class="sidebar">
    <div class="brand"><i class="bi bi-bag-check me-2"></i>{{ \App\Models\Setting::get('company_name', 'Fashion Industries INC') }}</div>
    <ul class="nav flex-column pb-4">
        @foreach (config('menu') as $item)
            @if (isset($item['heading']))
                <li class="heading">{{ $item['heading'] }}</li>
            @elseif (Route::has($item['route']) && auth()->user()->can($item['can']))
                <li class="nav-item">
                    <a class="nav-link {{ str_starts_with($item['route'], 'reports.') ? (request()->routeIs($item['route']) ? 'active' : '') : (request()->routeIs(\Illuminate\Support\Str::beforeLast($item['route'], '.') . '.*') ? 'active' : '') }}"
                       href="{{ route($item['route']) }}"><i class="bi bi-{{ $item['icon'] }}"></i>{{ $item['label'] }}</a>
                </li>
            @endif
        @endforeach
    </ul>
</nav>

<div class="content-wrap">
    <header class="navbar px-4">
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="menu-toggle" aria-label="Menu" onclick="document.body.classList.toggle('nav-open')"><i class="bi bi-list fs-5"></i></button>
            <span class="navbar-text fw-semibold">@yield('title', 'Dashboard')</span>
        </div>
        <div class="d-flex align-items-center gap-2">
        <button type="button" class="theme-toggle" aria-label="Toggle dark mode" onclick="toggleTheme()"><i class="bi bi-moon-stars" id="themeIcon"></i></button>
        <div class="dropdown">
            <a class="text-decoration-none dropdown-toggle" href="#" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle me-1"></i>{{ auth()->user()->name }}
                <span class="badge text-bg-secondary">{{ auth()->user()->roles->pluck('label')->join(', ') }}</span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Log out</button>
                    </form>
                </li>
            </ul>
        </div>
        </div>
    </header>

    <main class="p-4">
        @include('partials.alerts')
        @yield('content')
    </main>
</div>

<script>
function toggleTheme(){var d=document.documentElement,n=d.getAttribute('data-bs-theme')==='dark'?'light':'dark';d.setAttribute('data-bs-theme',n);try{localStorage.setItem('theme',n)}catch(e){}syncIcon()}
function syncIcon(){var i=document.getElementById('themeIcon');if(i)i.className='bi bi-'+(document.documentElement.getAttribute('data-bs-theme')==='dark'?'sun':'moon-stars')}
syncIcon();
</script>
@stack('scripts')
</body>
</html>
