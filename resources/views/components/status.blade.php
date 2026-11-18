@props(['code'])
@php $labels = \App\Models\Attendance::STATUSES; @endphp
<span {{ $attributes->merge(['class' => 'pill pill-'.$code]) }}>{{ $labels[$code] ?? $code }}</span>
