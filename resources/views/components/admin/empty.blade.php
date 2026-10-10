@props(['icon' => 'inbox', 'title', 'text' => null])
<div {{ $attributes->merge(['class' => 'empty']) }}>
    <div class="empty-icon"><x-admin.icon :name="$icon" /></div>
    <div class="empty-title">{{ $title }}</div>
    @if ($text)<p class="empty-text">{{ $text }}</p>@endif
    {{ $slot }}
</div>
