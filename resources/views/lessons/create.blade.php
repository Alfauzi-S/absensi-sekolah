@extends('layouts.app')
@section('title', 'Mulai sesi pelajaran')
@section('subtitle', 'Daftar siswa akan terisi Hadir; Anda tinggal mengubah yang tidak hadir')

@section('content')
<section class="card narrow">
    <form method="post" action="{{ route('lessons.store') }}" class="stack">
        @csrf
        <div class="grid-2">
            <x-field label="Kelas" name="class_id">
                <select class="input" name="class_id" required>
                    @foreach ($classes as $c)<option value="{{ $c->id }}" @selected(old('class_id', $selectedClass) == $c->id)>{{ $c->name }}</option>@endforeach
                </select>
            </x-field>
            <x-field label="Mata pelajaran" name="subject_id">
                <select class="input" name="subject_id">
                    <option value="">Tanpa mapel</option>
                    @foreach ($subjects as $s)<option value="{{ $s->id }}" @selected(old('subject_id') == $s->id)>{{ $s->name }}</option>@endforeach
                </select>
            </x-field>
        </div>
        <div class="grid-2">
            <x-field label="Tanggal" name="date"><input class="input" type="date" name="date" value="{{ old('date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required></x-field>
            <div class="grid-2 tight">
                <x-field label="Jam ke" name="period_start">
                    @if ($periods->isEmpty())
                        <input class="input" type="number" name="period_start" min="1" max="20" value="{{ old('period_start', 1) }}" required>
                    @else
                        <select class="input" name="period_start">@foreach ($periods as $p)<option value="{{ $p->number }}" @selected(old('period_start', $current) == $p->number)>{{ $p->number }} · {{ $p->start_time }}</option>@endforeach</select>
                    @endif
                </x-field>
                <x-field label="Sampai jam ke" name="period_end">
                    @if ($periods->isEmpty())
                        <input class="input" type="number" name="period_end" min="1" max="20" value="{{ old('period_end', 1) }}" required>
                    @else
                        <select class="input" name="period_end">@foreach ($periods as $p)<option value="{{ $p->number }}" @selected(old('period_end', $current) == $p->number)>{{ $p->number }} · {{ $p->end_time }}</option>@endforeach</select>
                    @endif
                </x-field>
            </div>
        </div>
        <x-field label="Materi / catatan (opsional)" name="topic"><input class="input" name="topic" value="{{ old('topic') }}" maxlength="200"></x-field>
        <div class="form-actions">
            <a href="{{ route('lessons.index') }}" class="btn btn-ghost">Batal</a>
            <button class="btn btn-primary">Mulai absen</button>
        </div>
    </form>
</section>
@endsection
