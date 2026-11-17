@extends('layouts.app')
@section('title', 'Akun saya')
@section('subtitle', auth()->user()->name.' · '.auth()->user()->roleLabel())

@section('content')
<section class="card narrow">
    <div class="card-head"><h2>Ganti password</h2></div>
    <form method="post" class="stack">
        @csrf @method('put')
        <x-field label="Password sekarang" name="current_password"><input class="input" type="password" name="current_password" required autocomplete="current-password"></x-field>
        <div class="grid-2">
            <x-field label="Password baru" name="password"><input class="input" type="password" name="password" minlength="8" required autocomplete="new-password"></x-field>
            <x-field label="Ulangi password baru" name="password_confirmation"><input class="input" type="password" name="password_confirmation" minlength="8" required autocomplete="new-password"></x-field>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Simpan password</button></div>
    </form>
</section>
@endsection
