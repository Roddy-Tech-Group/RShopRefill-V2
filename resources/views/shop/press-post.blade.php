@php
    // Single press post. Dark-mode safe (bg-white/bg-zinc-50/bg-blue-50 -> navy).
    $img = fn (string $file) => asset('assets/'.rawurlencode($file));
    $pressEmail = 'info@rshoprefill.com';
@endphp

<x-layouts.app.header
    :title="$post->title.' | '.$siteName"
    :description="$post->excerpt"
    :keywords="$post->title.', '.$siteName.' press, '.$siteName.' newsroom, Roddy Technologies'"
    :og-image="$post->image ? asset('assets/'.rawurlencode($post->image)) : asset('assets/og-image.png')"
    og-type="article"
>

    <article class="mx-auto w-full max-w-[820px] px-4 py-12 sm:px-6 sm:py-16">
        <a href="{{ route('shop.press') }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-medium text-blue-600 hover:underline">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
            Back to newsroom
        </a>

        <p class="mt-6 text-xs font-bold uppercase tracking-wider text-blue-600">{{ $post->category }}</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">{{ $post->title }}</h1>
        <p class="mt-3 text-sm text-zinc-500">{{ $post->published_at->format('M j, Y') }}</p>

        <div class="mt-8 flex items-center justify-center overflow-hidden rounded-[12px] bg-blue-50 p-8 ring-1 ring-zinc-100" style="height: 18rem;">
            <img src="{{ $img($post->image) }}" alt="" class="max-h-full w-auto object-contain" loading="lazy">
        </div>

        <div class="mt-8 space-y-4 text-sm leading-relaxed text-zinc-600 sm:text-base">
            @foreach ($post->body as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        </div>

        {{-- Optional downloadable attachment. Renders only when the editor
             uploaded a file in the admin. `download` attribute hints the
             browser to save instead of navigate. --}}
        @if ($post->attachment_path)
            <div class="mt-8 flex items-center justify-between gap-3 rounded-[12px] border border-blue-200 bg-blue-50 px-5 py-4 dark:border-blue-500/30 dark:bg-blue-500/10">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-blue-600 text-white">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9z"/>
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $post->attachment_label ?: 'Download file' }}</p>
                        <p class="truncate text-[11px] text-zinc-600 dark:text-zinc-400">{{ basename($post->attachment_path) }}</p>
                    </div>
                </div>
                <a
                    href="{{ asset('assets/'.$post->attachment_path) }}"
                    download
                    class="inline-flex shrink-0 items-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                    Download
                </a>
            </div>
        @endif

        <div class="mt-10 rounded-[12px] bg-zinc-50 p-6 ring-1 ring-zinc-100">
            <p class="text-sm leading-relaxed text-zinc-600">
                Media enquiry about this announcement? Email
                <a href="mailto:{{ $pressEmail }}" class="font-medium text-blue-600 hover:underline">{{ $pressEmail }}</a>
                or <a href="{{ route('shop.contact') }}" wire:navigate class="font-medium text-blue-600 hover:underline">contact our team</a>.
            </p>
        </div>
    </article>

    @if ($related->isNotEmpty())
        <section class="border-t border-zinc-100 bg-zinc-50">
            <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">
                <h2 class="text-xl font-bold tracking-tight text-zinc-900">More from the newsroom</h2>
                <div class="mt-6 grid grid-cols-1 gap-x-6 gap-y-10 sm:grid-cols-3">
                    @foreach ($related as $item)
                        <a href="{{ route('shop.press.show', $item->slug) }}" wire:navigate class="group block">
                            <div class="flex items-center justify-center overflow-hidden rounded-[12px] bg-white p-6 ring-1 ring-zinc-100" style="height: 10rem;">
                                <img src="{{ $img($item->image) }}" alt="" class="max-h-full w-auto object-contain" loading="lazy">
                            </div>
                            <p class="mt-3 text-xs font-bold uppercase tracking-wider text-blue-600">{{ $item->category }}</p>
                            <h3 class="mt-1 text-base font-bold leading-snug text-zinc-900 transition-colors group-hover:text-blue-600">{{ $item->title }}</h3>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

</x-layouts.app.header>
