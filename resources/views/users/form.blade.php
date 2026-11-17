@extends('layouts.app')
@section('title', $user->exists ? 'Ubah akun' : 'Tambah akun')

@section('content')
<section class="card narrow">
    <form method="post" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="stack">
        @csrf
        @if ($user->exists) @method('put') @endif
        <x-field label="Nama lengkap" name="name"><input class="input" name="name" value="{{ old('name', $user->name) }}" required maxlength="120"></x-field>
        <div class="grid-2">
            <x-field label="Username" name="username"><input class="input" name="username" value="{{ old('username', $user->username) }}" required maxlength="40" autocomplete="off"></x-field>
            <x-field label="NIP" name="nip"><input class="input" name="nip" value="{{ old('nip', $user->nip) }}" maxlength="30"></x-field>
        </div>
        <div class="field">
            <span class="field-label">Peran</span>
            <div class="seg seg-lg seg-wrap">
                @foreach (\App\Models\User::ROLES as $k => $v)
                    <label class="seg-opt"><input type="radio" name="role" value="{{ $k }}" @checked(old('role', $user->role) === $k) @disabled($user->is(auth()->user()))><span>{{ $v }}</span></label>
                @endforeach
            </div>
            @if ($user->is(auth()->user()))<input type="hidden" name="role" value="{{ $user->role }}">@endif
        </div>
        <x-field :label="$user->exists ? 'Password baru' : 'Password'" name="password" :hint="$user->exists ? 'Kosongkan bila tidak diganti. Minimal 8 karakter.' : 'Minimal 8 karakter.'">
            <input class="input" type="password" name="password" minlength="8" autocomplete="new-password" @required(! $user->exists)>
        </x-field>
        <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active)) @disabled($user->is(auth()->user()))> Akun aktif (boleh masuk)</label>
        @if ($user->is(auth()->user()))<input type="hidden" name="is_active" value="1">@endif
        <div class="form-actions">
            @if ($user->exists && ! $user->is(auth()->user()))<button form="del" class="btn btn-danger-ghost mr-auto"><x-icon name="trash" /> Hapus</button>@endif
            <a href="{{ route('users.index') }}" class="btn btn-ghost">Batal</a>
            <button class="btn btn-primary">Simpan</button>
        </div>
    </form>
    @if ($user->exists)
        <form id="del" method="post" action="{{ route('users.destroy', $user) }}" data-confirm="Hapus akun {{ $user->username }}?">@csrf @method('delete')</form>
    @endif
</section>
@endsection
