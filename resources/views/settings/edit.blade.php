@extends('layouts.app')
@section('title', 'Pengaturan')
@section('subtitle', 'Identitas sekolah, jam absensi, hari libur, dan kiosk')

@section('content')
<div class="cols">
<section class="card col-main">
    <form method="post" class="stack">
        @csrf @method('put')
        <div class="card-head"><h2>Sekolah</h2></div>
        <x-field label="Nama sekolah" name="school_name"><input class="input" name="school_name" value="{{ old('school_name', $s['school_name']) }}" required maxlength="120"></x-field>
        <x-field label="Alamat" name="school_address"><input class="input" name="school_address" value="{{ old('school_address', $s['school_address']) }}" maxlength="255"></x-field>
        <div class="grid-2">
            <x-field label="Kepala sekolah" name="headmaster_name" hint="Dipakai di tanda tangan rekap Excel."><input class="input" name="headmaster_name" value="{{ old('headmaster_name', $s['headmaster_name']) }}" maxlength="120"></x-field>
            <x-field label="NIP kepala sekolah" name="headmaster_nip"><input class="input" name="headmaster_nip" value="{{ old('headmaster_nip', $s['headmaster_nip']) }}" maxlength="30"></x-field>
        </div>

        <div class="divider"></div>
        <div class="card-head"><h2>Jam absensi</h2></div>
        <div class="timeline">
            <x-field label="Scan masuk dibuka" name="checkin_start"><input class="input" type="time" name="checkin_start" value="{{ old('checkin_start', $s['checkin_start']) }}" required></x-field>
            <x-field label="Terlambat setelah" name="late_after"><input class="input" type="time" name="late_after" value="{{ old('late_after', $s['late_after']) }}" required></x-field>
            <x-field label="Jam pulang" name="checkout_start"><input class="input" type="time" name="checkout_start" value="{{ old('checkout_start', $s['checkout_start']) }}" required></x-field>
            <x-field label="Scan pulang ditutup" name="checkout_end"><input class="input" type="time" name="checkout_end" value="{{ old('checkout_end', $s['checkout_end']) }}" required></x-field>
        </div>
        <p class="muted small">Scan sebelum jam pulang dihitung masuk; setelahnya dihitung pulang. Pada jam pulang, siswa yang belum tercatat otomatis diberi Alpa (atau status surat izinnya).</p>

        <div class="field">
            <span class="field-label">Libur mingguan</span>
            <div class="seg seg-wrap">
                @foreach ([1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'] as $d => $label)
                    <label class="seg-opt"><input type="checkbox" name="weekend_days[]" value="{{ $d }}" @checked(in_array($d, old('weekend_days', \App\Models\Setting::weekendDays())))><span>{{ $label }}</span></label>
                @endforeach
            </div>
        </div>
        <div class="divider"></div>
        <div class="card-head"><h2>Absensi per jam pelajaran</h2></div>
        <div class="field">
            <span class="field-label">Mode</span>
            <div class="seg seg-lg seg-wrap">
                @foreach (\App\Models\Setting::LESSON_MODES as $k => $v)
                    <label class="seg-opt"><input type="radio" name="lesson_mode" value="{{ $k }}" @checked(old('lesson_mode', $s['lesson_mode']) === $k)><span>{{ $v }}</span></label>
                @endforeach
            </div>
            <span class="field-hint">Diisi guru: guru membuka sesi dan menandai yang tidak hadir. Scan kartu: guru juga bisa memindai kartu siswa dengan kamera HP.</span>
        </div>
        <div class="field">
            <span class="field-label">Yang boleh mengabsen</span>
            <div class="seg seg-lg seg-wrap">
                @foreach (\App\Models\Setting::LESSON_ACCESS as $k => $v)
                    <label class="seg-opt"><input type="radio" name="lesson_access" value="{{ $k }}" @checked(old('lesson_access', $s['lesson_access']) === $k)><span>{{ $v }}</span></label>
                @endforeach
            </div>
            <span class="field-hint">Admin dan TU selalu boleh. Kepala sekolah hanya melihat.</span>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Simpan pengaturan</button></div>
    </form>

    <div class="divider"></div>
    <div class="grid-2">
        <div>
            <div class="card-head"><h2>Mata pelajaran</h2></div>
            <form method="post" action="{{ route('settings.subject') }}" class="copy">
                @csrf
                <input class="input input-sm" name="subject" placeholder="Nama mapel" required maxlength="80" aria-label="Nama mapel">
                <button class="btn btn-sm"><x-icon name="plus" /> Tambah</button>
            </form>
            @error('subject')<span class="field-error">{{ $message }}</span>@enderror
            <ul class="list">
                @forelse ($subjects as $sub)
                    <li class="list-row"><span class="grow">{{ $sub->name }}</span>
                        <form method="post" action="{{ route('settings.subject.remove', $sub) }}">@csrf @method('delete')<button class="icon-btn danger" aria-label="Hapus"><x-icon name="x" :size="16" /></button></form></li>
                @empty
                    <li class="muted small">Belum ada mapel.</li>
                @endforelse
            </ul>
        </div>
        <div>
            <div class="card-head"><h2>Jam pelajaran</h2></div>
            <form method="post" action="{{ route('settings.period') }}" class="copy">
                @csrf
                <input class="input input-sm" type="time" name="start_time" required aria-label="Mulai">
                <input class="input input-sm" type="time" name="end_time" required aria-label="Selesai">
                <button class="btn btn-sm"><x-icon name="plus" /></button>
            </form>
            @error('end_time')<span class="field-error">{{ $message }}</span>@enderror
            <ul class="list">
                @forelse ($periods as $p)
                    <li class="list-row"><span class="date-badge"><b>{{ $p->number }}</b>jam</span><span class="grow">{{ $p->start_time }} – {{ $p->end_time }}</span>
                        <form method="post" action="{{ route('settings.period.remove', $p) }}">@csrf @method('delete')<button class="icon-btn danger" aria-label="Hapus"><x-icon name="x" :size="16" /></button></form></li>
                @empty
                    <li class="muted small">Belum ada jam pelajaran.</li>
                @endforelse
            </ul>
        </div>
    </div>
</section>

<aside class="col-side stack">
    <section class="card">
        <div class="card-head"><h2>Layar kiosk</h2></div>
        <p class="muted small">Buka tautan ini di tablet/HP gerbang sekolah. Bisa memakai kamera atau scanner USB. Jangan bagikan ke siswa.</p>
        <div class="copy">
            <input class="input mono input-sm" value="{{ $kioskUrl }}" readonly data-copy>
            <button type="button" class="btn btn-sm" data-copy-btn>Salin</button>
        </div>
        <div class="kiosk-qr" data-qr-url="{{ $kioskUrl }}"></div>
        <div class="row-between">
            <a class="btn btn-ghost btn-sm" href="{{ $kioskUrl }}" target="_blank" rel="noopener"><x-icon name="external" /> Buka</a>
            <form method="post" action="{{ route('settings.kiosk') }}" data-confirm="Buat tautan baru? Tablet yang memakai tautan lama harus dibuka ulang dengan tautan baru.">@csrf<button class="btn btn-ghost btn-sm"><x-icon name="refresh" /> Tautan baru</button></form>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><h2>Hari libur</h2></div>
        <form method="post" action="{{ route('settings.holiday') }}" class="stack">
            @csrf
            <div class="grid-2 tight">
                <input class="input input-sm" type="date" name="date" required aria-label="Tanggal">
                <input class="input input-sm" name="name" placeholder="Nama libur" required maxlength="120" aria-label="Nama libur">
            </div>
            @error('date')<span class="field-error">{{ $message }}</span>@enderror
            <button class="btn btn-sm"><x-icon name="plus" /> Tambah</button>
        </form>
        <ul class="list">
            @forelse ($holidays as $h)
                <li class="list-row">
                    <span class="date-badge"><b>{{ \Carbon\Carbon::parse($h->date)->format('j') }}</b>{{ \Carbon\Carbon::parse($h->date)->translatedFormat('M') }}</span>
                    <span class="grow">{{ $h->name }}</span>
                    <form method="post" action="{{ route('settings.holiday.remove', $h) }}">@csrf @method('delete')<button class="icon-btn danger" aria-label="Hapus"><x-icon name="x" :size="16" /></button></form>
                </li>
            @empty
                <li class="muted small">Belum ada hari libur yang akan datang.</li>
            @endforelse
        </ul>
    </section>
</aside>
</div>
@endsection

@push('scripts')
<script src="{{ asset('vendor/qrcode.min.js') }}"></script>
<script>
document.querySelectorAll('[data-qr-url]').forEach(function (el) { new QRCode(el, { text: el.dataset.qrUrl, width: 148, height: 148 }); });
</script>
@endpush
