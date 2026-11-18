@extends('layouts.base')

@section('body')
@php
    $u = auth()->user();
    $nav = [
        ['Utama', [
            ['dashboard', 'Beranda', 'home', true],
            ['attendance', 'Absensi Harian', 'clipboard', true],
            ['lessons.index', 'Absensi per Jam', 'clock', \App\Models\Setting::lessonsEnabled()],
            ['leaves.index', 'Izin & Sakit', 'file', true],
            ['recap', 'Rekap', 'chart', true],
        ]],
        ['Data Sekolah', [
            ['students.index', 'Siswa', 'users', $u->canManageData(), ['students.index', 'students.show', 'students.edit', 'students.create']],
            ['classes.index', 'Kelas', 'school', $u->canManageData()],
            ['students.cards', 'Kartu QR', 'qr', $u->canManageData()],
            ['students.import', 'Impor Siswa', 'upload', $u->canManageData()],
        ]],
        ['Admin', [
            ['users.index', 'Pengguna', 'user-cog', $u->isAdmin()],
            ['settings', 'Pengaturan', 'settings', $u->isAdmin()],
        ]],
    ];
    $active = fn ($r, $patterns = null) => request()->routeIs(...($patterns ?? [$r, str_replace('.index', '', $r).'.*']));
@endphp
<div class="shell" data-shell>
    <aside class="sidebar" id="sidebar">
        <a href="{{ route('dashboard') }}" class="brand">
            <span class="brand-mark"><x-icon name="scan" :size="20" /></span>
            <span class="brand-text">
                <strong>Absensi</strong>
                <small>{{ \App\Models\Setting::get('school_name') }}</small>
            </span>
        </a>
        <nav class="nav">
            @foreach ($nav as [$group, $items])
                @php $items = array_filter($items, fn ($i) => $i[3]); @endphp
                @if ($items)
                    <div class="nav-group">{{ $group }}</div>
                    @foreach ($items as $item)
                        @php [$route, $label, $icon] = $item; @endphp
                        <a href="{{ route($route) }}" @class(['nav-link', 'is-active' => $active($route, $item[4] ?? null)])>
                            <x-icon :name="$icon" /> <span>{{ $label }}</span>
                        </a>
                    @endforeach
                @endif
            @endforeach
        </nav>
        @if ($u->isAdmin())
            <a class="kiosk-link" href="{{ route('kiosk', \App\Models\Setting::kioskToken()) }}" target="_blank" rel="noopener">
                <x-icon name="maximize" /> <span>Buka layar kiosk</span>
            </a>
        @endif
        <div class="me">
            <span class="avatar">{{ $u->initials() }}</span>
            <a href="{{ route('account') }}" class="me-text">
                <strong>{{ $u->name }}</strong>
                <small>{{ $u->roleLabel() }}</small>
            </a>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button class="icon-btn" title="Keluar" aria-label="Keluar"><x-icon name="logout" /></button>
            </form>
        </div>
    </aside>
    <div class="scrim" data-close-nav></div>

    <main class="main">
        <header class="topbar">
            <button class="icon-btn only-mobile" data-open-nav aria-label="Buka menu"><x-icon name="menu" /></button>
            <div class="topbar-title">
                <h1>@yield('title')</h1>
                @hasSection('subtitle')<p>@yield('subtitle')</p>@endif
            </div>
            <div class="topbar-actions">@yield('actions')</div>
        </header>

        <div class="content">
            @if (session('ok'))
                <div class="toast toast-ok" role="status" data-toast><x-icon name="check" /> {{ session('ok') }}</div>
            @endif
            @if ($errors->has('class') || $errors->has('general'))
                <div class="toast toast-err" role="alert"><x-icon name="alert" /> {{ $errors->first('class') ?: $errors->first('general') }}</div>
            @endif
            @yield('content')
        </div>
    </main>
</div>
@endsection
