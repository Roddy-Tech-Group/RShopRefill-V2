@php
    // Press & Media newsroom. Articles come from the press_articles table (CMS-managed).
    // Dark-mode safe: bg-white/bg-zinc-50/bg-blue-50 remap to navy in dark.
    $img = fn (string $file) => asset('assets/'.rawurlencode($file));
    $pressEmail = 'info@rshoprefill.com';
@endphp

<x-layouts.app.header :title="'Press and Media | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">Press &amp; Media</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">Newsroom</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base">
                The latest news, announcements and milestones from {{ $siteName }}.
            </p>
        </div>
    </section>

    {{-- ── Posts grid ────────────────────────────────────────── --}}
    <section class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">
        @if ($posts->isNotEmpty())
            <div class="grid grid-cols-1 gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($posts as $post)
                    <a href="{{ route('shop.press.show', $post->slug) }}" wire:navigate class="group block">
                        <div class="flex items-center justify-center overflow-hidden rounded-[12px] bg-blue-50 p-6 ring-1 ring-zinc-100" style="height: 12rem;">
                            <img src="{{ $img($post->image) }}" alt="" class="max-h-full w-auto object-contain" loading="lazy">
                        </div>
                        <p class="mt-4 text-xs font-bold uppercase tracking-wider text-blue-600">{{ $post->category }}</p>
                        <h2 class="mt-1 text-lg font-bold leading-snug text-zinc-900 transition-colors group-hover:text-blue-600">{{ $post->title }}</h2>
                        <p class="mt-1.5 text-sm leading-relaxed text-zinc-600">{{ $post->excerpt }}</p>
                        <p class="mt-3 text-xs text-zinc-500">{{ $post->published_at->format('M j, Y') }}</p>
                    </a>
                @endforeach
            </div>
        @else
            <p class="text-center text-sm text-zinc-600">No posts yet. Check back soon.</p>
        @endif
    </section>

    {{-- ── Media kit ─────────────────────────────────────────── --}}
    <section class="border-t border-zinc-100 bg-zinc-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {{-- Media enquiries --}}
                <div class="rounded-[12px] bg-white p-6 ring-1 ring-zinc-100">
                    <h3 class="text-base font-bold text-zinc-900">Media enquiries</h3>
                    <p class="mt-2 text-sm leading-relaxed text-zinc-600">For interviews, data or media requests, email us with your outlet and deadline.</p>
                    <a href="mailto:{{ $pressEmail }}" class="mt-3 inline-block text-sm font-semibold text-blue-600 hover:underline">{{ $pressEmail }}</a>
                </div>

                {{-- Brand assets --}}
                <div class="rounded-[12px] bg-white p-6 ring-1 ring-zinc-100">
                    <h3 class="text-base font-bold text-zinc-900">Brand assets</h3>
                    <p class="mt-2 text-sm leading-relaxed text-zinc-600">Use our official logo without altering or recolouring it.</p>
                    <a href="{{ $img('Rshoprefillslogo.webp') }}" download class="mt-3 inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                        Download logo
                    </a>
                </div>

                {{-- Boilerplate --}}
                <div class="rounded-[12px] bg-white p-6 ring-1 ring-zinc-100">
                    <h3 class="text-base font-bold text-zinc-900">About {{ $siteName }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-zinc-600">A global digital marketplace for gift cards, eSIMs, top-ups, bills and travel, with an in-app wallet and crypto support. Founded in 2024, a wholly-owned product of Roddy Technologies LTD.</p>
                </div>
            </div>
        </div>
    </section>

</x-layouts.app.header>
