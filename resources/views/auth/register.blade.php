@extends('layouts.app')

@section('title', 'Register')

@section('content')
    <div class="auth__card">
        <h1 class="auth__title">Register</h1>
        <p class="auth__lede">Create the account you will send from.</p>

        <form method="POST" action="{{ url('/register') }}">
            @csrf

            <div class="field">
                <label for="name" class="field__label">Name</label>
                <input type="text" class="input" id="name" name="name"
                       value="{{ old('name') }}" required autofocus autocomplete="name">
            </div>

            <div class="field">
                <label for="email" class="field__label">Email</label>
                <input type="email" class="input input--mono" id="email" name="email"
                       value="{{ old('email') }}" required autocomplete="username">
            </div>

            <div class="field">
                <label for="password" class="field__label">Password</label>
                <input type="password" class="input" id="password" name="password"
                       required autocomplete="new-password">
            </div>

            <div class="field">
                <label for="password_confirmation" class="field__label">Confirm password</label>
                <input type="password" class="input" id="password_confirmation"
                       name="password_confirmation" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn--primary btn--block">Register</button>
        </form>

        <p class="auth__alt">
            Already registered? <a href="{{ route('login') }}">Log in</a>
        </p>
    </div>
@endsection
