{{-- Animated dark-theme icon: a moon that waxes from half to full and wanes
     back, every few seconds. The left half is always lit. The right half is a
     second half-disc that grows out of the centre line (scaleX 0 -> 1), so its
     curved edge sweeps across the face like the terminator, then shrinks back.
     It used to be one half-disc flipping sides, which read as a coin, not a
     moon. Inline SVG so currentColor inherits the row text colour. Size it
     from the call site (class="h-4 w-4"). --}}
<svg {{ $attributes->merge(['class' => 'shrink-0']) }} viewBox="0 0 24 24" fill="none" role="img" aria-label="Dark theme">
    <style>
        .td-wax {
            /* At rest (and under reduced motion) the moon is HALF: the waxing
               half is collapsed. The keyframes take over from here. */
            transform: scaleX(0);
            transform-origin: 12px 12px;
            animation: td-phase 6s cubic-bezier(0.45, 0, 0.25, 1) infinite;
        }
        @keyframes td-phase {
            0%, 12%   { transform: scaleX(0); }   /* half moon */
            48%, 62%  { transform: scaleX(1); }   /* full moon */
            98%, 100% { transform: scaleX(0); }   /* and back */
        }
        @media (prefers-reduced-motion: reduce) { .td-wax { animation: none; } }
    </style>
    <circle cx="12" cy="12" r="8.6" stroke="currentColor" stroke-width="2"/>
    {{-- The lit half, fixed. Sweep 0 runs the arc down the LEFT side. --}}
    <path d="M12 3.4 A8.6 8.6 0 0 0 12 20.6 Z" fill="currentColor"/>
    {{-- The waxing half. Sweep 1 runs the arc down the RIGHT side; scaled from
         the centre line it grows into a lens, then the full disc, then back. --}}
    <path class="td-wax" d="M12 3.4 A8.6 8.6 0 0 1 12 20.6 Z" fill="currentColor"/>
</svg>
