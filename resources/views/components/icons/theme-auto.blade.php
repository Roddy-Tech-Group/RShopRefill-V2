{{-- Animated auto-theme icon: an "A" that breathes, matching the set.

     It used to be a small sun offset to (9.5, 9.5) with an "A" tucked into the
     bottom-right corner. Two marks in one 24px box is fine at 32px and unreadable
     at the 16px these render at, where it came out as a smudge: rays, core and
     letter all collapsed into each other.

     Its siblings are single centred marks filling the box at stroke-width 2
     (theme-light a sun at 12,12; theme-dark a circle of r 8.6). This now matches
     that footprint and weight, so the three read as one set rather than two
     symbols and a scribble.

     Inline SVG so currentColor inherits the row text colour. Size it from the
     call site (class="h-4 w-4"). --}}
<svg {{ $attributes->merge(['class' => 'shrink-0']) }} viewBox="0 0 24 24" fill="none" role="img" aria-label="Auto theme">
    <style>
        /* The same slow breath the sun's core uses, so the trio animates alike.
           Origin is the glyph's centre, not the box's, or it drifts as it scales. */
        .tauto { transform-origin: 12px 13px; animation: tauto-pulse 3s ease-in-out infinite; }
        @keyframes tauto-pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.08); } }
        @media (prefers-reduced-motion: reduce) { .tauto { animation: none; } }
    </style>
    <g class="tauto" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5.6 20 L12 4.6 L18.4 20"/>
        <path d="M8.5 14.6 H15.5"/>
    </g>
</svg>
