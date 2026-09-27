<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Smart Emailing')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">

    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>

@auth
    <header class="topbar">
        <div class="topbar__inner">
            <a class="brand" href="{{ url('/') }}">
                Smart Emailing
                <span class="brand__mark">Delivery</span>
            </a>

            <nav class="topnav">
                <a class="topnav__link {{ request()->routeIs('batches.*') ? 'is-current' : '' }}"
                   href="{{ route('batches.index') }}">Batches</a>
                <a class="topnav__link {{ request()->routeIs('smtp.*') ? 'is-current' : '' }}"
                   href="{{ route('smtp.index') }}">Relays</a>
            </nav>

            <div class="topbar__user">
                <span class="whoami">
                    <span class="whoami__name">{{ auth()->user()->name }}</span>
                    <span class="tag {{ auth()->user()->is_admin ? 'tag--accent' : '' }}">
                        {{ auth()->user()->is_admin ? 'Admin' : 'User' }}
                    </span>
                </span>

                <form method="POST" action="{{ route('logout') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="btn btn--sm">Log out</button>
                </form>
            </div>
        </div>
    </header>

    <main class="shell @yield('shell-class')">
        @include('partials.flash')
        @yield('content')
    </main>
@else
    <main class="auth">
        <div class="auth__box">
            <div class="auth__brand">
                <span class="auth__brand-name">Smart Emailing</span>
                <span class="brand__mark">Delivery</span>
            </div>

            @include('partials.flash')
            @yield('content')
        </div>
    </main>
@endauth

@stack('scripts')
</body>
</html>
