@extends('layouts.app')
@section('title', 'Absensi Harian')
@section('subtitle', $class ? 'Kelas '.$class->name.' · '.$date->translatedFormat('l, j F Y') : 'Pilih kelas')

@section('content')
<form class="toolbar" method="get">
    <select class="input" name="kelas" onchange="this.form.submit()" aria-label="Kelas">
        @foreach ($classes as $c)
            <option value="{{ $c->id }}" @selected($class?->id === $c->id)>{{ $c->name }}</option>
        @endforeach
    </select>
    <div class="date-nav">
        <a class="icon-btn" href="{{ request()->fullUrlWithQuery(['tanggal' => $date->subDay()->toDateString()]) }}" aria-label="Hari sebelumnya"><x-icon name="chevron-left" /></a>
        <input class="input" type="date" name="tanggal" value="{{ $date->toDateString() }}" max="{{ now()->toDateString() }}" onchange="this.form.submit()">
        <a class="icon-btn" href="{{ request()->fullUrlWithQuery(['tanggal' => $date->addDay()->toDateString()]) }}" aria-label="Hari berikutnya"><x-icon name="chevron-right" /></a>
    </div>
    @if (! $date->isToday())
        <a class="btn btn-ghost" href="{{ request()->fullUrlWithQuery(['tanggal' => now()->toDateString()]) }}">Hari ini</a>
    @endif
</form>

@if ($offReason)
    <div class="banner"><x-icon name="calendar" /> {{ $date->translatedFormat('l, j F') }} libur: <strong>{{ $offReason }}</strong></div>
@endif

@if (! $class)
    <section class="card"><x-empty icon="school" title="Belum ada kelas yang bisa Anda lihat">Admin perlu menjadikan Anda wali kelas terlebih dulu.</x-empty></section>
@else
    <div class="chips">
        @foreach (\App\Models\Attendance::STATUSES as $code => $label)
            <span class="chip chip-{{ $code }}"><b>{{ $count[$code] }}</b> {{ $label }}</span>
        @endforeach
        <span class="chip"><b>{{ $rows->count() - array_sum($count) }}</b> Belum tercatat</span>
    </div>

    <section class="card flush">
        @if ($rows->isEmpty())
            <x-empty icon="users" title="Kelas ini belum punya siswa" />
        @else
        <div class="table-wrap">
            <table class="table table-att">
                <thead><tr>
                    <th class="num">No</th><th>Siswa</th><th>Masuk</th><th>Pulang</th>
                    <th>Status</th><th>Keterangan</th>
                </tr></thead>
                <tbody>
                @foreach ($rows as $i => $r)
                    @php $a = $r['att']; $s = $r['student']; @endphp
                    <tr>
                        <td class="num muted">{{ $i + 1 }}</td>
                        <td>
                            <a href="{{ route('students.show', $s) }}" class="person">
                                <span class="avatar sm">{{ $s->initials() }}</span>
                                <span><strong>{{ $s->name }}</strong><small class="muted">{{ $s->nis }}</small></span>
                            </a>
                        </td>
                        <td class="nowrap">
                            @if ($a?->check_in_at){{ $a->check_in_at->format('H:i') }}@if ($a->late_minutes)<small class="text-T"> +{{ $a->late_minutes }}m</small>@endif
                            @else<span class="muted">–</span>@endif
                        </td>
                        <td class="nowrap">{{ $a?->check_out_at?->format('H:i') ?? '–' }}</td>
                        <td>
                            @if ($canEdit)
                                <form method="post" action="{{ route('attendance.correct') }}" class="seg" data-autosave>
                                    @csrf
                                    <input type="hidden" name="student_id" value="{{ $s->id }}">
                                    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                                    @foreach (\App\Models\Attendance::STATUSES as $code => $label)
                                        <label class="seg-opt seg-{{ $code }}" title="{{ $label }}">
                                            <input type="radio" name="status" value="{{ $code }}" @checked($a?->status === $code)>
                                            <span>{{ $code }}</span>
                                        </label>
                                    @endforeach
                                    <input type="hidden" name="note" value="{{ $a?->note }}">
                                    <noscript><button class="btn btn-sm">Simpan</button></noscript>
                                </form>
                            @elseif ($a)
                                <x-status :code="$a->status" />
                            @else
                                <span class="muted small">Belum tercatat</span>
                            @endif
                        </td>
                        <td class="small">
                            @if ($canEdit)
                                <input class="input input-sm note-input" form="" data-note-for="{{ $s->id }}" value="{{ $a?->note }}" placeholder="{{ $a?->corrected ? 'Dikoreksi' : 'Tambah catatan' }}" maxlength="200">
                            @else
                                {{ $a?->note }}
                            @endif
                            @if ($a?->corrected)<span class="tag" title="Diubah manual">manual</span>@endif
                            @if ($a?->source === 'izin')<span class="tag">surat</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>
    @if ($canEdit)
        <p class="muted small">Klik huruf status untuk mengubah; tersimpan otomatis. H Hadir · T Terlambat · S Sakit · I Izin · D Dispensasi · A Alpa. Perubahan manual tidak tertimpa scan atau surat izin.</p>
    @endif
@endif
@endsection
