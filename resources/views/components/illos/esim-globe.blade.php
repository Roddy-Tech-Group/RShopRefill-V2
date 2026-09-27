{{-- Animated eSIM scene: a globe with a plane tracing its orbit and a floating
     eSIM chip beaming signal - the evergreen replacement for the World Cup
     stadium advert, which dated the moment the tournament ended. Inline SVG,
     no JS, honors prefers-reduced-motion. Size via the class attribute. IDs are
     instance-suffixed so the scene can render more than once per page. --}}
@php $uid = 'eg'.uniqid(); @endphp
<svg {{ $attributes }} viewBox="0 0 820 640" role="img" aria-label="A globe circled by a plane with an eSIM chip beaming a signal">
  <style>
    .e4-orbit { transform-box: fill-box; transform-origin: center; animation: e4-spin 18s linear infinite; }
    .e4-globe { transform-box: fill-box; transform-origin: center; animation: e4-bob 5s ease-in-out infinite; }
    .e4-land  { animation: e4-drift 24s linear infinite; }
    .e4-chip  { animation: e4-bob 3.6s ease-in-out infinite 1s; }
    .e4-halo  { transform-box: fill-box; transform-origin: center; animation: e4-halo 3.6s ease-in-out infinite 1s; }
    .e4-sg    { opacity: 0; animation: e4-sig 2.2s ease-in-out infinite; }
    .e4-sgB   { animation-delay: 0.22s; }
    .e4-sgC   { animation-delay: 0.44s; }
    .e4-tw    { animation: e4-twinkle 3s ease-in-out infinite; }
    .e4-tw2   { animation: e4-twinkle 3.8s ease-in-out infinite 0.9s; }
    .e4-tw3   { animation: e4-twinkle 4.4s ease-in-out infinite 1.7s; }
    @keyframes e4-spin { to { transform: rotate(360deg); } }
    @keyframes e4-bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
    @keyframes e4-drift { to { transform: translateX(-120px); } }
    @keyframes e4-halo { 0%, 100% { transform: scale(1); opacity: 0.3; } 50% { transform: scale(1.18); opacity: 0.1; } }
    @keyframes e4-sig { 0%, 8% { opacity: 0; } 22%, 78% { opacity: 1; } 92%, 100% { opacity: 0; } }
    @keyframes e4-twinkle { 0%, 100% { opacity: 0.15; } 50% { opacity: 0.6; } }
    @media (prefers-reduced-motion: reduce) {
      .e4-orbit, .e4-globe, .e4-land, .e4-chip, .e4-halo, .e4-sg, .e4-tw, .e4-tw2, .e4-tw3 { animation: none; }
      .e4-sg { opacity: 1; }
    }
  </style>
  <defs>
    <linearGradient id="e4sea-{{ $uid }}" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#2563EB"/>
      <stop offset="1" stop-color="#132C5C"/>
    </linearGradient>
    <linearGradient id="e4land-{{ $uid }}" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#4ADE80"/>
      <stop offset="1" stop-color="#16A34A"/>
    </linearGradient>
    <clipPath id="e4clip-{{ $uid }}">
      <circle cx="0" cy="0" r="150"/>
    </clipPath>
  </defs>

  {{-- Transparent background: the parent card provides the dark surface. --}}

  {{-- Decor --}}
  <circle class="e4-tw" cx="90" cy="92" r="3" fill="#3B82F6"/>
  <circle class="e4-tw2" cx="742" cy="120" r="3" fill="#60A5FA"/>
  <circle class="e4-tw3" cx="120" cy="520" r="3" fill="#93C5FD"/>
  <path class="e4-tw2" d="M86 250 v14 M79 257 h14" stroke="#3B82F6" stroke-width="2.5" stroke-linecap="round"/>
  <path class="e4-tw3" d="M744 540 v14 M737 547 h14" stroke="#60A5FA" stroke-width="2.5" stroke-linecap="round"/>

  {{-- Globe + orbiting plane, centred so the orbit ring stays concentric. --}}
  <g transform="translate(380,330)">
    <ellipse cx="0" cy="196" rx="150" ry="22" fill="#03060D" opacity="0.45"/>

    <g class="e4-globe">
      <circle r="150" fill="url(#e4sea-{{ $uid }})"/>

      {{-- Continents drift behind the sphere edge to suggest rotation. --}}
      <g clip-path="url(#e4clip-{{ $uid }})">
        <g class="e4-land" fill="url(#e4land-{{ $uid }})">
          <path d="M-190 -78 q34 -30 74 -12 q30 14 22 44 q-8 30 -46 30 q-42 0 -56 -26 q-12 -22 6 -36 Z"/>
          <path d="M-96 24 q30 -16 56 4 q22 16 10 44 q-14 30 -50 26 q-34 -4 -40 -32 q-6 -28 24 -42 Z"/>
          <path d="M30 -96 q44 -22 82 4 q30 20 16 52 q-16 34 -60 30 q-44 -4 -54 -36 q-8 -30 16 -50 Z"/>
          <path d="M74 42 q36 -14 60 10 q20 20 4 46 q-18 28 -54 20 q-32 -8 -34 -36 q-2 -26 24 -40 Z"/>
          {{-- Duplicated one drift-width to the right so the loop is seamless. --}}
          <path d="M-70 -78 q34 -30 74 -12 q30 14 22 44 q-8 30 -46 30 q-42 0 -56 -26 q-12 -22 6 -36 Z"/>
          <path d="M24 24 q30 -16 56 4 q22 16 10 44 q-14 30 -50 26 q-34 -4 -40 -32 q-6 -28 24 -42 Z"/>
          <path d="M150 -96 q44 -22 82 4 q30 20 16 52 q-16 34 -60 30 q-44 -4 -54 -36 q-8 -30 16 -50 Z"/>
          <path d="M194 42 q36 -14 60 10 q20 20 4 46 q-18 28 -54 20 q-32 -8 -34 -36 q-2 -26 24 -40 Z"/>
        </g>
      </g>

      {{-- Meridians --}}
      <g fill="none" stroke="#BFDBFE" stroke-width="2" opacity="0.35">
        <circle r="150"/>
        <ellipse rx="60" ry="150"/>
        <ellipse rx="118" ry="150"/>
        <path d="M-150 0 H150"/>
        <path d="M-138 -62 H138"/>
        <path d="M-138 62 H138"/>
      </g>
      <circle r="150" fill="none" stroke="#3B82F6" stroke-width="3" opacity="0.55"/>
    </g>

    {{-- Orbit ring with the plane riding it. --}}
    <g class="e4-orbit">
      <ellipse rx="212" ry="212" fill="none" stroke="#3B82F6" stroke-width="2.5" stroke-dasharray="10 14" opacity="0.45"/>
      <g transform="translate(0,-212)">
        <g transform="rotate(90)">
          <path d="M28 0 L-14 -18 L-6 0 L-14 18 Z" fill="#F8FAFC"/>
          <path d="M-2 0 L-24 -22 L-14 -22 Z" fill="#BFDBFE"/>
          <path d="M-2 0 L-24 22 L-14 22 Z" fill="#BFDBFE"/>
        </g>
      </g>
    </g>
  </g>

  {{-- Floating eSIM chip - same component vocabulary as the other eSIM scenes. --}}
  <g transform="translate(648,300)">
    <circle class="e4-halo" cx="30" cy="22" r="38" stroke="#3B82F6" stroke-width="2.5" fill="none" opacity="0.3"/>
    <g class="e4-chip">
      <rect width="60" height="44" rx="10" fill="#3B82F6"/>
      <path d="M46 0 L60 0 L60 14 Z" fill="#070D1A"/>
      <rect x="15" y="11" width="30" height="22" rx="5" fill="none" stroke="#DBEAFE" stroke-width="2"/>
      <path d="M15 22 H45 M30 11 V33" stroke="#DBEAFE" stroke-width="2"/>
    </g>
    <path class="e4-sg" d="M70 6 A14 14 0 0 1 70 38" stroke="#BFDBFE" stroke-width="5" stroke-linecap="round" fill="none"/>
    <path class="e4-sg e4-sgB" d="M80 -2 A24 24 0 0 1 80 46" stroke="#BFDBFE" stroke-width="5" stroke-linecap="round" fill="none"/>
    <path class="e4-sg e4-sgC" d="M90 -10 A34 34 0 0 1 90 54" stroke="#BFDBFE" stroke-width="5" stroke-linecap="round" fill="none"/>
  </g>
</svg>
