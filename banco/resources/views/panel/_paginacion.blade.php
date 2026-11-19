{{-- Paginación en español. Recibe $p (LengthAwarePaginator). --}}
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2">
    <span class="small text-body-secondary">
        @if ($p->total() > 0)
            Mostrando {{ $p->firstItem() }}–{{ $p->lastItem() }} de {{ $p->total() }} · página {{ $p->currentPage() }} de {{ $p->lastPage() }}
        @else
            Sin registros
        @endif
    </span>
    @if ($p->hasPages())
        <nav aria-label="Paginación">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item {{ $p->onFirstPage() ? 'disabled' : '' }}">
                    <a class="page-link" href="{{ $p->onFirstPage() ? '#' : $p->previousPageUrl() }}">« Anterior</a>
                </li>
                <li class="page-item {{ $p->hasMorePages() ? '' : 'disabled' }}">
                    <a class="page-link" href="{{ $p->hasMorePages() ? $p->nextPageUrl() : '#' }}">Siguiente »</a>
                </li>
            </ul>
        </nav>
    @endif
</div>
