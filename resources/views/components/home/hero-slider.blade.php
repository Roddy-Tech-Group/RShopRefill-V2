{{--
    Homepage hero stage, driven by admin > Content > Hero Sections.

    - No enabled sections: the hero area renders nothing (turned off).
    - One enabled section: rendered directly with no slider chrome, so the
      default state (just the built-in designed hero) matches the pre-CMS
      storefront exactly.
    - Two or more: slides stack in one grid cell and crossfade every 7s with
      dot controls. Pauses while hovered.
--}}
@php
    $heroSections = \App\Models\HeroSection::enabledCached();
@endphp

@if ($heroSections->count() === 1)
    @php $heroOnly = $heroSections->first(); @endphp
    @if ($heroOnly->is_builtin)
        <x-home.hero :media-path="$heroOnly->media_path" :media-type="$heroOnly->media_type" />
    @else
        <x-home.hero-slide :section="$heroOnly" />
    @endif
@elseif ($heroSections->count() > 1)
    <div
        x-data="{
            total: {{ $heroSections->count() }},
            current: 0,
            paused: false,
            cycle() {
                setInterval(() => {
                    if (! this.paused) this.current = (this.current + 1) % this.total;
                }, 7000);
            },
        }"
        x-init="cycle()"
        @mouseenter="paused = true"
        @mouseleave="paused = false"
        class="relative w-full"
    >
        <div class="grid">
            @foreach ($heroSections as $i => $heroSection)
                <div
                    :class="current === {{ $i }} ? 'opacity-100' : 'pointer-events-none opacity-0'"
                    :aria-hidden="(current !== {{ $i }}).toString()"
                    class="col-start-1 row-start-1 transition-opacity duration-700"
                >
                    @if ($heroSection->is_builtin)
                        <x-home.hero :media-path="$heroSection->media_path" :media-type="$heroSection->media_type" />
                    @else
                        <x-home.hero-slide :section="$heroSection" />
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Dot controls --}}
        <div class="absolute bottom-4 left-1/2 z-20 flex -translate-x-1/2 items-center gap-2">
            @foreach ($heroSections as $i => $heroSection)
                <button
                    type="button"
                    @click="current = {{ $i }}"
                    :class="current === {{ $i }} ? 'w-6 bg-blue-600' : 'w-2.5 bg-zinc-400/70 hover:bg-zinc-500/80'"
                    class="h-2.5 rounded-full transition-all duration-300"
                    aria-label="Show hero slide {{ $i + 1 }}"
                ></button>
            @endforeach
        </div>
    </div>
@endif
