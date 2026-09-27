{{--
    Overrides the framework default (pagination::tailwind) so `links()` emits
    this app's own markup. The app ships no Tailwind; the file name is only
    the slot Laravel looks in.
--}}
@if ($paginator->hasPages())
    <nav aria-label="Pagination">
        <ul class="pager__links">
            @if ($paginator->onFirstPage())
                <li class="disabled" aria-disabled="true">
                    <span aria-label="@lang('pagination.previous')">&lsaquo;</span>
                </li>
            @else
                <li>
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                       aria-label="@lang('pagination.previous')">&lsaquo;</a>
                </li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="disabled" aria-disabled="true"><span>{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="active" aria-current="page"><span>{{ $page }}</span></li>
                        @else
                            <li><a href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li>
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                       aria-label="@lang('pagination.next')">&rsaquo;</a>
                </li>
            @else
                <li class="disabled" aria-disabled="true">
                    <span aria-label="@lang('pagination.next')">&rsaquo;</span>
                </li>
            @endif
        </ul>
    </nav>
@endif
