@extends('layouts.app')
@section('title', $lesson->schoolClass->name.' · '.($lesson->subject?->name ?? 'Pelajaran'))
@section('subtitle', \Carbon\Carbon::parse($lesson->date)->translatedFormat('l, j F Y').' · '.$lesson->periodLabel().' · '.($lesson->teacher?->name ?? ''))
@section('actions')
    @if ($canEdit)
        <form method="post" action="{{ route('lessons.destroy', $lesson) }}" data-confirm="Hapus sesi ini beserta absensinya?">@csrf @method('delete')<button class="btn btn-danger-ghost"><x-icon name="trash" /> Hapus</button></form>
    @endif
    <a href="{{ route('lessons.index', ['tanggal' => $lesson->date]) }}" class="btn btn-ghost">Selesai</a>
@endsection

@section('content')
@if ($lesson->topic)<p class="muted">Materi: {{ $lesson->topic }}</p>@endif
<div class="chips">
    @foreach (\App\Models\Attendance::STATUSES as $code => $label)
        <span class="chip chip-{{ $code }}"><b data-count="{{ $code }}">{{ $count[$code] }}</b> {{ $label }}</span>
    @endforeach
</div>

@if ($canEdit && $qr)
<section class="card lesson-scan" data-lesson-scan="{{ route('lessons.scan', $lesson) }}">
    <div class="row-between">
        <div><strong>Scan kartu siswa</strong><p class="muted small">Arahkan kamera HP ke kartu QR; siswa yang dipindai menjadi Hadir.</p></div>
        <button type="button" class="btn btn-primary" data-lesson-cam><x-icon name="camera" /> Nyalakan kamera</button>
    </div>
    <div id="lesson-reader" class="lesson-reader" hidden></div>
    <p class="lesson-scan-result" data-lesson-result></p>
</section>
@endif

<section class="card flush">
    <div class="table-wrap">
        <table class="table table-att">
            <thead><tr><th class="num">No</th><th>Siswa</th><th>Status</th><th>Keterangan</th></tr></thead>
            <tbody>
            @foreach ($marks as $i => $m)
                <tr data-student-row="{{ $m->student_id }}">
                    <td class="num muted">{{ $i + 1 }}</td>
                    <td><span class="person"><span class="avatar sm">{{ $m->student->initials() }}</span><span><strong>{{ $m->student->name }}</strong><small class="muted">{{ $m->student->nis }}@if ($m->scanned_at) · scan {{ $m->scanned_at->format('H:i') }}@endif</small></span></span></td>
                    <td>
                        @if ($canEdit)
                            <form method="post" action="{{ route('lessons.mark', $lesson) }}" class="seg" data-autosave>
                                @csrf
                                <input type="hidden" name="student_id" value="{{ $m->student_id }}">
                                @foreach (\App\Models\Attendance::STATUSES as $code => $label)
                                    <label class="seg-opt seg-{{ $code }}" title="{{ $label }}"><input type="radio" name="status" value="{{ $code }}" @checked($m->status === $code)><span>{{ $code }}</span></label>
                                @endforeach
                                <input type="hidden" name="note" value="{{ $m->note }}">
                                <noscript><button class="btn btn-sm">Simpan</button></noscript>
                            </form>
                        @else
                            <x-status :code="$m->status" />
                        @endif
                    </td>
                    <td class="small">
                        @if ($canEdit)<input class="input input-sm note-input" data-note-for="{{ $m->student_id }}" value="{{ $m->note }}" placeholder="Tambah catatan" maxlength="200">
                        @else{{ $m->note }}@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@if ($canEdit)<p class="muted small">Perubahan tersimpan otomatis. H Hadir · T Terlambat · S Sakit · I Izin · D Dispensasi · A Alpa.</p>@endif
@endsection

@if ($canEdit && $qr)
@push('scripts')
<script src="{{ asset('vendor/html5-qrcode.min.js') }}"></script>
@endpush
@endif
