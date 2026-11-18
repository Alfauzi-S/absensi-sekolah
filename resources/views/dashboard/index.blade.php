@extends('layouts.app')
@section('title', 'Beranda')
@section('subtitle', $today->translatedFormat('l, j F Y'))
@section('actions')
    <a href="{{ route('attendance') }}" class="btn btn-ghost"><x-icon name="clipboard" /> Absensi harian</a>
@endsection

@section('content')
@if ($offReason)
    <div class="banner"><x-icon name="calendar" /> Hari ini libur: <strong>{{ $offReason }}</strong>. Scan tidak dicatat.</div>
@endif

@php
    $present = $totals['H'] + $totals['T'];
    $pct = $students > 0 ? round($present / $students * 100) : 0;
@endphp

<section class="hero-stats">
    <div class="hero-card">
        <div class="ring" style="--p: {{ $pct }}">
            <div class="ring-inner"><strong>{{ $pct }}%</strong><span>hadir</span></div>
        </div>
        <div>
            <p class="hero-label">Kehadiran hari ini</p>
            <p class="hero-number">{{ $present }} <span>dari {{ $students }} siswa</span></p>
            <p class="muted small">{{ $notYet }} siswa belum tercatat</p>
        </div>
    </div>
    <div class="stat-grid">
        <div class="stat stat-H"><span>Hadir tepat waktu</span><strong>{{ $totals['H'] }}</strong></div>
        <div class="stat stat-T"><span>Terlambat</span><strong>{{ $totals['T'] }}</strong></div>
        <div class="stat stat-S"><span>Izin / Sakit / Disp.</span><strong>{{ $totals['S'] + $totals['I'] + $totals['D'] }}</strong></div>
        <div class="stat stat-A"><span>Alpa</span><strong>{{ $totals['A'] }}</strong></div>
    </div>
</section>

<div class="cols">
    <section class="card col-main">
        <div class="card-head">
            <h2>Per kelas</h2>
            <span class="muted small">Klik kelas untuk melihat daftar hadir</span>
        </div>
        @if ($classes->isEmpty())
            <x-empty icon="school" title="Belum ada kelas">
                @if (auth()->user()->canManageData()) Tambahkan kelas di menu <a href="{{ route('classes.index') }}">Kelas</a>. @else Anda belum tercatat sebagai wali kelas. @endif
            </x-empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Kelas</th><th class="num">Hadir</th><th class="num">Terlambat</th><th class="num">Izin/Sakit</th><th class="num">Alpa</th><th class="num">Belum</th><th style="width:28%">Kehadiran</th></tr></thead>
                    <tbody>
                    @foreach ($classes as $c)
                        @php
                            $n = $byClass[$c->id] ?? [];
                            $h = ($n['H'] ?? 0); $t = ($n['T'] ?? 0); $izin = ($n['S'] ?? 0) + ($n['I'] ?? 0) + ($n['D'] ?? 0); $a = $n['A'] ?? 0;
                            $belum = max(0, $c->total - $h - $t - $izin - $a);
                            $p = $c->total > 0 ? round(($h + $t) / $c->total * 100) : 0;
                        @endphp
                        <tr class="row-link" data-href="{{ route('attendance', ['kelas' => $c->id]) }}">
                            <td><strong>{{ $c->name }}</strong><div class="muted small">{{ $c->homeroomTeacher?->name ?? 'Belum ada wali kelas' }}</div></td>
                            <td class="num">{{ $h }}</td>
                            <td class="num">{{ $t ?: '–' }}</td>
                            <td class="num">{{ $izin ?: '–' }}</td>
                            <td class="num {{ $a ? 'text-A' : '' }}">{{ $a ?: '–' }}</td>
                            <td class="num muted">{{ $belum ?: '–' }}</td>
                            <td><div class="bar"><span style="width: {{ $p }}%"></span></div><span class="small muted">{{ $p }}%</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <aside class="col-side stack">
        <section class="card">
            <div class="card-head"><h2>Scan terbaru</h2><span class="live-dot" title="Diperbarui otomatis"></span></div>
            <div data-feed="{{ route('dashboard.feed') }}">@include('dashboard._recent')</div>
        </section>

        <section class="card">
            <div class="card-head"><h2>10 hari sekolah terakhir</h2></div>
            <div class="spark">
                @foreach ($trend as $date => $p)
                    <div class="spark-col" title="{{ \Carbon\Carbon::parse($date)->translatedFormat('D, j M') }}: {{ $p === null ? 'tidak ada data' : $p.'%' }}">
                        <div class="spark-bar" style="height: {{ max(4, (int) $p) }}%"></div>
                        <span>{{ \Carbon\Carbon::parse($date)->format('j') }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        @if ($frequentAbsent->isNotEmpty())
            <section class="card">
                <div class="card-head"><h2>Perlu perhatian</h2><span class="muted small">Alpa 30 hari</span></div>
                <ul class="list">
                    @foreach ($frequentAbsent as $s)
                        <li><a href="{{ route('students.show', $s) }}" class="list-row">
                            <span class="avatar sm">{{ $s->initials() }}</span>
                            <span class="grow"><strong>{{ $s->name }}</strong><small class="muted">{{ $s->schoolClass?->name }}</small></span>
                            <span class="pill pill-A">{{ $s->alpa }}× alpa</span>
                        </a></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </aside>
</div>
@endsection
