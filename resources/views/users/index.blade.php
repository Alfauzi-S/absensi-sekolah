@extends('layouts.app')
@section('title', 'Pengguna')
@section('subtitle', 'Akun guru, wali kelas, TU, dan kepala sekolah')
@section('actions')<a href="{{ route('users.create') }}" class="btn btn-primary"><x-icon name="plus" /> Tambah akun</a>@endsection

@section('content')
<section class="card flush">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nama</th><th>Username</th><th>Peran</th><th>Wali kelas</th><th>Login terakhir</th><th></th></tr></thead>
            <tbody>
            @foreach ($users as $u)
                <tr @class(['is-muted' => ! $u->is_active])>
                    <td><span class="person"><span class="avatar sm">{{ $u->initials() }}</span><strong>{{ $u->name }}</strong></span></td>
                    <td class="mono">{{ $u->username }}</td>
                    <td><span class="pill pill-role-{{ $u->role }}">{{ $u->roleLabel() }}</span>@unless ($u->is_active)<span class="tag">nonaktif</span>@endunless</td>
                    <td>{{ $u->homeroomClasses->pluck('name')->join(', ') ?: '–' }}</td>
                    <td class="small muted">{{ $u->last_login_at?->diffForHumans() ?? 'Belum pernah' }}</td>
                    <td class="actions"><a class="icon-btn" href="{{ route('users.edit', $u) }}" title="Ubah" aria-label="Ubah"><x-icon name="edit" /></a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
<p class="muted small">Kepala Sekolah hanya melihat. Guru melihat dan mengoreksi kelas yang diwalikan (atur di menu Kelas). TU mengelola data siswa, kelas, dan izin.</p>
@endsection
