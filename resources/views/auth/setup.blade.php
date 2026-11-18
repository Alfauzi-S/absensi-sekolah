@extends('layouts.guest')
@section('title', 'Pemasangan awal')
@section('content')
    <p class="eyebrow">Pemasangan awal</p>
    <h1 class="guest-title">Buat akun admin</h1>
    <p class="muted">Langkah ini hanya muncul sekali, saat aplikasi belum punya pengguna.</p>
    <form method="post" action="{{ url('/setup') }}" class="stack">
        @csrf
        <x-field label="Nama sekolah" name="school_name">
            <input class="input" name="school_name" value="{{ old('school_name') }}" required>
        </x-field>
        <x-field label="Nama admin" name="name">
            <input class="input" name="name" value="{{ old('name') }}" required>
        </x-field>
        <x-field label="Username" name="username">
            <input class="input" name="username" value="{{ old('username', 'admin') }}" required>
        </x-field>
        <div class="grid-2">
            <x-field label="Password" name="password">
                <input class="input" type="password" name="password" minlength="8" required>
            </x-field>
            <x-field label="Ulangi password" name="password_confirmation">
                <input class="input" type="password" name="password_confirmation" minlength="8" required>
            </x-field>
        </div>
        <button class="btn btn-primary btn-block btn-lg">Simpan dan masuk</button>
    </form>
@endsection
