@props(['column', 'sort' => [], 'default' => 'desc'])
@php
    $active = ($sort['sort'] ?? null) === $column;
    $dir = $active ? ($sort['dir'] ?? 'desc') : null;
    $next = $active ? ($dir === 'asc' ? 'desc' : 'asc') : $default;
    $url = request()->fullUrlWithQuery(['sort' => $column, 'dir' => $next, 'page' => null]);
@endphp
<th {{ $attributes }} @if ($active) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif scope="col">
    <a href="{{ $url }}" class="sort-link">{{ $slot }}<x-admin.icon :name="$active ? ($dir === 'asc' ? 'chevron-up' : 'chevron-down') : 'sort'" size="xs" /><span class="sr-only">{{ $active ? 'sorted '.($dir === 'asc' ? 'ascending' : 'descending').', ' : '' }}sort {{ $next === 'asc' ? 'ascending' : 'descending' }}</span></a>
</th>
