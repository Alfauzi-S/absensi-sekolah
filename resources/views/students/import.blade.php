@extends('layouts.app')
@section('title', 'Impor Siswa')
@section('subtitle', 'Tambah atau perbarui banyak siswa sekaligus dari Excel/CSV')

@section('content')
<div class="cols">
<section class="card col-main">
    <form method="post" enctype="multipart/form-data" class="stack">
        @csrf
        <label class="dropzone">
            <x-icon name="upload" :size="28" />
            <strong>Pilih file Excel (.xlsx) atau CSV</strong>
            <span class="muted small" data-file-name>Maksimal 5.000 baris</span>
            <input type="file" name="file" accept=".xlsx,.csv,.txt" required>
        </label>
        @error('file')<span class="field-error">{{ $message }}</span>@enderror
        <div class="form-actions"><button class="btn btn-primary"><x-icon name="upload" /> Impor sekarang</button></div>
    </form>
    @if (session('importErrors'))
        <div class="banner banner-warn"><x-icon name="alert" /> <div><strong>{{ count(session('importErrors')) }} baris dilewati</strong>
            <ul class="small">@foreach (array_slice(session('importErrors'), 0, 20) as $e)<li>{{ $e }}</li>@endforeach</ul></div></div>
    @endif
</section>
<aside class="col-side card">
    <div class="card-head"><h2>Format kolom</h2></div>
    <ol class="steps">
        <li><strong>NIS</strong> dan <strong>Nama</strong> wajib.</li>
        <li><strong>Kelas</strong>, <strong>L/P</strong>, <strong>NISN</strong>, <strong>No HP Ortu</strong> opsional.</li>
        <li>NIS yang sudah ada akan diperbarui, bukan diduplikasi.</li>
        <li>Kelas yang belum ada dibuat otomatis.</li>
    </ol>
    <a class="btn btn-ghost btn-block" href="{{ route('students.template') }}"><x-icon name="download" /> Unduh template</a>
</aside>
</div>
@endsection
