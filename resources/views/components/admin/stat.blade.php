@props(['label', 'value', 'hint' => null, 'tone' => null, 'href' => null])
<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif class="stat {{ $tone ? 'stat-'.$tone : '' }}">
    <div class="stat-label">{{ $label }}</div>
    <div class="stat-value">{{ $value }}</div>
    @if ($hint)<div class="stat-hint">{{ $hint }}</div>@endif
</{{ $href ? 'a' : 'div' }}>
