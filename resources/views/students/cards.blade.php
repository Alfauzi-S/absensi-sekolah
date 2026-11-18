@extends('layouts.app')
@section('title', 'Kartu QR')
@section('subtitle', 'Cetak kartu siswa ukuran kartu ATM (8,6 × 5,4 cm)')
@section('actions')
    @if ($students->isNotEmpty())<button class="btn btn-primary" onclick="window.print()"><x-icon name="printer" /> Cetak {{ $students->count() }} kartu</button>@endif
@endsection

@section('content')
<form class="toolbar no-print" method="get">
    <select class="input" name="kelas" onchange="this.form.submit()">
        <option value="">Pilih kelas…</option>
        @foreach ($classes as $c)<option value="{{ $c->id }}" @selected(request('kelas') == $c->id)>{{ $c->name }}</option>@endforeach
    </select>
    <span class="muted small">Di dialog cetak pilih kertas A4, skala 100%, dan aktifkan "Background graphics". Simpan sebagai PDF bila perlu.</span>
</form>

@if ($students->isEmpty())
    <section class="card no-print"><x-empty icon="qr" title="Pilih kelas untuk menampilkan kartu" /></section>
@else
    <div class="card-sheet">
        @foreach ($students as $s)
            <article class="id-card">
                <header><span class="brand-mark xs"><x-icon name="scan" :size="12" /></span><span>{{ $school }}</span></header>
                <div class="id-body">
                    <div class="id-info">
                        <small>KARTU ABSENSI SISWA</small>
                        <strong>{{ $s->name }}</strong>
                        <span>NIS {{ $s->nis }}</span>
                        <span>Kelas {{ $s->schoolClass?->name ?? '–' }}</span>
                        <code>{{ $s->prettyCode() }}</code>
                    </div>
                    <div class="id-qr" data-qr="{{ $s->qr_code }}"></div>
                </div>
            </article>
        @endforeach
    </div>
@endif
@endsection

@push('scripts')
<script src="{{ asset('vendor/qrcode.min.js') }}"></script>
<script>
document.querySelectorAll('[data-qr]').forEach(function (el) {
    new QRCode(el, { text: el.dataset.qr, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M });
});
</script>
@endpush
