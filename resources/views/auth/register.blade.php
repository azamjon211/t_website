@extends('layouts.app')

@section('title', 'Ro\'yxatdan o\'tish — Chat')
@section('body-class', 'auth-page')

@section('content')
    <main class="auth-card">
        <div class="auth-logo" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M5.5 11.8l11-4.3c.5-.2 1 .1.8.9l-1.9 8.8c-.1.6-.5.8-1 .5l-2.8-2.1-1.4 1.3c-.2.2-.3.3-.6.3l.2-2.9 5.3-4.8c.2-.2 0-.3-.3-.1l-6.5 4.1-2.8-.9c-.6-.2-.6-.6.1-.8z"/></svg>
        </div>
        <h1>Ro'yxatdan o'tish</h1>
        <p class="auth-sub">Boshqalar sizni <b>@username</b> orqali topadi. Username takrorlanmas bo'ladi.</p>

        <form method="POST" action="{{ url('/register') }}" class="auth-form">
            @csrf
            <label class="field">
                <input type="text" name="name" value="{{ old('name') }}" placeholder=" " autocomplete="name" maxlength="64" required autofocus>
                <span>Ism</span>
            </label>
            @error('name') <p class="field-error">{{ $message }}</p> @enderror

            <label class="field field-prefixed">
                <input type="text" name="username" value="{{ old('username') }}" placeholder=" " autocomplete="username" autocapitalize="off" spellcheck="false" minlength="3" maxlength="32" pattern="@?[A-Za-z][A-Za-z0-9_]*" required>
                <span>Username</span>
            </label>
            @error('username') <p class="field-error">{{ $message }}</p> @enderror

            <label class="field">
                <input type="password" name="password" placeholder=" " autocomplete="new-password" minlength="6" required>
                <span>Parol</span>
            </label>
            @error('password') <p class="field-error">{{ $message }}</p> @enderror

            <label class="field">
                <input type="password" name="password_confirmation" placeholder=" " autocomplete="new-password" minlength="6" required>
                <span>Parolni takrorlang</span>
            </label>

            <button type="submit" class="btn-primary">Ro'yxatdan o'tish</button>
        </form>

        <p class="auth-switch">Akkauntingiz bormi? <a href="{{ route('login') }}">Kirish</a></p>
    </main>
@endsection
