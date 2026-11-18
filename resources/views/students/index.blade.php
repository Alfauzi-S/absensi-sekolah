@extends('layouts.app')
@section('title', 'Siswa')
@section('subtitle', $students->total().' siswa')
@section('actions')
    <a href="{{ route('students.import') }}" class="btn btn-ghost"><x-icon name="upload" /> Impor</a>
    <a href="{{ route('students.create') }}" class="btn btn-primary"><x-icon name="plus" /> Tambah siswa</a>
@endsection

@section('content')
<form class="toolbar" method="get">
    <div class="search"><x-icon name="search" /><input class="input" name="q" value="{{ request('q') }}" placeholder="Cari nama atau NIS"></div>
    <select class="input" name="kelas" onchange="this.form.submit()">
        <option value="">Semua kelas</option>
        @foreach ($classes as $c)<option value="{{ $c->id }}" @selected(request('kelas') == $c->id)>{{ $c->name }}</option>@endforeach
    </select>
    <select class="input" name="status" onchange="this.form.submit()">
        @foreach (['aktif' => 'Aktif', 'lulus' => 'Lulus', 'pindah' => 'Pindah', 'keluar' => 'Keluar', 'semua' => 'Semua status'] as $k => $v)
            <option value="{{ $k }}" @selected(request('status', 'aktif') === $k)>{{ $v }}</option>
        @endforeach
    </select>
</form>

<section class="card flush">
    @if ($students->isEmpty())
        <x-empty icon="users" title="Tidak ada siswa">Tambahkan satu per satu atau <a href="{{ route('students.import') }}">impor dari Excel</a>.</x-empty>
    @else
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nama</th><th>NIS</th><th>Kelas</th><th>L/P</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach ($students as $s)
                <tr class="row-link" data-href="{{ route('students.show', $s) }}">
                    <td><span class="person"><span class="avatar sm">{{ $s->initials() }}</span><strong>{{ $s->name }}</strong></span></td>
                    <td class="mono">{{ $s->nis }}</td>
                    <td>{{ $s->schoolClass?->name ?? '–' }}</td>
                    <td>{{ $s->gender }}</td>
                    <td>@if ($s->status === 'aktif')<span class="pill pill-H">Aktif</span>@else<span class="pill">{{ \App\Models\Student::STATUSES[$s->status] }}</span>@endif</td>
                    <td class="actions"><a class="icon-btn" href="{{ route('students.edit', $s) }}" title="Ubah" aria-label="Ubah"><x-icon name="edit" /></a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</section>
{{ $students->links('partials.pagination') }}
@endsection
