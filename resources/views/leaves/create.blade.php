@extends('layouts.app')
@section('title', 'Catat izin')
@section('subtitle', 'Status kehadiran pada rentang tanggal ini langsung berubah')

@section('content')
<section class="card narrow">
    <form method="post" action="{{ route('leaves.store') }}" enctype="multipart/form-data" class="stack">
        @csrf
        <x-field label="Siswa" name="student_id">
            <select class="input" name="student_id" required data-searchable>
                <option value="">Pilih siswa…</option>
                @foreach ($students as $s)
                    <option value="{{ $s->id }}" @selected(old('student_id', $selected) == $s->id)>{{ $s->name }} · {{ $s->schoolClass?->name }} · {{ $s->nis }}</option>
                @endforeach
            </select>
        </x-field>
        <div class="field">
            <span class="field-label">Jenis</span>
            <div class="seg seg-lg">
                @foreach (\App\Models\Leave::KINDS as $code => $label)
                    <label class="seg-opt seg-{{ $code }}"><input type="radio" name="kind" value="{{ $code }}" @checked(old('kind', 'S') === $code)><span>{{ $label }}</span></label>
                @endforeach
            </div>
            @error('kind')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="grid-2">
            <x-field label="Dari tanggal" name="date_from">
                <input class="input" type="date" name="date_from" value="{{ old('date_from', now()->toDateString()) }}" required>
            </x-field>
            <x-field label="Sampai tanggal" name="date_to">
                <input class="input" type="date" name="date_to" value="{{ old('date_to', now()->toDateString()) }}" required>
            </x-field>
        </div>
        <x-field label="Keterangan" name="reason">
            <input class="input" name="reason" value="{{ old('reason') }}" placeholder="Contoh: demam, surat dokter" maxlength="255" required>
        </x-field>
        <x-field label="Foto/PDF surat (opsional)" name="attachment" hint="JPG, PNG, atau PDF, paling besar 3 MB.">
            <input class="input" type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf">
        </x-field>
        <div class="form-actions">
            <a href="{{ route('leaves.index') }}" class="btn btn-ghost">Batal</a>
            <button class="btn btn-primary">Simpan izin</button>
        </div>
    </form>
</section>
@endsection
