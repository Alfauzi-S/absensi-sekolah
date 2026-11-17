@extends('layouts.app')
@section('title', $class->exists ? 'Kelas '.$class->name : 'Tambah kelas')
@section('actions')
    @if ($class->exists)
        <a class="btn btn-ghost" href="{{ route('attendance', ['kelas' => $class->id]) }}"><x-icon name="clipboard" /> Absensi</a>
        <a class="btn btn-ghost" href="{{ route('students.cards', ['kelas' => $class->id]) }}"><x-icon name="qr" /> Cetak kartu</a>
    @endif
@endsection

@section('content')
<div class="cols">
<section class="card {{ $class->exists ? 'col-side' : 'narrow' }}">
    <div class="card-head"><h2>Data kelas</h2></div>
    <form method="post" action="{{ $class->exists ? route('classes.update', $class) : route('classes.store') }}" class="stack">
        @csrf
        @if ($class->exists) @method('put') @endif
        <div class="grid-2">
            <x-field label="Nama kelas" name="name"><input class="input" name="name" value="{{ old('name', $class->name) }}" placeholder="X-1" required maxlength="30"></x-field>
            <x-field label="Tingkat" name="grade"><input class="input" type="number" name="grade" min="1" max="12" value="{{ old('grade', $class->grade) }}" required></x-field>
        </div>
        <x-field label="Wali kelas" name="homeroom_teacher_id" hint="Wali kelas bisa melihat dan mengoreksi absensi kelas ini.">
            <select class="input" name="homeroom_teacher_id">
                <option value="">Belum ada</option>
                @foreach ($teachers as $t)<option value="{{ $t->id }}" @selected(old('homeroom_teacher_id', $class->homeroom_teacher_id) == $t->id)>{{ $t->name }}</option>@endforeach
            </select>
        </x-field>
        <div class="form-actions">
            @if ($class->exists)<button form="del" class="btn btn-danger-ghost mr-auto"><x-icon name="trash" /> Hapus</button>@endif
            <button class="btn btn-primary">Simpan</button>
        </div>
    </form>
    @if ($class->exists)
        <form id="del" method="post" action="{{ route('classes.destroy', $class) }}" data-confirm="Hapus kelas {{ $class->name }}?">@csrf @method('delete')</form>
    @endif
</section>

@if ($class->exists)
<section class="card flush col-main">
    <form method="post" action="{{ route('classes.move', $class) }}" data-confirm="Pindahkan siswa terpilih?">
        @csrf
        <div class="card-head pad">
            <h2>{{ $students->count() }} siswa</h2>
            <div class="inline">
                <select class="input input-sm" name="target" required>
                    <option value="">Pindahkan terpilih ke…</option>
                    @foreach ($others as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
                    <option value="lulus">Tandai lulus</option>
                </select>
                <button class="btn btn-sm">Pindahkan</button>
            </div>
        </div>
        @if ($students->isEmpty())
            <x-empty icon="users" title="Belum ada siswa di kelas ini" />
        @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th style="width:40px"><input type="checkbox" data-check-all aria-label="Pilih semua"></th><th>Nama</th><th>NIS</th><th>L/P</th></tr></thead>
                <tbody>
                @foreach ($students as $s)
                    <tr>
                        <td><input type="checkbox" name="students[]" value="{{ $s->id }}" aria-label="Pilih {{ $s->name }}"></td>
                        <td><a href="{{ route('students.show', $s) }}">{{ $s->name }}</a></td>
                        <td class="mono">{{ $s->nis }}</td>
                        <td>{{ $s->gender }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </form>
</section>
@endif
</div>
@endsection
