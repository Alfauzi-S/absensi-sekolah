@extends('layouts.app')
@section('title', 'Kelas')
@section('subtitle', $classes->count().' kelas')
@section('actions')<a href="{{ route('classes.create') }}" class="btn btn-primary"><x-icon name="plus" /> Tambah kelas</a>@endsection

@section('content')
@if ($classes->isEmpty())
    <section class="card"><x-empty icon="school" title="Belum ada kelas">Kelas juga dibuat otomatis saat mengimpor siswa.</x-empty></section>
@else
<div class="tiles">
    @foreach ($classes as $c)
        <a class="tile" href="{{ route('classes.edit', $c) }}">
            <div class="tile-top"><span class="tile-grade">{{ $c->grade }}</span><strong>{{ $c->name }}</strong></div>
            <div class="tile-meta"><x-icon name="users" :size="15" /> {{ $c->total }} siswa</div>
            <div class="tile-meta muted"><x-icon name="user-cog" :size="15" /> {{ $c->homeroomTeacher?->name ?? 'Belum ada wali kelas' }}</div>
        </a>
    @endforeach
</div>
@endif
@endsection
