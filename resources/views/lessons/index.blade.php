@extends('layouts.app')
@section('title', 'Absensi per Jam')
@section('subtitle', $date->translatedFormat('l, j F Y'))
@section('actions')
    <a href="{{ route('lessons.recap') }}" class="btn btn-ghost"><x-icon name="chart" /> Rekap per jam</a>
    @if ($canCreate)<a href="{{ route('lessons.create') }}" class="btn btn-primary"><x-icon name="plus" /> Mulai sesi</a>@endif
@endsection

@section('content')
<form class="toolbar" method="get">
    <div class="date-nav">
        <a class="icon-btn" href="?tanggal={{ $date->subDay()->toDateString() }}" aria-label="Hari sebelumnya"><x-icon name="chevron-left" /></a>
        <input class="input" type="date" name="tanggal" value="{{ $date->toDateString() }}" max="{{ now()->toDateString() }}" onchange="this.form.submit()">
        <a class="icon-btn" href="?tanggal={{ $date->addDay()->toDateString() }}" aria-label="Hari berikutnya"><x-icon name="chevron-right" /></a>
    </div>
</form>

@if ($lessons->isEmpty())
    <section class="card"><x-empty icon="clock" title="Belum ada sesi pelajaran">Klik <strong>Mulai sesi</strong> saat masuk kelas. Daftar siswa langsung terisi Hadir; ubah yang tidak hadir.</x-empty></section>
@else
<div class="tiles">
    @foreach ($lessons as $l)
        <a class="tile" href="{{ route('lessons.show', $l) }}">
            <div class="tile-top"><span class="tile-grade">{{ $l->period_start }}</span><strong>{{ $l->schoolClass->name }}</strong></div>
            <div class="tile-meta"><x-icon name="file" :size="15" /> {{ $l->subject?->name ?? 'Tanpa mapel' }} · {{ $l->periodLabel() }}</div>
            <div class="tile-meta muted"><x-icon name="user-cog" :size="15" /> {{ $l->teacher?->name ?? '–' }}</div>
            <div class="tile-meta"><span class="pill pill-H">{{ $l->hadir }} hadir</span>@if ($l->tidak)<span class="pill pill-A">{{ $l->tidak }} tidak</span>@endif</div>
        </a>
    @endforeach
</div>
@endif
@endsection
