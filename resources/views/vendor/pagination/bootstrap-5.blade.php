@if ($paginator->hasPages())
<nav>
    <ul class="pagination pagination-sm mb-0" style="gap:3px;">

        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <li class="page-item disabled">
                <span class="page-link" style="border-radius:6px;color:#9ca3af;border:1px solid #e5e7eb;">
                    &lt; Back
                </span>
            </li>
        @else
            <li class="page-item">
                <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev"
                    style="border-radius:6px;border:1px solid #e5e7eb;color:#374151;">&lt; Back</a>
            </li>
        @endif

        {{-- Page numbers --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <li class="page-item disabled">
                    <span class="page-link" style="border:none;background:transparent;color:#9ca3af;padding:0 4px;">•••</span>
                </li>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <li class="page-item active">
                            <span class="page-link"
                                style="border-radius:6px;background:#1e2a3a;border-color:#1e2a3a;color:#fff;min-width:36px;text-align:center;">
                                {{ $page }}
                            </span>
                        </li>
                    @else
                        <li class="page-item">
                            <a class="page-link" href="{{ $url }}"
                                style="border-radius:6px;border:1px solid #e5e7eb;color:#374151;min-width:36px;text-align:center;">
                                {{ $page }}
                            </a>
                        </li>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <li class="page-item">
                <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next"
                    style="border-radius:6px;border:1px solid #e5e7eb;color:#374151;">Next &gt;</a>
            </li>
        @else
            <li class="page-item disabled">
                <span class="page-link" style="border-radius:6px;color:#9ca3af;border:1px solid #e5e7eb;">
                    Next &gt;
                </span>
            </li>
        @endif

    </ul>
</nav>
@endif
