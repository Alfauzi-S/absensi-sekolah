@extends('layouts.base')
@section('title', 'Kiosk')
@section('body-class', 'kiosk')
@section('body')
<div class="kiosk-app" data-kiosk data-scan-url="{{ route('kiosk.scan', $token) }}" data-stats-url="{{ route('kiosk.stats', $token) }}">
    <header class="kiosk-head">
        <div class="brand">
            <span class="brand-mark"><x-icon name="scan" :size="20" /></span>
            <span class="brand-text"><strong>{{ $school }}</strong><small>Masuk {{ $s['checkin_start'] }} · terlambat lewat {{ $s['late_after'] }} · pulang {{ $s['checkout_start'] }}</small></span>
        </div>
        <div class="kiosk-clock"><strong data-clock>--:--</strong><span data-date></span></div>
    </header>

    <main class="kiosk-main">
        <section class="kiosk-scan">
            <div class="kiosk-camera" data-camera-box>
                <div id="reader"></div>
                <div class="kiosk-camera-idle" data-camera-idle>
                    <x-icon name="qr" :size="56" />
                    <p><strong>Tempelkan kartu ke scanner</strong><br>atau nyalakan kamera</p>
                    <button class="btn btn-primary btn-lg" data-camera-start><x-icon name="camera" /> Nyalakan kamera</button>
                </div>
            </div>
            <form class="kiosk-manual" data-manual>
                <input class="input" name="code" placeholder="Ketik kode kartu" autocomplete="off" data-code-input aria-label="Kode kartu">
                <button class="btn">Kirim</button>
            </form>
        </section>

        <section class="kiosk-side">
            <div class="result result-idle" data-result>
                <div class="result-avatar" data-r-avatar><x-icon name="scan" :size="36" /></div>
                <h2 data-r-title>Siap memindai</h2>
                <p class="result-name" data-r-name>{{ $stats['off'] ? 'Hari ini libur: '.$stats['off'] : 'Silakan scan kartu QR' }}</p>
                <p class="result-msg" data-r-msg></p>
            </div>
            <div class="kiosk-stats">
                <div><strong data-s-in>{{ $stats['in'] }}</strong><span>sudah masuk</span></div>
                <div><strong data-s-late>{{ $stats['late'] }}</strong><span>terlambat</span></div>
                <div><strong data-s-total>{{ $stats['total'] }}</strong><span>siswa</span></div>
            </div>
            <ul class="kiosk-log" data-log></ul>
        </section>
    </main>
</div>
@endsection

@push('scripts')
<script src="{{ asset('vendor/html5-qrcode.min.js') }}"></script>
<script src="{{ asset('js/kiosk.js') }}?v={{ filemtime(public_path('js/kiosk.js')) }}"></script>
@endpush
