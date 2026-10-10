@php
    $isLength = $paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;
    $current = $paginator->currentPage();
    $lastPage = $isLength ? $paginator->lastPage() : null;
    $window = [];
    if ($isLength) {
        foreach ([1, $current - 1, $current, $current + 1, $lastPage] as $p) {
            if ($p >= 1 && $p <= $lastPage) { $window[$p] = true; }
        }
        ksort($window);
    }
@endphp
@if ($isLength ? $paginator->total() : $paginator->count())
<div class="pagination-bar">
    <div>
        @if ($isLength)
            Showing <strong class="tabular">{{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }}</strong> of <strong class="tabular">{{ number_format($paginator->total()) }}</strong>
        @endif
    </div>
    <div class="row">
        @if ($isLength && ! empty($perPageOptions ?? true))
            <form method="get" class="per-page">
                @foreach (request()->except(['per_page', 'page']) as $key => $value)
                    @if (is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                @endforeach
                <label for="per-page-{{ $paginator->getPageName() }}" class="small">Rows</label>
                <select id="per-page-{{ $paginator->getPageName() }}" name="per_page" class="select" data-autosubmit>
                    @foreach ([25, 50, 100] as $n)<option value="{{ $n }}" @selected($paginator->perPage() == $n)>{{ $n }}</option>@endforeach
                </select>
            </form>
        @endif
        @if ($paginator->hasPages())
            <nav class="pagination" aria-label="Pagination">
                <a class="page-link" href="{{ $paginator->previousPageUrl() ?? '#' }}" @if ($paginator->onFirstPage()) aria-disabled="true" tabindex="-1" @endif rel="prev" aria-label="Previous page"><x-admin.icon name="chevron-left" size="sm" /></a>
                @if ($isLength)
                    @php $prev = 0; @endphp
                    @foreach (array_keys($window) as $p)
                        @if ($p - $prev > 1)<span class="page-link" aria-hidden="true">…</span>@endif
                        <a class="page-link" href="{{ $paginator->url($p) }}" @if ($p === $current) aria-current="page" @endif>{{ $p }}</a>
                        @php $prev = $p; @endphp
                    @endforeach
                @endif
                <a class="page-link" href="{{ $paginator->nextPageUrl() ?? '#' }}" @if (! $paginator->hasMorePages()) aria-disabled="true" tabindex="-1" @endif rel="next" aria-label="Next page"><x-admin.icon name="chevron-right" size="sm" /></a>
            </nav>
        @endif
    </div>
</div>
@endif
