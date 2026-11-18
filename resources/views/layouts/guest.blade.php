@extends('layouts.base')
@section('body-class', 'guest')
@section('body')
<div class="guest-wrap">
    <div class="guest-art" aria-hidden="true">
        <div class="guest-art-inner">
            <span class="brand-mark lg"><x-icon name="scan" :size="28" /></span>
            <h2>Absensi siswa,<br>cukup sekali tempel.</h2>
            <p>Siswa scan kartu QR di gerbang, guru dan wali kelas langsung melihat siapa yang hadir, terlambat, atau belum datang.</p>
            <ul class="guest-points">
                <li><x-icon name="check" /> Rekap bulanan siap cetak</li>
                <li><x-icon name="check" /> Izin dan sakit tercatat rapi</li>
                <li><x-icon name="check" /> Bisa dibuka dari HP</li>
            </ul>
        </div>
    </div>
    <div class="guest-form">
        <div class="guest-card">
            @yield('content')
        </div>
    </div>
</div>
@endsection
