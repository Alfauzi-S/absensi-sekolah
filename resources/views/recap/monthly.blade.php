@extends('layouts.app')
@section('title', 'Rekap Bulanan')
@section('subtitle', $class ? 'Kelas '.$class->name.' · '.$month->translatedFormat('F Y') : '')
@section('actions')
    @if (auth()->user()->seesAllClasses())<a class="btn btn-ghost" href="{{ route('recap.summary') }}"><x-icon name="chart" /> Ringkasan sekolah</a>@endif
    @if ($class)<a class="btn btn-primary" href="{{ route('recap.download', ['kelas' => $class->id, 'bulan' => $month->format('Y-m')]) }}"><x-icon name="download" /> Unduh Excel</a>@endif
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

@if (! $data)
    <section class="card"><x-empty icon="chart" title="Belum ada kelas yang bisa direkap" /></section>
@else
    <p class="muted small">Hari efektif {{ $data['effective'] }} · Wali kelas {{ $class->homeroomTeacher?->name ?? '–' }} · Kolom abu-abu = libur</p>
    <section class="card flush">
        <div class="table-wrap">
            <table class="table recap">
                <thead>
                    <tr>
                        <th class="sticky">Nama</th>
                        @foreach ($data['days'] as $d)
                            <th class="day {{ isset($data['off'][$d->toDateString()]) ? 'off' : '' }}" title="{{ $d->translatedFormat('l, j F') }}{{ isset($data['off'][$d->toDateString()]) ? ' · '.$data['off'][$d->toDateString()] : '' }}">{{ $d->format('j') }}<small>{{ mb_substr($d->translatedFormat('D'), 0, 2) }}</small></th>
                        @endforeach
                        @foreach (\App\Services\RecapService::CODES as $code)<th class="num tot">{{ $code }}</th>@endforeach
                        <th class="num">%</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($data['rows'] as $r)
                    <tr>
                        <td class="sticky"><a href="{{ route('students.show', ['student' => $r['student'], 'bulan' => $month->format('Y-m')]) }}">{{ $r['student']->name }}</a></td>
                        @foreach ($data['days'] as $d)
                            @php $k = $d->toDateString(); $code = $r['cells'][$k]; @endphp
                            <td class="day {{ isset($data['off'][$k]) ? 'off' : '' }}">@if ($code && ! isset($data['off'][$k]))<span class="dot dot-{{ $code }}">{{ $code }}</span>@endif</td>
                        @endforeach
                        @foreach (\App\Services\RecapService::CODES as $code)<td class="num tot {{ $r['count'][$code] && $code === 'A' ? 'text-A' : '' }}">{{ $r['count'][$code] ?: '' }}</td>@endforeach
                        <td class="num"><strong>{{ $r['percent'] === null ? '–' : $r['percent'] }}</strong></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
@endsection
