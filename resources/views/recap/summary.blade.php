@extends('layouts.app')
@section('title', 'Ringkasan Sekolah')
@section('subtitle', $from->translatedFormat('j M Y').' – '.$to->translatedFormat('j M Y').' · '.$effective.' hari efektif')
@section('actions')<a class="btn btn-ghost" href="{{ route('recap') }}"><x-icon name="chart" /> Rekap per kelas</a>@endsection

@section('content')
<form class="toolbar" method="get">
    <label class="inline small">Dari <input class="input" type="date" name="dari" value="{{ $from->toDateString() }}"></label>
    <label class="inline small">Sampai <input class="input" type="date" name="sampai" value="{{ $to->toDateString() }}"></label>
    <button class="btn">Terapkan</button>
</form>
<section class="card flush">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Kelas</th><th class="num">Siswa</th>@foreach (\App\Models\Attendance::STATUSES as $c => $l)<th class="num">{{ $l }}</th>@endforeach<th style="width:22%">Kehadiran</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr class="row-link" data-href="{{ route('recap', ['kelas' => $r['class']->id, 'bulan' => $from->format('Y-m')]) }}">
                    <td><strong>{{ $r['class']->name }}</strong><div class="muted small">{{ $r['class']->homeroomTeacher?->name }}</div></td>
                    <td class="num">{{ $r['class']->total }}</td>
                    @foreach (\App\Models\Attendance::STATUSES as $c => $l)<td class="num {{ $c === 'A' && $r['count'][$c] ? 'text-A' : '' }}">{{ $r['count'][$c] ?: '–' }}</td>@endforeach
                    <td><div class="bar"><span style="width: {{ $r['percent'] ?? 0 }}%"></span></div><span class="small muted">{{ $r['percent'] === null ? '–' : $r['percent'].'%' }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection
