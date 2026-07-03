{{--
    Top utility bar — locale + help. Desktop only; the mobile drawer carries
    these controls on small screens. The country/language buttons open the
    shared <x-nav.locale-modal /> popup.
--}}
@php
    // Pull the live Trustpilot aggregate (CMS-managed via SiteSetting).
    // Falls back to a sensible default so the badge always renders.
    $tpRating = (float) \App\Models\SiteSetting::get('reviews.aggregate.rating', 4.4);
    $tpCount  = (int)   \App\Models\SiteSetting::get('reviews.aggregate.count', 0);
@endphp
<div class="bg-zinc-50">
    <div class="max-w-[1350px] mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-9 gap-1">

            {{-- Trustpilot rating badge - desktop only. Wired to the live
                 aggregate so the score and review count match the /reviews
                 page. Click-through opens the public Trustpilot profile in a
                 new tab (config('services.trustpilot.profile_url')). --}}
            <a
                href="{{ config('services.trustpilot.profile_url') }}"
                target="_blank"
                rel="noopener noreferrer"
                class="hidden md:flex items-center gap-1.5 px-2.5 py-1 rounded-[12px] text-[13px] font-medium text-zinc-900 hover:bg-zinc-200 transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                aria-label="See our {{ number_format($tpRating, 1) }} out of 5 rating on Trustpilot (opens in a new tab)"
            >
                {{-- Stars: each one is its own 16px box. The "fill" is driven
                     by a CSS gradient (Trustpilot green up to a per-star %)
                     so the half-step at e.g. 4.5 stars renders as a real
                     half-green / half-grey square, matching the live widget. --}}
                <span class="flex items-center gap-[2px]">
                    @for ($i = 1; $i <= 5; $i++)
                        @php
                            // How filled is THIS star (0..1) given the rating?
                            $fill = max(0, min(1, $tpRating - ($i - 1)));
                            $pct  = (int) round($fill * 100);
                        @endphp
                        <span
                            class="flex h-4 w-4 items-center justify-center rounded-[2px]"
                            style="background: linear-gradient(to right, #00b67a {{ $pct }}%, #dcdce6 {{ $pct }}%);"
                        >
                            <svg class="h-2.5 w-2.5 text-white" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                            </svg>
                        </span>
                    @endfor
                </span>
                <span class="ml-1.5 inline-flex items-center gap-1 text-[13px] font-bold tracking-tight text-zinc-900">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="#00b67a" aria-hidden="true">
                        <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                    </svg>
                    <span>Trustpilot</span>
                </span>
            </a>

            {{-- Spacer keeps the right-aligned controls in place on mobile
                 (when the Trustpilot badge is hidden). --}}
            <span class="md:hidden"></span>

            <div class="flex items-center gap-1">

            {{-- Country --}}
            <button
                type="button"
                @click="localeModalOpen = true"
                class="flex items-center gap-1.5 px-2.5 py-1 rounded-[12px] text-[13px] font-medium text-zinc-900 hover:bg-zinc-200 transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
            >
                <img :src="'https://flagcdn.com/w40/' + (countryCode || 'us').toLowerCase() + '.png'" alt="" class="h-4 w-6 shrink-0 rounded-[3px] object-cover ring-1 ring-zinc-200">
                <span x-text="country">United States</span>
            </button>

            {{-- Language --}}
            <button
                type="button"
                @click="localeModalOpen = true"
                class="flex items-center gap-1.5 px-2.5 py-1 rounded-[12px] text-[13px] font-medium text-zinc-900 hover:bg-zinc-200 transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
            >
                <svg viewBox="0 0 24 24" class="h-5 w-5 shrink-0" fill="currentColor" aria-hidden="true">
                    <path d="M22.55 18.71a1 1 0 0 0 -1.24 0.61 4 4 0 0 1 -6.6 1.58 0.51 0.51 0 0 1 -0.15 -0.35 0.47 0.47 0 0 1 0.15 -0.35l1.51 -1.52a0.48 0.48 0 0 0 0.11 -0.53 0.49 0.49 0 0 0 -0.45 -0.3h-4.37a0.49 0.49 0 0 0 -0.49 0.49v4.37a0.5 0.5 0 0 0 0.3 0.45 0.51 0.51 0 0 0 0.54 -0.11l0.77 -0.77a0.5 0.5 0 0 1 0.69 0A6 6 0 0 0 23.16 20a1 1 0 0 0 -0.61 -1.29Z"/>
                    <path d="M9 23.13a0.45 0.45 0 0 0 0.37 -0.08 0.42 0.42 0 0 0 0.17 -0.34v-1.1a0.48 0.48 0 0 0 -0.35 -0.47 9.82 9.82 0 0 1 -1.61 -0.64v-1.28a2.47 2.47 0 0 1 0.87 -1.88 4.4 4.4 0 0 0 -2.82 -7.78H2.46A9.81 9.81 0 0 1 12 2a9.69 9.69 0 0 1 5.53 1.72h-3.32a2.7 2.7 0 1 0 0 5.39 2.52 2.52 0 0 1 1.84 0.82 6.75 6.75 0 0 1 1.19 -0.1 7.39 7.39 0 0 1 4.23 1.33 0.32 0.32 0 0 0 0.41 0 2 2 0 0 1 1.39 -0.57 0.37 0.37 0 0 0 0.28 -0.13 0.36 0.36 0 0 0 0.09 -0.3 11.76 11.76 0 0 0 -23.4 1.6C0.24 13 0.76 21 9 23.13Z"/>
                    <path d="M23.76 12.47a0.48 0.48 0 0 0 -0.3 -0.45 0.49 0.49 0 0 0 -0.54 0.1l-0.83 0.83a0.49 0.49 0 0 1 -0.69 0 6 6 0 0 0 -9.82 2.35 1 1 0 0 0 0.61 1.24 1 1 0 0 0 1.24 -0.61 4 4 0 0 1 6.57 -1.6 0.49 0.49 0 0 1 0.15 0.35 0.52 0.52 0 0 1 -0.15 0.32l-1.45 1.45a0.49 0.49 0 0 0 0.34 0.84h4.37a0.49 0.49 0 0 0 0.49 -0.49Z"/>
                </svg>
                <span x-text="language">English</span>
            </button>

            {{-- Help --}}
            <a href="{{ route('shop.help') }}" wire:navigate class="flex items-center gap-1.5 px-2.5 py-1 rounded-[12px] text-[13px] font-medium text-zinc-900 hover:bg-zinc-200 transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40">
                <img src="{{ asset('assets/' . rawurlencode('new info.svg')) }}" alt="" class="w-5 h-5 shrink-0" loading="lazy">
                <span>Help</span>
            </a>

            {{-- Theme picker (light / dark / system) — hover or click to open --}}
            <x-theme-toggle class="h-9 w-9 rounded-[12px] text-zinc-900 hover:bg-zinc-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40" />

            </div>
        </div>
    </div>
</div>
