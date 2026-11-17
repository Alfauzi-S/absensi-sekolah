@extends('layouts.guest')
@section('title', 'Masuk')
@section('content')
    <p class="eyebrow">{{ \App\Models\Setting::get('school_name') }}</p>
    <h1 class="guest-title">Masuk</h1>
    <p class="muted">Gunakan akun yang diberikan admin sekolah.</p>
    <form method="post" action="{{ url('/login') }}" class="stack">
        @csrf
        <x-field label="Username" name="username">
            <input class="input" name="username" value="{{ old('username') }}" autocomplete="username" autofocus required>
        </x-field>
        <x-field label="Password" name="password">
            <input class="input" type="password" name="password" autocomplete="current-password" required>
        </x-field>
        <label class="check"><input type="checkbox" name="remember" value="1"> Ingat saya di perangkat ini</label>
        <button class="btn btn-primary btn-block btn-lg">Masuk</button>
    </form>
@endsection
