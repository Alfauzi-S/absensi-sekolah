@props(['label', 'name', 'hint' => null])
<label class="field">
    <span class="field-label">{{ $label }}</span>
    {{ $slot }}
    @if ($hint)<span class="field-hint">{{ $hint }}</span>@endif
    @error($name)<span class="field-error">{{ $message }}</span>@enderror
</label>
