@extends('layouts.app')

@section('title', 'Log in')

@section('content')
    <div class="auth__card">
        <h1 class="auth__title">Log in</h1>
        <p class="auth__lede">Sign in to upload recipient files and run sends.</p>

        <form method="POST" action="{{ url('/login') }}">
            @csrf

            <div class="field">
                <label for="email" class="field__label">Email</label>
                <input type="email" class="input input--mono" id="email" name="email"
                       value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>

            <div class="field">
                <label for="password" class="field__label">Password</label>
                <input type="password" class="input" id="password" name="password"
                       required autocomplete="current-password">
            </div>

            <div class="field">
                <label class="check" for="remember">
                    <input type="checkbox" id="remember" name="remember" value="1">
                    Keep me signed in
                </label>
            </div>

            <button type="submit" class="btn btn--primary btn--block">Log in</button>
        </form>

        <p class="auth__alt">
            No account yet? <a href="{{ route('register') }}">Register</a>
        </p>
    </div>
@endsection
