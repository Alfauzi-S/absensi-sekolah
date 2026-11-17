@extends('layouts.app')
@section('title', 'Rekap per Jam Pelajaran')
@section('subtitle', $class ? 'Kelas '.$class->name.' · '.$month->translatedFormat('F Y') : '')
@section('actions')
    @if ($class)<a class="btn btn-primary" href="{{ route('lessons.download', ['kelas' => $class->id, 'bulan' => $month->format('Y-m')]) }}"><x-icon name="download" /> Unduh Excel</a>@endif
@endsection

@section('content')
<form class="toolbar" method="get">
    <select class="input" name="kelas" onchange="this.form.submit()">
        @foreach ($classes as $c)<option value="{{ $c->id }}" @selected($class?->id === $c->id)>{{ $c->name }}</option>@endforeach
    </select>
    <div class="date-nav">
        <a class="icon-btn" href="{{ request()->fullUrlWithQuery(['bulan' => $month->subMonth()->format('Y-m')]) }}" aria-label="Bulan sebelumnya"><x-icon name="chevron-left" /></a>
        <input class="input" type="month" name="bulan" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()">
        <a class="icon-btn" href="{{ request()->fullUrlWithQuery(['bulan' => $month->addMonth()->format('Y-m')]) }}" aria-label="Bulan berikutnya"><x-icon name="chevron-right" /></a>
    </div>
</form>

@if (! $data || $data['lessons']->isEmpty())
    <section class="card"><x-empty icon="clock" title="Belum ada sesi pelajaran bulan ini" /></section>
@else
<section class="card flush">
    <div class="table-wrap">
        <table class="table recap">
            <thead><tr>
                <th class="sticky">Nama</th>
                @foreach ($data['lessons'] as $l)
                    <th class="day" title="{{ $l->subject?->name }} · {{ $l->periodLabel() }} · {{ $l->teacher?->name }}"><a href="{{ route('lessons.show', $l) }}">{{ \Carbon\Carbon::parse($l->date)->format('j') }}</a><small>J{{ $l->period_start }}</small></th>
                @endforeach
                @foreach (\App\Services\RecapService::CODES as $code)<th class="num tot">{{ $code }}</th>@endforeach
                <th class="num">%</th>
            </tr></thead>
            <tbody>
            @foreach ($data['rows'] as $r)
                <tr>
                    <td class="sticky">{{ $r['student']->name }}</td>
                    @foreach ($data['lessons'] as $l)
                        <td class="day">@if ($code = $r['cells'][$l->id] ?? null)<span class="dot dot-{{ $code }}">{{ $code }}</span>@endif</td>
                    @endforeach
                    @foreach (\App\Services\RecapService::CODES as $code)<td class="num tot">{{ $r['count'][$code] ?: '' }}</td>@endforeach
                    <td class="num"><strong>{{ $r['percent'] ?? '–' }}</strong></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
<p class="muted small">Arahkan kursor ke kolom untuk melihat mapel, jam, dan guru. Klik tanggal untuk membuka sesinya.</p>
@endif
@endsection
