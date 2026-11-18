@extends('layouts.app')
@section('title', $student->name)
@section('subtitle', 'NIS '.$student->nis.' · '.($student->schoolClass?->name ?? 'Tanpa kelas'))
@section('actions')
    @if (auth()->user()->canEditClass($student->class_id) && auth()->user()->role !== 'kepsek')
        <a href="{{ route('leaves.create', ['siswa' => $student->id]) }}" class="btn btn-ghost"><x-icon name="file" /> Catat izin</a>
    @endif
    @if (auth()->user()->canManageData())
        <a href="{{ route('students.cards', ['siswa' => $student->id]) }}" class="btn btn-ghost"><x-icon name="qr" /> Kartu</a>
        <a href="{{ route('students.edit', $student) }}" class="btn btn-primary"><x-icon name="edit" /> Ubah</a>
    @endif
@endsection

@section('content')
<form class="toolbar" method="get">
    <div class="date-nav">
        <a class="icon-btn" href="?bulan={{ $month->subMonth()->format('Y-m') }}" aria-label="Bulan sebelumnya"><x-icon name="chevron-left" /></a>
        <input class="input" type="month" name="bulan" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()">
        <a class="icon-btn" href="?bulan={{ $month->addMonth()->format('Y-m') }}" aria-label="Bulan berikutnya"><x-icon name="chevron-right" /></a>
    </div>
</form>

<section class="stat-grid six">
    @foreach (\App\Models\Attendance::STATUSES as $code => $label)
        <div class="stat stat-{{ $code }}"><span>{{ $label }}</span><strong>{{ $count[$code] }}</strong></div>
    @endforeach
</section>
<p class="muted small">Hari efektif {{ $effective }} · Kehadiran {{ $percent === null ? '–' : $percent.'%' }} · Total terlambat {{ $late_minutes }} menit</p>

<div class="cols">
    <section class="card flush col-main">
        <div class="card-head pad"><h2>Riwayat {{ $month->translatedFormat('F Y') }}</h2></div>
        @if ($rows->isEmpty())
            <x-empty icon="calendar" title="Belum ada catatan bulan ini" />
        @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Tanggal</th><th>Masuk</th><th>Pulang</th><th>Status</th><th>Keterangan</th></tr></thead>
                <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td class="nowrap">{{ \Carbon\Carbon::parse($r->date)->translatedFormat('D, j M') }}</td>
                        <td>{{ $r->check_in_at?->format('H:i') ?? '–' }}@if ($r->late_minutes)<small class="text-T"> +{{ $r->late_minutes }}m</small>@endif</td>
                        <td>{{ $r->check_out_at?->format('H:i') ?? '–' }}</td>
                        <td><x-status :code="$r->status" /></td>
                        <td class="small">{{ $r->note }} @if ($r->corrected)<span class="tag">manual</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>
    <aside class="col-side stack">
        <section class="card">
            <div class="card-head"><h2>Data siswa</h2></div>
            <dl class="dl">
                <dt>NISN</dt><dd>{{ $student->nisn ?: '–' }}</dd>
                <dt>Jenis kelamin</dt><dd>{{ $student->gender === 'P' ? 'Perempuan' : 'Laki-laki' }}</dd>
                <dt>Kelas</dt><dd>{{ $student->schoolClass?->name ?? '–' }}</dd>
                <dt>HP orang tua</dt><dd>{{ $student->parent_phone ?: '–' }}</dd>
                <dt>Status</dt><dd>{{ \App\Models\Student::STATUSES[$student->status] }}</dd>
                @if (auth()->user()->canManageData())<dt>Kode kartu</dt><dd class="mono">{{ $student->prettyCode() }}</dd>@endif
            </dl>
        </section>
        @if ($leaves->isNotEmpty())
        <section class="card">
            <div class="card-head"><h2>Surat izin</h2></div>
            <ul class="list">
                @foreach ($leaves as $l)
                    <li class="list-row"><x-status :code="$l->kind" /><span class="grow small">{{ \Carbon\Carbon::parse($l->date_from)->translatedFormat('j M') }}@if ($l->date_to !== $l->date_from)–{{ \Carbon\Carbon::parse($l->date_to)->translatedFormat('j M') }}@endif · {{ $l->reason }}</span></li>
                @endforeach
            </ul>
        </section>
        @endif
    </aside>
</div>
@endsection
