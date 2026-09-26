{{-- Money-in transaction icon: an arrow pointing in (down-left), green, which
     is the same answer the amount beside it gives. The direction was carried by
     the arrow alone while the figure next to it was already coloured, so the two
     halves of one row said it differently. A gentle nudge animation, off under
     prefers-reduced-motion. Sits inside a small rounded tile at the call site;
     size via the class attribute (e.g. class="h-4 w-4"). --}}
<svg {{ $attributes->merge(['class' => 'shrink-0 text-emerald-600 dark:text-emerald-400']) }} viewBox="0 0 24 24" fill="none" role="img" aria-label="Money in">
    <style>
        .tc-arrow { animation: tc-nudge 2.4s ease-in-out infinite; }
        @keyframes tc-nudge { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(-1.25px, 1.25px); } }
        @media (prefers-reduced-motion: reduce) { .tc-arrow { animation: none; } }
    </style>
    <path class="tc-arrow" d="M17 7 L7.5 16.5 M7.5 9.5 V16.5 H14.5" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
