{{--
    ╔══════════════════════════════════════════════════════════════════════════╗
    ║  <x-illo> — reusable inline animated SVG illustrations                    ║
    ╚══════════════════════════════════════════════════════════════════════════╝

    WHY: the SVG ships inline in the HTML, so there is NO image request and it
    paints instantly (this is what replaced the slow .webp/.png assets). The GSAP
    engine in resources/js/app.js wires the motion off the `data-illo` attribute.

    USAGE:
        <x-illo name="gift" class="h-16 w-16" />          (size via the class)
        <x-illo name="payout" class="h-full w-full" />    (fills a sized box)

    AVAILABLE NAMES
        Hero chips (200x200, light circle bg — size square, e.g. h-16 w-16):
          · gift      — gift box with bow + tag
          · globe     — globe, location pin, orbiting plane
          · shield    — security shield + verified user
          · search    — shopping bags + magnifier
          · mobile    — phone with gift, "works on every device"
        Full scenes (size with max-w-* or h-full w-full):
          · notFound  — 420x200, the animated "404" characters (used on errors/404)
          · emptyCart — 520x400 dark panel, desert cart (cart page + nav popups)
          · cardFan   — 320x260 dark panel, fanned gift cards (how-it-works step 1)
          · payWeb    — 320x260 dark panel, checkout hub linked to each accepted payment method, w/ travelling dots (step 2)
          · delivery  — 320x260 dark panel, a card flying into a phone: delivered notification + code (step 3)

    ADDING A NEW ONE (keep it reusable):
        1. Add a @case('myName') here with the inline <svg> (namespace every id/class,
           e.g. #imy-thing / .imy-bit, so multiple illustrations can share a page).
        2. Add a matching `myName` entry to the ILLOS registry in app.js with
           set(g, el) / build(tl) / idle(g, el). The engine scopes selectors per
           instance (gsap.context), plays on scroll-into-view, and replays on hover.

    BEHAVIOR: entrance plays when scrolled into view; idle loops forever; hovering
    replays it fast. Falls back to a static SVG if GSAP is missing or the user has
    prefers-reduced-motion. Safe to render the same illustration more than once on
    a page.
--}}
@props(['name'])

<div data-illo="{{ $name }}" {{ $attributes->merge(['class' => 'block']) }}>
    @switch($name)
        @case('gift')
            <svg viewBox="0 0 200 200" role="img" aria-label="Gift box" class="h-auto w-full">
                <title>Gift</title>
                <circle cx="100" cy="100" r="100" fill="#DBEAFE"/>
                <ellipse cx="100" cy="164" rx="46" ry="5" fill="#BFDBFE"/>
                <g id="igift-all">
                    <rect x="58" y="96" width="84" height="64" rx="6" fill="#BFDBFE" stroke="#0F172A" stroke-width="3"/>
                    <rect x="88" y="96" width="24" height="64" fill="#FFFFFF" stroke="#0F172A" stroke-width="3"/>
                    <rect x="50" y="76" width="100" height="24" rx="4" fill="#93C5FD" stroke="#0F172A" stroke-width="3"/>
                    <rect x="88" y="76" width="24" height="24" fill="#FFFFFF" stroke="#0F172A" stroke-width="3"/>
                    <path d="M100 76 C88 76 64 70 62 58 C60 46 74 40 84 48 C92 54 98 66 100 76 Z" fill="#FFFFFF" stroke="#0F172A" stroke-width="3" stroke-linejoin="round"/>
                    <path d="M100 76 C112 76 136 70 138 58 C140 46 126 40 116 48 C108 54 102 66 100 76 Z" fill="#FFFFFF" stroke="#0F172A" stroke-width="3" stroke-linejoin="round"/>
                    <path d="M78 54 C72 56 70 62 74 66" fill="none" stroke="#0F172A" stroke-width="2" stroke-linecap="round"/>
                    <path d="M122 54 C128 56 130 62 126 66" fill="none" stroke="#0F172A" stroke-width="2" stroke-linecap="round"/>
                    <rect x="94" y="68" width="12" height="10" rx="3" fill="#FFFFFF" stroke="#0F172A" stroke-width="3"/>
                    <g id="igift-tag">
                        <path d="M104 75 C116 84 127 96 134 114" fill="none" stroke="#0F172A" stroke-width="2.5"/>
                        <g transform="rotate(28 134 112)"><rect x="130" y="106" width="48" height="28" rx="6" fill="#FFFFFF" stroke="#0F172A" stroke-width="3"/><circle cx="138" cy="120" r="3" fill="#BFDBFE" stroke="#0F172A" stroke-width="2"/></g>
                    </g>
                </g>
                <path class="igift-spark" d="M44 56v10M39 61h10" stroke="#3B82F6" stroke-width="2.5" stroke-linecap="round"/>
                <circle class="igift-spark" cx="160" cy="52" r="3.5" fill="#3B82F6"/>
                <circle class="igift-spark" cx="50" cy="134" r="3" fill="#60A5FA"/>
            </svg>
            @break

        @case('globe')
            <svg viewBox="0 0 200 200" role="img" aria-label="Global coverage" class="h-auto w-full">
                <title>Global coverage</title>
                <circle cx="100" cy="100" r="100" fill="#EFF6FF"/>
                <g id="igc-globe">
                    <circle cx="95" cy="105" r="52" fill="#FFFFFF" stroke="#0F172A" stroke-width="3"/>
                    <path d="M58 92 C64 78 84 74 92 84 C100 80 108 88 102 96 C110 98 106 110 96 108 C88 116 66 112 64 102 C56 100 54 96 58 92 Z" fill="#93C5FD" stroke="#0F172A" stroke-width="2"/>
                    <path d="M104 124 C112 120 122 126 118 134 C110 138 102 132 104 124 Z" fill="#93C5FD" stroke="#0F172A" stroke-width="2"/>
                    <ellipse cx="74" cy="130" rx="8" ry="4" fill="#93C5FD" stroke="#0F172A" stroke-width="2"/>
                </g>
                <g transform="rotate(-18 100 105)"><path id="igc-orbit" d="M18 105 A82 30 0 1 1 182 105 A82 30 0 1 1 18 105" fill="none" stroke="#3B82F6" stroke-width="2" stroke-dasharray="5 7" stroke-linecap="round"/></g>
                <g id="igc-pin">
                    <path d="M70 26 C52 26 40 40 40 56 C40 74 70 96 70 96 C70 96 100 74 100 56 C100 40 88 26 70 26 Z" fill="#FFFFFF" stroke="#0F172A" stroke-width="3" stroke-linejoin="round"/>
                    <circle cx="70" cy="55" r="12" fill="#60A5FA" stroke="#0F172A" stroke-width="3"/>
                </g>
                <g id="igc-plane"><path d="M-11 -4 L11 0 L-7 6 L-4 1 Z" fill="#3B82F6" stroke="#0F172A" stroke-width="2" stroke-linejoin="round"/></g>
            </svg>
            @break

        @case('shield')
            <svg viewBox="0 0 200 200" role="img" aria-label="Secured users" class="h-auto w-full">
                <title>Secured users</title>
                <circle cx="100" cy="100" r="100" fill="#DBEAFE"/>
                <ellipse cx="106" cy="176" rx="44" ry="5" fill="#BFDBFE"/>
                <g id="ishield-shield">
                    <path d="M100 30 C118 44 142 52 158 56 C158 92 156 128 100 168 C44 128 42 92 42 56 C58 52 82 44 100 30 Z" fill="#FFFFFF" stroke="#0F172A" stroke-width="3" stroke-linejoin="round"/>
                    <path d="M100 44 C114 55 132 61 146 65 C146 94 143 122 100 154 C57 122 54 94 54 65 C68 61 86 55 100 44 Z" fill="#93C5FD" stroke="#0F172A" stroke-width="2.5" stroke-linejoin="round"/>
                    <path id="ishield-check" d="M76 96 L94 114 L128 78" fill="none" stroke="#FFFFFF" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/>
                </g>
                <g id="ishield-user">
                    <circle cx="144" cy="138" r="32" fill="#FFFFFF" stroke="#0F172A" stroke-width="3"/>
                    <clipPath id="ishield-clip"><circle cx="144" cy="138" r="30"/></clipPath>
                    <g clip-path="url(#ishield-clip)">
                        <path d="M116 164 C120 148 168 148 172 164 L172 172 L116 172 Z" fill="#93C5FD" stroke="#0F172A" stroke-width="2.5"/>
                        <circle cx="144" cy="130" r="10.5" fill="#BFDBFE" stroke="#0F172A" stroke-width="2.5"/>
                        <path d="M134 128 C136 120 152 120 154 128" fill="none" stroke="#0F172A" stroke-width="2.5"/>
                    </g>
                </g>
                <path class="ishield-spark" d="M40 44v10M35 49h10" stroke="#3B82F6" stroke-width="2.5" stroke-linecap="round"/>
                <circle class="ishield-spark" cx="166" cy="58" r="3.5" fill="#3B82F6"/>
            </svg>
            @break

        @case('search')
            <svg viewBox="0 0 200 200" role="img" aria-label="Search products" class="h-auto w-full">
                <title>Search products</title>
                <circle cx="100" cy="100" r="100" fill="#DBEAFE"/>
                <ellipse cx="96" cy="166" rx="40" ry="5" fill="#BFDBFE"/>
                <g class="isr-bag">
                    <path d="M126 70 C126 52 144 52 144 70" fill="none" stroke="#0F172A" stroke-width="3"/>
                    <path d="M118 70 L150 70 L158 142 L112 142 Z" fill="#93C5FD" stroke="#0F172A" stroke-width="3" stroke-linejoin="round"/>
                </g>
                <g class="isr-bag">
                    <path d="M66 78 C66 56 88 56 88 78" fill="none" stroke="#0F172A" stroke-width="3"/>
                    <path d="M80 78 C80 56 102 56 102 78" fill="none" stroke="#0F172A" stroke-width="3"/>
                    <path d="M52 78 L116 78 L124 156 L42 156 Z" fill="#FFFFFF" stroke="#0F172A" stroke-width="3" stroke-linejoin="round"/>
                    <circle cx="68" cy="84" r="4" fill="#BFDBFE" stroke="#0F172A" stroke-width="2.5"/>
                    <circle cx="100" cy="84" r="4" fill="#BFDBFE" stroke="#0F172A" stroke-width="2.5"/>
                </g>
                <g id="isr-glass">
                    <path d="M142 142 L170 170" stroke="#0F172A" stroke-width="14" stroke-linecap="round"/>
                    <path d="M142 142 L170 170" stroke="#60A5FA" stroke-width="7" stroke-linecap="round"/>
                    <circle cx="118" cy="118" r="36" fill="#FFFFFF" stroke="#0F172A" stroke-width="3"/>
                    <circle cx="118" cy="118" r="28" fill="#BFDBFE" stroke="#0F172A" stroke-width="2"/>
                    <path d="M102 134 L120 112 L138 134 Z" fill="#93C5FD" stroke="#0F172A" stroke-width="2.5" stroke-linejoin="round"/>
                    <path id="isr-glint" d="M104 100 L114 92" stroke="#FFFFFF" stroke-width="4" stroke-linecap="round"/>
                </g>
            </svg>
            @break

        @case('notFound')
            <svg viewBox="0 0 420 200" role="img" aria-label="404, page not found" class="h-auto w-full">
                <title>404</title>
                <g stroke="#0F172A" opacity="0.35" stroke-width="2.5" stroke-linecap="round"><path d="M40 194h24"/><path d="M140 196h16"/><path d="M236 194h26"/><path d="M330 196h20"/></g>
                <g class="i404-char">
                    <path d="M88 160 L85 182 L76 184" fill="none" stroke="#0F172A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M108 160 L110 182 L119 184" fill="none" stroke="#0F172A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M82 50 L116 50 L116 112 L128 112 L128 132 L116 132 L116 160 L94 160 L94 132 L42 132 L42 116 Z" fill="#3B82F6" stroke="#0F172A" stroke-width="3" stroke-linejoin="round"/>
                    <circle cx="100" cy="74" r="2" fill="#0F172A"/>
                    <path d="M70 16 Q78 12 86 16" fill="none" stroke="#0F172A" stroke-width="2.5" stroke-linecap="round"/>
                    <path d="M99 13 Q107 9 115 13" fill="none" stroke="#0F172A" stroke-width="2.5" stroke-linecap="round"/>
                    <g class="i404-eye"><circle cx="78" cy="30" r="7.5" fill="#FFFFFF" stroke="#0F172A" stroke-width="2.5"/><circle class="i404-pupil" cx="80" cy="31" r="2.6" fill="#0F172A"/></g>
                    <g class="i404-eye"><circle cx="106" cy="30" r="7.5" fill="#FFFFFF" stroke="#0F172A" stroke-width="2.5"/><circle class="i404-pupil" cx="108" cy="31" r="2.6" fill="#0F172A"/></g>
                </g>
                <g class="i404-char">
                    <path d="M198 167 L196 186 L188 188" fill="none" stroke="#0F172A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M222 167 L224 186 L232 188" fill="none" stroke="#0F172A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <ellipse cx="210" cy="112" rx="46" ry="56" fill="#BFDBFE" stroke="#0F172A" stroke-width="3"/>
                    <ellipse cx="210" cy="112" rx="24" ry="34" fill="#FFFFFF" stroke="#0F172A" stroke-width="3"/>
                    <path d="M166 84 C155 76 151 64 156 53" fill="none" stroke="#0F172A" stroke-width="3" stroke-linecap="round"/>
                    <path d="M254 84 C265 76 269 64 264 53" fill="none" stroke="#0F172A" stroke-width="3" stroke-linecap="round"/>
                    <path d="M187 16 L198 19" stroke="#0F172A" stroke-width="2.5" stroke-linecap="round"/>
                    <path d="M233 16 L222 19" stroke="#0F172A" stroke-width="2.5" stroke-linecap="round"/>
                    <path d="M202 44 Q210 38 218 44" fill="none" stroke="#0F172A" stroke-width="3" stroke-linecap="round"/>
                    <g class="i404-eye"><circle cx="194" cy="30" r="7.5" fill="#FFFFFF" stroke="#0F172A" stroke-width="2.5"/><circle class="i404-pupil" cx="194" cy="32" r="2.6" fill="#0F172A"/></g>
                    <g class="i404-eye"><circle cx="226" cy="30" r="7.5" fill="#FFFFFF" stroke="#0F172A" stroke-width="2.5"/><circle class="i404-pupil" cx="226" cy="32" r="2.6" fill="#0F172A"/></g>
                </g>
                <g class="i404-char">
                    <g transform="translate(252 0)">
                        <path d="M88 160 L85 182 L76 184" fill="none" stroke="#0F172A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M108 160 L110 182 L119 184" fill="none" stroke="#0F172A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M82 50 L116 50 L116 112 L128 112 L128 132 L116 132 L116 160 L94 160 L94 132 L42 132 L42 116 Z" fill="#3B82F6" stroke="#0F172A" stroke-width="3" stroke-linejoin="round"/>
                        <ellipse cx="112" cy="11" rx="8" ry="3.5" fill="none" stroke="#60A5FA" stroke-width="2.5"/>
                        <path d="M64 14 Q72 10 80 14" fill="none" stroke="#0F172A" stroke-width="2.5" stroke-linecap="round"/>
                        <g class="i404-eye"><circle cx="74" cy="30" r="7.5" fill="#FFFFFF" stroke="#0F172A" stroke-width="2.5"/><circle class="i404-pupil" cx="72" cy="31" r="2.6" fill="#0F172A"/></g>
                        <path d="M98 31 q7 5 14 0" fill="none" stroke="#0F172A" stroke-width="2.5" stroke-linecap="round"/>
                    </g>
                </g>
            </svg>
            @break

        @case('emptyCart')
            <svg viewBox="0 0 520 400" role="img" aria-label="Empty cart illustration" class="h-auto w-full">
                <title>Your cart is empty</title>
                <rect width="520" height="400" rx="24" fill="#0E1626"/>
                <g fill="#FFFFFF" opacity="0.28">
                    <circle cx="70" cy="56" r="2"/><circle cx="150" cy="34" r="1.5"/><circle cx="420" cy="52" r="2"/>
                    <circle cx="478" cy="142" r="1.5"/><circle cx="255" cy="38" r="1.5"/>
                </g>
                <g stroke="#2A3A5C" stroke-width="3" stroke-linecap="round">
                    <path d="M70 312h18"/><path d="M152 322h12"/><path d="M298 336h12"/><path d="M468 300h10"/>
                </g>
                <g fill="#1D4ED8">
                    <path d="M48 302 C44 288 50 276 57 270 C58 282 56 293 53 302 Z"/>
                    <path d="M58 302 C57 288 63 279 70 275 C68 286 65 295 62 302 Z"/>
                    <path d="M118 302 C115 290 120 280 127 276 C126 287 124 295 122 302 Z"/>
                    <path d="M128 302 C128 290 134 282 141 279 C138 289 134 296 131 302 Z"/>
                </g>
                <g id="ec-cactus">
                    <path d="M79 302 C74 262 72 204 80 174 C85 157 105 156 110 172 C117 198 114 262 110 302 Z" fill="#3B82F6"/>
                    <path d="M79 244 C60 240 49 226 51 203 C52 190 66 189 67 201 C69 214 71 224 81 228 Z" fill="#3B82F6"/>
                    <path d="M110 222 C129 218 139 202 137 180 C136 167 122 166 121 178 C119 192 118 204 108 208 Z" fill="#3B82F6"/>
                    <g stroke="#BFDBFE" stroke-width="2" stroke-linecap="round">
                        <path d="M88 195l-7 -3"/><path d="M101 215l7 -3"/><path d="M90 250l-7 -3"/><path d="M102 275l7 -3"/>
                        <path d="M58 214l-6 -4"/><path d="M129 190l6 -4"/><path d="M95 172l-2 -7"/>
                    </g>
                    <path d="M94 162 C88 158 86 150 90 144 C92 150 94 152 94 146 C95 151 97 150 99 144 C103 150 100 158 94 162 Z" fill="#FFFFFF"/>
                </g>
                <g id="ec-tumbleweed" fill="none" stroke="#BFDBFE" stroke-linecap="round">
                    <path d="M402 318 C398 297 426 285 445 295 C462 281 486 299 474 315 C487 329 463 344 446 336 C432 351 404 341 402 318 Z" stroke-width="3"/>
                    <path d="M417 312 C425 299 446 301 453 315" stroke-width="2.5"/>
                    <path d="M414 327 C428 338 450 333 459 319" stroke-width="2.5"/>
                    <path d="M432 304 C439 312 437 323 428 330" stroke-width="2.5"/>
                </g>
                <g id="ec-cart">
                    <g class="ec-bag">
                        <path d="M232 188 L266 188 L270 244 L228 244 Z" fill="#60A5FA"/>
                        <path d="M240 188 C240 174 258 174 258 188" stroke="#60A5FA" stroke-width="4.5" fill="none"/>
                    </g>
                    <g class="ec-bag">
                        <path d="M288 180 L322 180 L328 246 L283 246 Z" fill="#FFFFFF"/>
                        <path d="M296 180 C296 166 314 166 314 180" stroke="#FFFFFF" stroke-width="4.5" fill="none"/>
                    </g>
                    <g class="ec-bag">
                        <path d="M250 196 L286 196 L291 248 L246 248 Z" fill="#1D4ED8"/>
                        <path d="M259 196 C259 181 277 181 277 196" stroke="#1D4ED8" stroke-width="4.5" fill="none"/>
                    </g>
                    <g fill="none" stroke="#FFFFFF" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M223 216 L345 271" stroke-width="2.5" opacity="0.75"/>
                        <path d="M355 216 L233 271" stroke-width="2.5" opacity="0.75"/>
                        <path d="M220 243 C262 247 318 247 358 243" stroke-width="2.5" opacity="0.75"/>
                        <path d="M216 210 C258 205 322 205 362 210 L350 276 C312 281 266 281 228 276 Z" stroke-width="5"/>
                        <path d="M218 211 C202 199 192 191 180 186" stroke-width="5"/>
                        <path d="M240 278 L237 305" stroke-width="5"/>
                        <path d="M340 278 L337 305" stroke-width="5"/>
                    </g>
                    <circle cx="177" cy="184" r="4.5" fill="#FFFFFF"/>
                    <g id="ec-wheel-1" stroke="#FFFFFF" fill="none" stroke-linecap="round">
                        <circle cx="236" cy="316" r="10" stroke-width="5"/>
                        <path d="M236 309v14M229 316h14" stroke-width="2.5"/>
                    </g>
                    <g id="ec-wheel-2" stroke="#FFFFFF" fill="none" stroke-linecap="round">
                        <circle cx="336" cy="316" r="10" stroke-width="5"/>
                        <path d="M336 309v14M329 316h14" stroke-width="2.5"/>
                    </g>
                </g>
                <g id="ec-bubble">
                    <path d="M295 139 C293 151 287 158 276 164 C289 160 300 154 305 140 Z" fill="#93C5FD"/>
                    <path d="M264 106 C264 83 283 72 304 72 C327 72 346 85 344 109 C342 131 324 141 302 141 C281 141 264 129 264 106 Z" fill="#93C5FD"/>
                    <circle cx="289" cy="100" r="3.5" fill="#0E1626"/>
                    <circle cx="319" cy="100" r="3.5" fill="#0E1626"/>
                    <path d="M283 91 Q289 87 295 89" stroke="#0E1626" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                    <path d="M313 89 Q319 87 325 91" stroke="#0E1626" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                    <path d="M289 121 Q304 110 319 121" stroke="#0E1626" stroke-width="4" fill="none" stroke-linecap="round"/>
                </g>
            </svg>
            @break

        @case('mobile')
            <svg viewBox="0 0 200 200" role="img" aria-label="Works on every device" class="h-auto w-full">
                <title>Mobile compatibility</title>
                <circle cx="100" cy="100" r="100" fill="#DBEAFE"/>
                <ellipse cx="100" cy="172" rx="46" ry="5" fill="#BFDBFE"/>
                <g id="imob-phone">
                    <g transform="rotate(-14 80 118)">
                        <rect x="53" y="70" width="54" height="96" rx="10" fill="#FFFFFF" stroke="#0F172A" stroke-width="3"/>
                        <rect x="60" y="84" width="40" height="66" rx="4" fill="#EFF6FF" stroke="#0F172A" stroke-width="2"/>
                        <path d="M72 77h16" stroke="#0F172A" stroke-width="2.5" stroke-linecap="round"/>
                        <path d="M72 159h16" stroke="#0F172A" stroke-width="2.5" stroke-linecap="round"/>
                        <rect class="imob-ui" x="64" y="90" width="32" height="5" rx="2.5" fill="#93C5FD"/>
                        <rect class="imob-ui" x="64" y="100" width="24" height="5" rx="2.5" fill="#BFDBFE"/>
                        <rect class="imob-ui" x="64" y="110" width="28" height="5" rx="2.5" fill="#BFDBFE"/>
                        <g class="imob-ui">
                            <rect x="64" y="120" width="32" height="18" rx="3" fill="#2563EB"/>
                            <circle cx="71" cy="129" r="3" fill="#FFFFFF"/>
                            <rect x="77" y="126" width="13" height="4" rx="2" fill="#FFFFFF" opacity="0.75"/>
                        </g>
                    </g>
                </g>
                <g class="imob-trail" stroke="#2563EB" stroke-width="2.5" stroke-linecap="round"><path d="M164 74h12"/></g>
                <g class="imob-trail" stroke="#2563EB" stroke-width="2.5" stroke-linecap="round"><path d="M166 84h16"/></g>
                <g class="imob-trail" stroke="#2563EB" stroke-width="2.5" stroke-linecap="round"><path d="M164 94h10"/></g>
                <g id="imob-gift">
                    <g transform="rotate(10 142 86)">
                        <path d="M142 70 C137 70 130 67 130 62 C130 58 135 57 138 60 C140 62 141 66 142 70 Z" fill="#FFFFFF" stroke="#0F172A" stroke-width="2.5" stroke-linejoin="round"/>
                        <path d="M142 70 C147 70 154 67 154 62 C154 58 149 57 146 60 C144 62 143 66 142 70 Z" fill="#FFFFFF" stroke="#0F172A" stroke-width="2.5" stroke-linejoin="round"/>
                        <rect x="126" y="78" width="32" height="24" rx="3" fill="#93C5FD" stroke="#0F172A" stroke-width="2.5"/>
                        <rect x="138" y="78" width="8" height="24" fill="#FFFFFF" stroke="#0F172A" stroke-width="2.5"/>
                        <rect x="123" y="70" width="38" height="10" rx="2" fill="#BFDBFE" stroke="#0F172A" stroke-width="2.5"/>
                        <rect x="138" y="70" width="8" height="10" fill="#FFFFFF" stroke="#0F172A" stroke-width="2.5"/>
                    </g>
                </g>
                <path class="imob-star" d="M56 42 C57.5 47 59.5 49 64 50.5 C59.5 52 57.5 54 56 59 C54.5 54 52.5 52 48 50.5 C52.5 49 54.5 47 56 42 Z" fill="#FFFFFF" stroke="#0F172A" stroke-width="2.5"/>
                <path class="imob-star" d="M158 121 C159 125 161 126.5 164.5 127.5 C161 128.5 159 130 158 134 C157 130 155 128.5 151.5 127.5 C155 126.5 157 125 158 121 Z" fill="#FFFFFF" stroke="#0F172A" stroke-width="2.5"/>
                <circle class="imob-star" cx="148" cy="46" r="2.5" fill="#2563EB"/>
                <circle class="imob-star" cx="44" cy="118" r="3" fill="#60A5FA"/>
                <circle class="imob-star" cx="50" cy="86" r="2" fill="#60A5FA"/>
            </svg>
            @break

        @case('cardFan')
            {{-- The fan is the catalogue: real gift card art, top-up operator
                 logos and eSIM cards, the same artwork the home rows show.

                 Gift cards come from the region's curated Popular row and
                 top-ups from the region's own operators first, both limited to
                 brands with a hand-made asset in config/brand_assets.php (the
                 synced logo_url is sometimes a grey placeholder). eSIM cards
                 reuse the eSIM row's own data: region colour and flags, with a
                 SIM chip in place of words, because the cards float in the idle
                 loop and moving text shimmers.

                 Cached per region: this renders on every home page view. With
                 no catalogue at all (a fresh install) the original abstract fan
                 below is drawn instead. .gc-card and .gc-spark are unchanged,
                 so the GSAP entrance and float in app.js still apply. --}}
            @php
                $fanRegion = strtoupper((string) (request()->attributes->get('region') ?: 'US'));

                $fanArt = \Illuminate\Support\Facades\Cache::remember("illo:card-fan:v1:{$fanRegion}", now()->addMinutes(30), function () use ($fanRegion) {
                    $localLogos = (array) config('brand_assets.logos', []);
                    $giftCategoryId = \App\Models\Category::where('slug', 'gift-cards')->value('id');

                    $gifts = \App\Models\Product::popularForRegion($fanRegion)
                        ->filter(fn ($p) => $giftCategoryId && (int) $p->category_id === (int) $giftCategoryId && isset($localLogos[$p->brand_key]))
                        ->unique('brand_key')
                        ->take(5)
                        ->map(fn ($p) => ['kind' => 'art', 'label' => \App\Models\Product::brandDisplayName($p->brand_key), 'file' => $localLogos[$p->brand_key]])
                        ->values()
                        ->all();

                    $topups = \App\Models\Product::query()
                        ->where('is_active', true)
                        ->whereIn('brand_key', array_keys($localLogos))
                        ->whereHas('category', fn ($q) => $q->where('slug', 'mobile-airtime'))
                        ->orderByRaw('country_code = ? DESC', [$fanRegion])
                        ->get(['brand_key', 'country_code'])
                        ->unique('brand_key')
                        ->take(3)
                        ->map(fn ($p) => ['kind' => 'art', 'label' => \App\Models\Product::brandDisplayName($p->brand_key), 'file' => $localLogos[$p->brand_key]])
                        ->values()
                        ->all();

                    $esims = \App\Support\EsimCardPresenter::family()
                        ->take(2)
                        ->map(fn (array $c) => [
                            'kind' => 'esim',
                            'label' => str_contains($c['name'], 'eSIM') ? $c['name'] : $c['name'].' eSIM',
                            'accent' => $c['accentColor'],
                            'flags' => array_slice($c['flagUrls'] ?? [], 0, 3),
                        ])
                        ->values()
                        ->all();

                    return ['gifts' => $gifts, 'topups' => $topups, 'esims' => $esims];
                });

                // Front card first, then the fan; extras only fill in when a
                // kind runs short, so a region with no eSIMs still gets seven.
                $fanItems = array_slice(array_values(array_filter([
                    $fanArt['gifts'][0] ?? null,
                    $fanArt['esims'][0] ?? null,
                    $fanArt['topups'][0] ?? null,
                    $fanArt['gifts'][1] ?? null,
                    $fanArt['esims'][1] ?? null,
                    $fanArt['topups'][1] ?? null,
                    $fanArt['gifts'][2] ?? null,
                    $fanArt['gifts'][3] ?? null,
                    $fanArt['topups'][2] ?? null,
                    $fanArt['gifts'][4] ?? null,
                ])), 0, 7);

                // The original fan's geometry, in paint order (back to front):
                // x, y, w, h, radius, angle, pivot x, pivot y. The last is the
                // front card and takes the first item.
                $fanSlots = [
                    [56, 50, 88, 56, 8, -20, 100, 78],
                    [178, 50, 88, 56, 8, 16, 222, 78],
                    [34, 112, 88, 56, 8, -28, 78, 140],
                    [200, 114, 88, 56, 8, 22, 244, 142],
                    [66, 168, 88, 56, 8, -12, 110, 196],
                    [172, 170, 88, 56, 8, 14, 216, 198],
                    [113, 110, 96, 60, 9, -3, 161, 140],
                ];
                $fanPlain = ['#1E3A8A', '#2563EB', '#93C5FD', '#FFFFFF', '#1D4ED8', '#60A5FA', '#FFFFFF'];

                // Unique per render: the clip ids must not collide if the fan
                // appears twice on a page.
                $fanUid = 'gcf'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6));

                $fanLabels = array_slice(array_column($fanItems, 'label'), 0, 4);
                $fanAria = 'Gift cards, mobile top-ups and eSIMs'.($fanLabels ? ', including '.\Illuminate\Support\Arr::join($fanLabels, ', ', ' and ') : '');
            @endphp
            @if ($fanItems)
            <svg viewBox="0 0 320 260" role="img" aria-label="{{ $fanAria }}" preserveAspectRatio="xMidYMid slice" class="h-full w-full">
                <title>Choose a gift card, top-up or eSIM</title>
                <rect width="320" height="260" rx="18" fill="#0E1626"/>
                <path class="gc-spark" d="M52 56v10M47 61h10" stroke="#60A5FA" stroke-width="2.5" stroke-linecap="round"/>
                <circle class="gc-spark" cx="272" cy="208" r="3" fill="#2563EB"/>
                <circle class="gc-spark" cx="282" cy="62" r="2.5" fill="#60A5FA"/>
                @foreach ($fanSlots as $slot => [$x, $y, $w, $h, $rx, $angle, $px, $py])
                    @php $item = $fanItems[($slot + 1) % 7] ?? null; @endphp
                    <g class="gc-card" data-fan-kind="{{ $item['kind'] ?? 'plain' }}" transform="rotate({{ $angle }} {{ $px }} {{ $py }})">
                        @if (($item['kind'] ?? null) === 'art')
                            <clipPath id="{{ $fanUid }}-{{ $slot }}"><rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}" rx="{{ $rx }}"/></clipPath>
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}" rx="{{ $rx }}" fill="#FFFFFF"/>
                            <image href="{{ asset('assets/'.rawurlencode($item['file'])) }}" x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}" preserveAspectRatio="xMidYMid slice" clip-path="url(#{{ $fanUid }}-{{ $slot }})"/>
                        @elseif (($item['kind'] ?? null) === 'esim')
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}" rx="{{ $rx }}" fill="{{ $item['accent'] }}"/>
                            @foreach ($item['flags'] as $flag => $flagUrl)
                                @php $fx = $x + 16 + $flag * 12; $fy = $y + 16; @endphp
                                <clipPath id="{{ $fanUid }}-{{ $slot }}-f{{ $flag }}"><circle cx="{{ $fx }}" cy="{{ $fy }}" r="8"/></clipPath>
                                <circle cx="{{ $fx }}" cy="{{ $fy }}" r="9.5" fill="#FFFFFF"/>
                                <image href="{{ $flagUrl }}" x="{{ $fx - 12 }}" y="{{ $fy - 8 }}" width="24" height="16" preserveAspectRatio="xMidYMid slice" clip-path="url(#{{ $fanUid }}-{{ $slot }}-f{{ $flag }})"/>
                            @endforeach
                            {{-- SIM chip: a card with one clipped corner and two contacts. --}}
                            @php $sx = $x + $w - 24; $sy = $y + $h - 25; @endphp
                            <path d="M{{ $sx + 1.5 }} {{ $sy }}h7.5l5 5v11.5a1.5 1.5 0 0 1-1.5 1.5h-11a1.5 1.5 0 0 1-1.5-1.5v-15a1.5 1.5 0 0 1 1.5-1.5z" fill="#FFFFFF" opacity="0.92"/>
                            <path d="M{{ $sx + 4 }} {{ $sy + 8 }}h6M{{ $sx + 4 }} {{ $sy + 12 }}h6" stroke="{{ $item['accent'] }}" stroke-width="1.4" stroke-linecap="round"/>
                        @else
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}" rx="{{ $rx }}" fill="{{ $fanPlain[$slot] }}"/>
                        @endif
                    </g>
                @endforeach
            </svg>
            @else
            <svg viewBox="0 0 320 260" role="img" aria-label="Choose your gift card" preserveAspectRatio="xMidYMid slice" class="h-full w-full">
                <title>Choose your gift card</title>
                <rect width="320" height="260" rx="18" fill="#0E1626"/>
                <path class="gc-spark" d="M52 56v10M47 61h10" stroke="#60A5FA" stroke-width="2.5" stroke-linecap="round"/>
                <circle class="gc-spark" cx="272" cy="208" r="3" fill="#2563EB"/>
                <circle class="gc-spark" cx="282" cy="62" r="2.5" fill="#60A5FA"/>
                <g class="gc-card" transform="rotate(-20 100 78)"><rect x="56" y="50" width="88" height="56" rx="8" fill="#1E3A8A"/><circle cx="78" cy="72" r="9" fill="#60A5FA"/><rect x="94" y="66" width="34" height="5" rx="2.5" fill="#FFFFFF" opacity="0.55"/><rect x="94" y="76" width="24" height="5" rx="2.5" fill="#FFFFFF" opacity="0.35"/></g>
                <g class="gc-card" transform="rotate(16 222 78)"><rect x="178" y="50" width="88" height="56" rx="8" fill="#2563EB"/><path d="M196 84 L206 64 L216 84 Z" fill="#FFFFFF"/><rect x="222" y="68" width="32" height="5" rx="2.5" fill="#FFFFFF" opacity="0.6"/><rect x="222" y="78" width="22" height="5" rx="2.5" fill="#FFFFFF" opacity="0.4"/></g>
                <g class="gc-card" transform="rotate(-28 78 140)"><rect x="34" y="112" width="88" height="56" rx="8" fill="#93C5FD"/><path d="M58 128l4 9 9 4-9 4-4 9-4-9-9-4 9-4z" fill="#0F172A"/><rect x="78" y="130" width="30" height="5" rx="2.5" fill="#0F172A" opacity="0.7"/><rect x="78" y="140" width="20" height="5" rx="2.5" fill="#0F172A" opacity="0.45"/></g>
                <g class="gc-card" transform="rotate(22 244 142)"><rect x="200" y="114" width="88" height="56" rx="8" fill="#FFFFFF"/><path d="M222 128l12 7v14l-12 7-12-7v-14z" fill="#2563EB"/><rect x="244" y="132" width="30" height="5" rx="2.5" fill="#0F172A" opacity="0.6"/><rect x="244" y="142" width="20" height="5" rx="2.5" fill="#0F172A" opacity="0.35"/></g>
                <g class="gc-card" transform="rotate(-12 110 196)"><rect x="66" y="168" width="88" height="56" rx="8" fill="#1D4ED8"/><path d="M92 180l-8 16h8l-6 16 16-20h-9l7-12z" fill="#FFFFFF"/><rect x="110" y="186" width="28" height="5" rx="2.5" fill="#FFFFFF" opacity="0.6"/><rect x="110" y="196" width="18" height="5" rx="2.5" fill="#FFFFFF" opacity="0.4"/></g>
                <g class="gc-card" transform="rotate(14 216 198)"><rect x="172" y="170" width="88" height="56" rx="8" fill="#60A5FA"/><circle cx="194" cy="198" r="9" fill="none" stroke="#0F172A" stroke-width="4"/><rect x="212" y="188" width="30" height="5" rx="2.5" fill="#0F172A" opacity="0.7"/><rect x="212" y="198" width="20" height="5" rx="2.5" fill="#0F172A" opacity="0.45"/></g>
                <g class="gc-card" transform="rotate(-3 161 140)"><rect x="113" y="110" width="96" height="60" rx="9" fill="#FFFFFF"/><path d="M141 140l12-14 12 14-12 14z" fill="#2563EB"/><rect x="168" y="128" width="32" height="6" rx="3" fill="#0F172A" opacity="0.85"/><rect x="168" y="140" width="24" height="5" rx="2.5" fill="#0F172A" opacity="0.45"/><rect x="125" y="158" width="70" height="4" rx="2" fill="#0F172A" opacity="0.25"/></g>
            </svg>
            @endif
            @break

        @case('payWeb')
            {{-- The hub is checkout; every node it links to is a payment mark
                 from the "Payment Methods We Accept" strip, read from the same
                 PaymentStripBadges list. So the picture can never show a rail or
                 coin checkout does not take: a coin the gateway stops accepting
                 drops out of both at once.

                 Owned logo files (Visa, Mastercard, MoMo, the coin icons) load
                 as SVG <image>, which the dark-mode img invert never matches.
                 The rest are the strip's own inline marks (payment-mark), with
                 the same path data drawn at node size.

                 Positions, .ipay-node / .ipay-link / .ipay-dot and #ipay-hub are
                 unchanged, so the GSAP entrance and the dots running along each
                 link in app.js still line up with the nodes. --}}
            @php
                $payBadges = collect(\App\Domain\Payment\Support\PaymentStripBadges::all());
                $payBadge = fn (string $key) => $payBadges->first(fn ($b) => ($b['svg'] ?? $b['img'] ?? null) === $key);
                $payCoins = $payBadges->where('method', 'crypto')->values();

                // Eight circles, filled in this order. The tail entries only
                // appear if something earlier is missing, so a thin coin lineup
                // never leaves an empty node.
                $payCircles = array_slice(array_values(array_filter([
                    $payBadge('mtn_momo'),
                    $payCoins[0] ?? null,
                    $payBadge('orange_money'),
                    $payBadge('bank'),
                    $payBadge('apple_pay'),
                    $payCoins[1] ?? null,
                    $payBadge('ussd'),
                    $payBadge('bank_qr'),
                    $payBadge('airtel_money'),
                    $payBadge('mobile_wallet'),
                    $payBadge('bank_online'),
                ])), 0, 8);

                $payCards = array_values(array_filter([$payBadge('visa.svg'), $payBadge('mastercard.svg')]));

                $payPositions = [[70, 52], [152, 36], [236, 52], [262, 120], [248, 196], [168, 222], [60, 222], [44, 128]];

                $payLabels = collect($payCards)->concat($payCircles)->pluck('label')->unique()->values()->all();
                $payAria = $payLabels ? 'Pay with '.\Illuminate\Support\Arr::join($payLabels, ', ', ' or ') : 'Pay your way';
            @endphp
            <svg viewBox="0 0 320 260" role="img" aria-label="{{ $payAria }}" preserveAspectRatio="xMidYMid slice" class="h-full w-full">
                <title>Pay your way</title>
                <rect width="320" height="260" rx="18" fill="#0E1626"/>
                <g fill="none" stroke="#2563EB" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path class="ipay-link" d="M150 102 L150 78 L86 78 L86 69"/>
                    <path class="ipay-link" d="M160 102 L160 72 L152 72 L152 53"/>
                    <path class="ipay-link" d="M176 102 L176 80 L236 80 L236 69"/>
                    <path class="ipay-link" d="M188 122 L245 122"/>
                    <path class="ipay-link" d="M188 146 L214 146 L214 190 L231 190"/>
                    <path class="ipay-link" d="M168 158 L168 205"/>
                    <path class="ipay-link" d="M140 158 L140 200 L60 200 L60 205"/>
                    <path class="ipay-link" d="M132 130 L61 130"/>
                    <path class="ipay-link" d="M132 146 L110 146 L110 158"/>
                </g>
                @foreach ($payPositions as $payIndex => [$cx, $cy])
                    @php $mark = $payCircles[$payIndex] ?? null; @endphp
                    <g class="ipay-node" data-pay-mark="{{ $mark['svg'] ?? ($mark ? 'coin' : 'none') }}">
                        @switch($mark['svg'] ?? ($mark ? 'coin' : null))
                            @case('mtn_momo')
                                {{-- The strip's MoMo tile: the owned PNG on its #004f70 card. --}}
                                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="15" fill="#004F70"/>
                                <image href="{{ asset('assets/'.rawurlencode('MTN MOMO.png')) }}" x="{{ $cx - 13 }}" y="{{ $cy - 13 }}" width="26" height="26" preserveAspectRatio="xMidYMid meet"/>
                                @break
                            @case('coin')
                                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="15" fill="#FFFFFF"/>
                                <image href="{{ asset('assets/'.rawurlencode($mark['img'])) }}" x="{{ $cx - 11 }}" y="{{ $cy - 11 }}" width="22" height="22" preserveAspectRatio="xMidYMid meet"/>
                                @break
                            @case('orange_money')
                                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="15" fill="#FFFFFF"/>
                                <svg x="{{ $cx - 9 }}" y="{{ $cy - 9 }}" width="18" height="18" viewBox="0 0 30 30"><rect width="30" height="30" fill="#FF7900"/><rect x="4" y="22" width="22" height="4" fill="#ffffff"/></svg>
                                @break
                            @case('apple_pay')
                                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="15" fill="#FFFFFF"/>
                                <svg x="{{ $cx - 10 }}" y="{{ $cy - 11 }}" width="20" height="20" viewBox="0 0 24 24" fill="#0F172A"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>
                                @break
                            @case('bank')
                                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="15" fill="#FFFFFF"/>
                                <svg x="{{ $cx - 10 }}" y="{{ $cy - 10 }}" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0F172A" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3.25 9.5 12 4.25l8.75 5.25"/><path d="M5.5 9.75v8M9.833 9.75v8M14.167 9.75v8M18.5 9.75v8"/><path d="M3 20.25h18"/></svg>
                                @break
                            @case('ussd')
                                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="15" fill="#FFFFFF"/>
                                <svg x="{{ $cx - 10 }}" y="{{ $cy - 10 }}" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0F172A" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="4.25" y="2.25" width="15.5" height="19.5" rx="3"/><path d="M8.5 7.5h.01M12 7.5h.01M15.5 7.5h.01M8.5 11h.01M12 11h.01M15.5 11h.01M8.5 14.5h.01M12 14.5h.01M15.5 14.5h.01" stroke-width="2.3"/><path d="M10.25 18.5h3.5"/></svg>
                                @break
                            @case('bank_qr')
                                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="15" fill="#FFFFFF"/>
                                <svg x="{{ $cx - 10 }}" y="{{ $cy - 10 }}" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0F172A" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><path d="M6.5 6.5h.01M17.5 6.5h.01M6.5 17.5h.01" stroke-width="2.3"/><path d="M14 14h3v3h-3z"/><path d="M20.5 14h.01M14 20.5h.01M17.5 20.5h.01M20.5 17.5h.01M20.5 20.5h.01" stroke-width="2.3"/></svg>
                                @break
                            @case('airtel_money')
                                <svg x="{{ $cx - 15 }}" y="{{ $cy - 15 }}" width="30" height="30" viewBox="0 0 28 28"><circle cx="14" cy="14" r="14" fill="#E40000"/><path d="M14 5 A9 9 0 0 1 23 14 L19.5 14 A5.5 5.5 0 0 0 14 8.5 Z" fill="#ffffff"/></svg>
                                @break
                            @case('mobile_wallet')
                                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="15" fill="#FFFFFF"/>
                                <svg x="{{ $cx - 10 }}" y="{{ $cy - 10 }}" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0F172A" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.75A2.75 2.75 0 0 1 5.75 6H18a3 3 0 0 1 3 3v7.5a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z"/><path d="M3 8.75V7.5A2.5 2.5 0 0 1 5.5 5h9.75"/><path d="M21 11.5h-3.25a1.75 1.75 0 0 0 0 3.5H21"/><path d="M17.9 13.25h.01" stroke-width="2.3"/></svg>
                                @break
                            @case('bank_online')
                                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="15" fill="#FFFFFF"/>
                                <svg x="{{ $cx - 10 }}" y="{{ $cy - 10 }}" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0F172A" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="3.75" width="19" height="16.5" rx="2.5"/><path d="M2.5 8.25h19"/><path d="M5.5 5.9h.01M8 5.9h.01"/><path d="M8 15.75 12 13.25l4 2.5"/><path d="M9.25 16v1.75M12 16v1.75M14.75 16v1.75"/></svg>
                                @break
                            @default
                                {{-- Nothing left to show: a plain node rather than a gap in the web. --}}
                                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="15" fill="#1E3A8A"/>
                        @endswitch
                    </g>
                @endforeach

                {{-- Card networks: the strip's Visa and Mastercard files side by side on a white card. --}}
                <g class="ipay-node" data-pay-mark="cards">
                    <rect x="84" y="160" width="52" height="34" rx="8" fill="#FFFFFF"/>
                    @foreach ($payCards as $cardIndex => $card)
                        <image href="{{ asset('assets/'.rawurlencode($card['img'])) }}" x="{{ count($payCards) === 1 ? 97 : 87 + $cardIndex * 24 }}" y="167" width="{{ count($payCards) === 1 ? 26 : 22 }}" height="20" preserveAspectRatio="xMidYMid meet"/>
                    @endforeach
                </g>
                <g id="ipay-hub"><rect x="132" y="102" width="56" height="56" rx="14" fill="#0F172A" stroke="#2563EB" stroke-width="2"/><circle cx="146" cy="117" r="5" fill="none" stroke="#FFFFFF" stroke-width="3"/><rect x="158" y="112" width="10" height="10" rx="2" fill="#FFFFFF"/><circle cx="174" cy="117" r="5" fill="#2563EB"/><circle cx="147" cy="140" r="5" fill="#FFFFFF"/><rect x="159" y="135" width="10" height="10" rx="2" fill="none" stroke="#FFFFFF" stroke-width="2.5"/><circle cx="174" cy="140" r="4" fill="#60A5FA"/></g>
                <g fill="#60A5FA"><circle class="ipay-dot" cx="160" cy="130" r="2.5"/><circle class="ipay-dot" cx="160" cy="130" r="2.5"/><circle class="ipay-dot" cx="160" cy="130" r="2.5"/><circle class="ipay-dot" cx="160" cy="130" r="2.5"/><circle class="ipay-dot" cx="160" cy="130" r="2.5"/><circle class="ipay-dot" cx="160" cy="130" r="2.5"/><circle class="ipay-dot" cx="160" cy="130" r="2.5"/><circle class="ipay-dot" cx="160" cy="130" r="2.5"/><circle class="ipay-dot" cx="160" cy="130" r="2.5"/></g>
            </svg>
            @break

        @case('delivery')
            {{-- A miniature of shop/order's completed mobile view. Only public
                 catalogue data supplies the example card; no order or credential
                 is read. Text stays still after the entrance so it remains sharp.
                 With no eligible card, neutral shapes replace the catalogue data. --}}
            @php
                $deliveryRegion = strtoupper((string) (request()->attributes->get('region') ?: 'US'));
                $deliveryPreview = \Illuminate\Support\Facades\Cache::remember("illo:delivery-preview:v1:{$deliveryRegion}", now()->addMinutes(30), function () use ($deliveryRegion) {
                    $deliveryVariantQuery = fn ($query) => $query
                        ->where('is_available', true)
                        ->where('is_variable', false)
                        ->where('face_value', '>', 0)
                        ->whereColumn('currency', 'products.currency_code');

                    $deliveryProduct = \App\Models\Product::query()
                        ->where('is_active', true)
                        ->where('is_out_of_stock', false)
                        ->where('country_code', $deliveryRegion)
                        ->whereIn('brand_key', array_keys((array) config('brand_assets.logos', [])))
                        ->whereHas('category', fn ($query) => $query->where('slug', 'gift-cards'))
                        ->whereHas('variants', $deliveryVariantQuery)
                        ->orderByDesc('is_popular')
                        ->orderByDesc('is_featured')
                        ->orderBy('name')
                        ->orderBy('id')
                        ->first();

                    if (! $deliveryProduct) {
                        return [];
                    }

                    $deliveryVariant = $deliveryProduct->variants()
                        ->where('is_available', true)
                        ->where('is_variable', false)
                        ->where('face_value', '>', 0)
                        ->where('currency', $deliveryProduct->currency_code)
                        ->orderBy('face_value')
                        ->first();

                    if (! $deliveryVariant) {
                        return [];
                    }

                    return [
                        'brand' => $deliveryProduct->brand_key,
                        'color' => \App\Models\Product::brandColor($deliveryProduct->brand_key, $deliveryProduct->brand_color),
                        'country' => $deliveryProduct->country_code,
                        'currency' => $deliveryVariant->currency,
                        'value' => $deliveryVariant->face_value,
                    ];
                });
                $deliveryColor = $deliveryPreview['color'] ?? '#f4f4f5';
                $deliveryInk = \App\Models\Product::colorIsDark($deliveryColor) ? '#ffffff' : '#0c1a36';
                $deliveryCountry = array_flip(config('countries.codes', []))[$deliveryPreview['country'] ?? ''] ?? ($deliveryPreview['country'] ?? '');
                $deliveryValue = $deliveryPreview && filled($deliveryPreview['currency'])
                    ? \App\Models\Product::currencySymbol($deliveryPreview['currency']).rtrim(rtrim(number_format((float) $deliveryPreview['value'], 2), '0'), '.')
                    : '';
            @endphp
            <svg viewBox="0 0 320 260" role="img" aria-label="{{ __('orders.success_heading') }}: {{ __('orders.credential_code') }}" preserveAspectRatio="xMidYMid slice" class="h-full w-full" font-family="'DM Sans', sans-serif">
                <title>{{ __('orders.success_heading') }}</title>
                <rect width="320" height="260" rx="18" fill="#0E1626"/>
                <circle cx="160" cy="132" r="108" fill="none" stroke="#1e293b" stroke-width="1"/>
                <ellipse cx="160" cy="244" rx="73" ry="4" fill="#020617" opacity="0.45"/>
                <path class="idl-spark" d="M47 74v8M43 78h8" stroke="#60a5fa" stroke-width="1.7" stroke-linecap="round"/>
                <path class="idl-spark" d="M272 170v8M268 174h8" stroke="#2563eb" stroke-width="1.7" stroke-linecap="round"/>
                <circle class="idl-spark" cx="267" cy="54" r="2.5" fill="#60a5fa"/>
                <circle class="idl-spark" cx="52" cy="201" r="2" fill="#2563eb"/>

                <g id="idl-phone">
                    <rect x="74" y="65" width="2" height="22" rx="1" fill="#64748b"/>
                    <rect x="244" y="83" width="2" height="31" rx="1" fill="#64748b"/>
                    <rect x="76" y="12" width="168" height="234" rx="23" fill="#182231" stroke="#64748b" stroke-width="1.5"/>
                    <rect x="82" y="18" width="156" height="222" rx="18" fill="#fafafa"/>
                    <rect x="142" y="24" width="36" height="6" rx="3" fill="#182231"/>

                    {{-- The same success tick and heading as the completed order. --}}
                    <g id="idl-badge">
                        <circle cx="160" cy="60" r="17" fill="#10b981" opacity="0.12"/>
                        <circle cx="160" cy="60" r="13" fill="#10b981"/>
                        <path id="idl-check" d="M153.5 60l4.5 4.5 8.5-10" fill="none" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                    </g>
                    <g id="idl-heading" text-anchor="middle">
                        <text x="160" y="87" fill="#0c1a36" font-size="9.5" font-weight="700">{{ __('orders.success_heading') }}</text>
                        <text x="160" y="98" fill="#4a5a72" font-size="5.8">{{ __('orders.success_thanks') }}</text>
                    </g>
                    <g id="idl-download">
                        <path d="M117 108v5m-2-2 2 2 2-2m-5 3v2h6v-2" fill="none" stroke="#0c1a36" stroke-width="0.8" stroke-linecap="round" stroke-linejoin="round"/>
                        <text x="164" y="114" text-anchor="middle" fill="#0c1a36" font-size="5.7" font-weight="600" text-decoration="underline">{{ __('orders.download_csv') }}</text>
                    </g>

                    <g id="idl-card">
                        <rect x="92" y="126" width="136" height="75" rx="6" fill="{{ $deliveryColor }}" stroke="{{ \App\Models\Product::colorIsDark($deliveryColor) ? $deliveryColor : '#dee3e1' }}" stroke-width="0.8"/>
                        @if ($deliveryPreview)
                            <image href="{{ \App\Models\Product::brandLogoUrl($deliveryPreview['brand']) }}" x="101" y="135" width="43" height="27" preserveAspectRatio="xMinYMid meet"/>
                            @if ($deliveryValue !== '')
                                <text x="219" y="145" text-anchor="end" fill="{{ $deliveryInk }}" font-size="9" font-weight="800">{{ $deliveryValue }}</text>
                            @else
                                <rect x="190" y="140" width="29" height="5" rx="2.5" fill="{{ $deliveryInk }}" opacity="0.15"/>
                            @endif
                            <text x="219" y="156" text-anchor="end" fill="{{ $deliveryInk }}" opacity="0.75" font-size="5.2">{{ $deliveryCountry }}</text>
                            @unless (\App\Models\Product::logoCarriesName($deliveryPreview['brand']))
                                <text x="101" y="170" fill="{{ $deliveryInk }}" font-size="5.4" font-weight="700">{{ __('orders.card_for_brand', ['brand' => \App\Models\Product::brandDisplayName($deliveryPreview['brand'])]) }}</text>
                            @endunless
                        @else
                            <rect x="102" y="137" width="26" height="20" rx="3" fill="#ffffff"/>
                            <path d="M109 143h12v8h-12zm6 0v8m-6-5h12" fill="none" stroke="#4a5a72" stroke-width="1.1"/>
                            <rect x="185" y="140" width="32" height="6" rx="3" fill="#c9d0cd"/>
                            <rect x="194" y="153" width="23" height="3" rx="1.5" fill="#c9d0cd"/>
                            <text x="102" y="170" fill="#0c1a36" font-size="5.4" font-weight="700">{{ __('orders.card_fallback_name') }}</text>
                        @endif

                        {{-- Masked drawing, never a credential from a customer's order. --}}
                        <g id="idl-credentials">
                            <rect x="99" y="178" width="122" height="16" rx="8" fill="#ffffff" stroke="#dee3e1" stroke-width="0.5"/>
                            <text x="106" y="188" fill="#6b7a90" font-size="4.7" font-weight="600">{{ __('orders.credential_code') }}</text>
                            @foreach ([131, 136, 141, 149, 154, 159, 167, 172, 177] as $deliveryDotX)
                                <circle class="idl-char" cx="{{ $deliveryDotX }}" cy="186" r="1.3" fill="#0c1a36"/>
                            @endforeach
                            <g class="idl-copy" fill="none" stroke="#6b7a90" stroke-width="0.8" stroke-linejoin="round">
                                <rect x="208" y="183" width="5" height="6" rx="1"/>
                                <path d="M210 181h4a1 1 0 0 1 1 1v5"/>
                            </g>
                        </g>
                    </g>
                    <g id="idl-actions" fill="#6b7a90" font-size="4.7" text-anchor="middle">
                        <text x="126" y="214">{{ __('orders.redeem_instructions') }}</text>
                        <text x="194" y="214">{{ __('orders.terms_and_conditions') }}</text>
                    </g>
                    <rect x="140" y="231" width="40" height="3" rx="1.5" fill="#0c1a36" opacity="0.22"/>
                </g>
            </svg>
            @break
    @endswitch
</div>
