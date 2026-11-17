@extends('layouts.app')
@section('title', $student->exists ? 'Ubah siswa' : 'Tambah siswa')

@section('content')
<section class="card narrow">
    <form method="post" action="{{ $student->exists ? route('students.update', $student) : route('students.store') }}" class="stack">
        @csrf
        @if ($student->exists) @method('put') @endif
        <x-field label="Nama lengkap" name="name">
            <input class="input" name="name" value="{{ old('name', $student->name) }}" required maxlength="120">
        </x-field>
        <div class="grid-2">
            <x-field label="NIS" name="nis"><input class="input" name="nis" value="{{ old('nis', $student->nis) }}" required maxlength="30"></x-field>
            <x-field label="NISN" name="nisn"><input class="input" name="nisn" value="{{ old('nisn', $student->nisn) }}" maxlength="30"></x-field>
        </div>
        <div class="grid-2">
            <x-field label="Kelas" name="class_id">
                <select class="input" name="class_id">
                    <option value="">Tanpa kelas</option>
                    @foreach ($classes as $c)<option value="{{ $c->id }}" @selected(old('class_id', $student->class_id) == $c->id)>{{ $c->name }}</option>@endforeach
                </select>
            </x-field>
            <div class="field">
                <span class="field-label">Jenis kelamin</span>
                <div class="seg seg-lg">
                    <label class="seg-opt"><input type="radio" name="gender" value="L" @checked(old('gender', $student->gender) === 'L')><span>Laki-laki</span></label>
                    <label class="seg-opt"><input type="radio" name="gender" value="P" @checked(old('gender', $student->gender) === 'P')><span>Perempuan</span></label>
                </div>
            </div>
        </div>
        <div class="grid-2">
            <x-field label="No. HP orang tua" name="parent_phone"><input class="input" name="parent_phone" value="{{ old('parent_phone', $student->parent_phone) }}" inputmode="tel" maxlength="20"></x-field>
            <x-field label="Status" name="status">
                <select class="input" name="status">
                    @foreach (\App\Models\Student::STATUSES as $k => $v)<option value="{{ $k }}" @selected(old('status', $student->status) === $k)>{{ $v }}</option>@endforeach
                </select>
            </x-field>
        </div>
        <div class="form-actions">
            @if ($student->exists)
                <button form="del" class="btn btn-danger-ghost mr-auto"><x-icon name="trash" /> Hapus</button>
            @endif
            <a href="{{ $student->exists ? route('students.show', $student) : route('students.index') }}" class="btn btn-ghost">Batal</a>
            <button class="btn btn-primary">Simpan</button>
        </div>
    </form>
    @if ($student->exists)
        <form id="del" method="post" action="{{ route('students.destroy', $student) }}" data-confirm="Hapus {{ $student->name }} beserta seluruh riwayat kehadirannya? Untuk siswa lulus/pindah, cukup ubah statusnya.">@csrf @method('delete')</form>
        <div class="divider"></div>
        <div class="row-between">
            <div><strong>Kartu hilang?</strong><p class="muted small">Buat kode QR baru. Kartu lama langsung tidak berlaku.</p></div>
            <form method="post" action="{{ route('students.reissue', $student) }}" data-confirm="Buat kartu baru untuk {{ $student->name }}? Kartu lama tidak bisa dipakai lagi.">@csrf<button class="btn btn-ghost"><x-icon name="refresh" /> Kartu baru</button></form>
        </div>
    @endif
</section>
@endsection
