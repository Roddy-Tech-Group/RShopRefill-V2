@php
    // Blog index. Posts come from the blog_posts table (CMS-managed).
    // Dark-mode safe: bg-white/bg-blue-50 remap to navy in dark.
    $img = fn (string $file) => asset('assets/'.rawurlencode($file));
@endphp

<x-layouts.app.header :title="'Blog | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">Blog</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">Guides, tips and stories</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base">
                Practical advice on getting the most out of {{ $siteName }}, from crypto and eSIMs to security and savings.
            </p>
        </div>
    </section>

    {{-- ── Posts grid ────────────────────────────────────────── --}}
    <section class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">
        @if ($posts->isNotEmpty())
            <div class="grid grid-cols-1 gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($posts as $post)
                    <a href="{{ route('shop.blog.show', $post->slug) }}" wire:navigate class="group block">
                        <div class="flex items-center justify-center overflow-hidden rounded-[12px] bg-blue-50 p-6 ring-1 ring-zinc-100" style="height: 12rem;">
                            <img src="{{ $img($post->image) }}" alt="" class="max-h-full w-auto object-contain" loading="lazy">
                        </div>
                        <p class="mt-4 text-xs font-bold uppercase tracking-wider text-blue-600">{{ $post->category }}</p>
                        <h2 class="mt-1 text-lg font-bold leading-snug text-zinc-900 transition-colors group-hover:text-blue-600">{{ $post->title }}</h2>
                        <p class="mt-1.5 text-sm leading-relaxed text-zinc-600">{{ $post->excerpt }}</p>
                        <p class="mt-3 text-xs text-zinc-500">{{ $post->published_at->format('M j, Y') }}@if ($post->read_time) &middot; {{ $post->read_time }}@endif</p>
                    </a>
                @endforeach
            </div>
        @else
            <p class="text-center text-sm text-zinc-600">No articles yet. Check back soon.</p>
        @endif
    </section>

</x-layouts.app.header>
