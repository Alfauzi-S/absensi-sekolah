@props(['icon' => 'clipboard', 'title'])
<div class="empty">
    <div class="empty-icon"><x-icon :name="$icon" :size="22" /></div>
    <p class="empty-title">{{ $title }}</p>
    @if (trim($slot))<p class="empty-text">{{ $slot }}</p>@endif
</div>
