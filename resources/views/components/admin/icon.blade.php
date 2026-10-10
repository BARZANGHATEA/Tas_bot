@props(['name', 'size' => null])
<svg {{ $attributes->merge(['class' => 'icon'.($size ? ' icon-'.$size : '')]) }} aria-hidden="true" focusable="false"><use href="#i-{{ $name }}"/></svg>
