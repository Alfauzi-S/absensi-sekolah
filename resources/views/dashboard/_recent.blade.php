@if ($recent->isEmpty())
    <x-empty icon="scan" title="Belum ada scan hari ini" />
@else
    <ul class="list">
        @foreach ($recent as $r)
            <li class="list-row">
                <span class="avatar sm">{{ $r->student->initials() }}</span>
                <span class="grow"><strong>{{ $r->student->name }}</strong><small class="muted">{{ $r->student->schoolClass?->name }} · {{ $r->check_in_at->format('H:i') }}</small></span>
                <x-status :code="$r->status" />
            </li>
        @endforeach
    </ul>
@endif
