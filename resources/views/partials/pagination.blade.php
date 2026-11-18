@if ($paginator->hasPages())
<nav class="pager">
    @if ($paginator->onFirstPage())<span class="btn btn-ghost is-disabled"><x-icon name="chevron-left" /></span>
    @else<a class="btn btn-ghost" href="{{ $paginator->previousPageUrl() }}"><x-icon name="chevron-left" /></a>@endif
    <span class="muted small">Halaman {{ $paginator->currentPage() }} dari {{ $paginator->lastPage() }}</span>
    @if ($paginator->hasMorePages())<a class="btn btn-ghost" href="{{ $paginator->nextPageUrl() }}"><x-icon name="chevron-right" /></a>
    @else<span class="btn btn-ghost is-disabled"><x-icon name="chevron-right" /></span>@endif
</nav>
@endif
