{{-- Money-out transaction icon: an arrow pointing out (up-right), red, which is
     the same answer the amount beside it gives. The direction was carried by the
     arrow alone while the figure next to it was already coloured, so the two
     halves of one row said it differently. A gentle nudge animation, off under
     prefers-reduced-motion. Sits inside a small rounded tile at the call site;
     size via the class attribute (e.g. class="h-4 w-4"). --}}
<svg {{ $attributes->merge(['class' => 'shrink-0 text-red-600 dark:text-red-400']) }} viewBox="0 0 24 24" fill="none" role="img" aria-label="Money out">
    <style>
        .td-arrow { animation: td-nudge 2.4s ease-in-out infinite; }
        @keyframes td-nudge { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(1.25px, -1.25px); } }
        @media (prefers-reduced-motion: reduce) { .td-arrow { animation: none; } }
    </style>
    <path class="td-arrow" d="M7 17 L16.5 7.5 M9.5 7.5 H16.5 V14.5" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
