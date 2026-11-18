@extends('layouts.app')
@section('title', 'Izin & Sakit')
@section('subtitle', 'Surat izin, sakit, dan dispensasi siswa')
@section('actions')
    @if ($canCreate)<a href="{{ route('leaves.create') }}" class="btn btn-primary"><x-icon name="plus" /> Catat izin</a>@endif
@endsection

@section('content')
<form class="toolbar" method="get">
    <div class="search"><x-icon name="search" /><input class="input" name="q" value="{{ request('q') }}" placeholder="Cari nama atau NIS"></div>
    <select class="input" name="kelas" onchange="this.form.submit()">
        <option value="">Semua kelas</option>
        @foreach ($classes as $c)<option value="{{ $c->id }}" @selected(request('kelas') == $c->id)>{{ $c->name }}</option>@endforeach
    </select>
</form>

<section class="card flush">
    @if ($leaves->isEmpty())
        <x-empty icon="file" title="Belum ada surat izin">Izin yang dicatat langsung mengubah status kehadiran pada tanggalnya.</x-empty>
    @else
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Siswa</th><th>Jenis</th><th>Tanggal</th><th>Keterangan</th><th>Dicatat</th><th></th></tr></thead>
            <tbody>
            @foreach ($leaves as $l)
                <tr>
                    <td><a class="person" href="{{ route('students.show', $l->student) }}"><span class="avatar sm">{{ $l->student->initials() }}</span><span><strong>{{ $l->student->name }}</strong><small class="muted">{{ $l->student->schoolClass?->name }}</small></span></a></td>
                    <td><x-status :code="$l->kind" /></td>
                    <td class="nowrap">
                        {{ \Carbon\Carbon::parse($l->date_from)->translatedFormat('j M Y') }}
                        @if ($l->date_to !== $l->date_from) – {{ \Carbon\Carbon::parse($l->date_to)->translatedFormat('j M Y') }}@endif
                    </td>
                    <td>{{ $l->reason }} @if ($l->attachment)<a class="tag" href="{{ route('leaves.attachment', $l) }}" target="_blank">lampiran</a>@endif</td>
                    <td class="small muted">{{ $l->creator?->name ?? '–' }}<br>{{ $l->created_at?->translatedFormat('j M, H:i') }}</td>
                    <td class="actions">
                        @if (auth()->user()->canEditClass($l->student->class_id))
                            <form method="post" action="{{ route('leaves.destroy', $l) }}" data-confirm="Hapus surat izin ini? Status kehadiran akan dikembalikan.">
                                @csrf @method('delete')
                                <button class="icon-btn danger" title="Hapus" aria-label="Hapus"><x-icon name="trash" /></button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</section>
{{ $leaves->links('partials.pagination') }}
@endsection
