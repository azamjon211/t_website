@extends('layouts.app')

@section('title', 'Kirish — Chat')
@section('body-class', 'auth-page')

@section('content')
    <main class="auth-card">
        <div class="auth-logo" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M5.5 11.8l11-4.3c.5-.2 1 .1.8.9l-1.9 8.8c-.1.6-.5.8-1 .5l-2.8-2.1-1.4 1.3c-.2.2-.3.3-.6.3l.2-2.9 5.3-4.8c.2-.2 0-.3-.3-.1l-6.5 4.1-2.8-.9c-.6-.2-.6-.6.1-.8z"/></svg>
        </div>
        <h1>Chatga kirish</h1>
        <p class="auth-sub">Username va parolingizni kiriting.</p>

        <form method="POST" action="{{ url('/login') }}" class="auth-form">
            @csrf
            <label class="field">
                <input type="text" name="username" value="{{ old('username') }}" placeholder=" " autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus>
                <span>Username</span>
            </label>
            @error('username') <p class="field-error">{{ $message }}</p> @enderror

            <label class="field">
                <input type="password" name="password" placeholder=" " autocomplete="current-password" required>
                <span>Parol</span>
            </label>
            @error('password') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit" class="btn-primary">Kirish</button>
        </form>

        <p class="auth-switch">Akkauntingiz yo'qmi? <a href="{{ route('register') }}">Ro'yxatdan o'tish</a></p>
    </main>
@endsection
