@if ($paginator->hasPages())
<nav class="k-pagination" role="navigation" aria-label="Pagination navigation">
 <div class="k-pagination-info"><span class="k-pagination-total">{{ number_format($paginator->total()) }} records</span><span class="k-pagination-range">Showing <strong>{{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }}</strong> of {{ number_format($paginator->total()) }}</span></div>
 <div class="k-pagination-controls">
 @if ($paginator->onFirstPage())<span class="k-page-btn k-page-disabled" aria-disabled="true"><i class="fa-solid fa-chevron-left"></i><span>Previous</span></span>@else<a class="k-page-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev"><i class="fa-solid fa-chevron-left"></i><span>Previous</span></a>@endif
 <div class="k-page-numbers">
 @foreach ($elements as $element)
  @if (is_string($element))<span class="k-page-ellipsis" aria-hidden="true">{{ $element }}</span>@endif
  @if (is_array($element))@foreach ($element as $page => $url)
   @if ($page == $paginator->currentPage())<span class="k-page-btn k-page-current" aria-current="page" aria-label="Page {{ $page }}">{{ $page }}</span>@else<a class="k-page-btn k-page-number" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>@endif
  @endforeach @endif
 @endforeach
 </div>
 @if ($paginator->hasMorePages())<a class="k-page-btn" href="{{ $paginator->nextPageUrl() }}" rel="next"><span>Next</span><i class="fa-solid fa-chevron-right"></i></a>@else<span class="k-page-btn k-page-disabled" aria-disabled="true"><span>Next</span><i class="fa-solid fa-chevron-right"></i></span>@endif
 </div>
</nav>
@endif
