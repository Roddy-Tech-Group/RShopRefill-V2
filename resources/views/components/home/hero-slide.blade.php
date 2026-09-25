{{--
    A CMS-managed hero slide (admin > Content > Hero Sections): full-bleed
    image or video with an optional headline, subtitle, and CTA over a dark
    scrim. Slides without media fall back to the storefront's light treatment
    so they never render as a void.
--}}
@props(['section'])

@php
    $hasMedia = (bool) $section->media_path;
@endphp

<section class="relative w-full overflow-hidden">
    @if ($hasMedia && $section->media_type === 'video')
        <video src="{{ $section->mediaUrl() }}" class="absolute inset-0 h-full w-full object-cover" autoplay muted loop playsinline aria-hidden="true"></video>
    @elseif ($hasMedia)
        <img src="{{ $section->mediaUrl() }}" alt="{{ $section->title }}" class="absolute inset-0 h-full w-full object-cover">
    @endif

    @if ($hasMedia)
        {{-- Scrim keeps the headline and CTA readable over any footage --}}
        <div class="absolute inset-0 bg-zinc-950/45" aria-hidden="true"></div>
    @endif

    <div class="relative z-10 mx-auto flex min-h-[420px] max-w-[1200px] flex-col items-center justify-center px-3 py-14 text-center sm:min-h-[520px] sm:px-10 sm:py-24 lg:px-14">
        @if ($section->title)
            <h2 class="mx-auto max-w-[900px] text-[40px] leading-[1.1] {{ $hasMedia ? 'text-white' : 'text-zinc-900 dark:text-white' }} sm:text-5xl md:text-6xl lg:text-[64px]">
                {{ $section->title }}
            </h2>
        @endif

        @if ($section->subtitle)
            <p class="mx-auto mt-5 max-w-xl text-lg leading-relaxed {{ $hasMedia ? 'text-white/90' : 'text-zinc-900 dark:text-zinc-200' }}">
                {{ $section->subtitle }}
            </p>
        @endif

        @if ($section->cta_label && $section->cta_url)
            <div class="mt-8">
                <a href="{{ $section->cta_url }}" class="inline-flex items-center rounded-full bg-blue-600 px-5 py-3 text-base font-semibold text-white shadow-lg shadow-blue-600/20 transition-transform hover:-translate-y-0.5 hover:bg-blue-700">
                    {{ $section->cta_label }}
                </a>
            </div>
        @endif
    </div>
</section>
