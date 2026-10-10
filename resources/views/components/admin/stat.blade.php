@props(['label', 'value', 'unit' => null, 'hint' => null, 'icon' => null, 'href' => null, 'delta' => null, 'deltaLabel' => 'vs yesterday', 'upIsGood' => true])
@php
    // Delta: signed integer/float difference vs. the previous period.
    $deltaClass = null;
    if ($delta !== null) {
        $deltaClass = $delta == 0 ? 'delta-flat' : ((($delta > 0) === $upIsGood) ? 'delta-up' : 'delta-down');
    }
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'stat']) }}>
    <div class="stat-label">@if ($icon)<x-admin.icon :name="$icon" size="sm" />@endif {{ $label }}</div>
    <div class="stat-value">{{ $value }}@if ($unit)<span class="unit">{{ $unit }}</span>@endif</div>
    @if ($delta !== null || $hint)
        <div class="stat-hint">
            @if ($delta !== null)
                <span class="delta {{ $deltaClass }}">
                    @if ($delta > 0)<x-admin.icon name="arrow-up" size="xs" />@elseif ($delta < 0)<x-admin.icon name="arrow-down" size="xs" />@endif
                    <span class="sr-only">{{ $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'no change') }}</span>{{ is_float($delta) ? rtrim(rtrim(number_format(abs($delta), 2, '.', ''), '0'), '.') : abs($delta) }}
                </span>
                {{ $deltaLabel }}@if ($hint) · @endif
            @endif
            {{ $hint }}
        </div>
    @endif
    {{ $slot }}
</{{ $tag }}>
