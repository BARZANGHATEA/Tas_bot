@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="page disabled">‹ Prev</span>
        @else
            <a class="page" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Prev</a>
        @endif
        <span class="page current">Page {{ $paginator->currentPage() }}@if (method_exists($paginator, 'lastPage')) of {{ $paginator->lastPage() }}@endif</span>
        @if ($paginator->hasMorePages())
            <a class="page" href="{{ $paginator->nextPageUrl() }}" rel="next">Next ›</a>
        @else
            <span class="page disabled">Next ›</span>
        @endif
    </nav>
@endif
