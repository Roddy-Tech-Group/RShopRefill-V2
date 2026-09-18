{{-- Shared inquiry-form layout. Pages differ only in copy, category dropdown
     contents, field labels and the POST action; this partial holds the markup
     so a copy tweak ships in one place.

     Expected vars (passed via @include):
       $kind        : short slug, shown as the hero badge unless $badge is set
       $title       : page H1
       $tagline     : hero subhead
       $intro       : intro paragraph next to the form
       $postRoute   : route name for POST
       $categories  : array<string> of options for the category select

     Optional label overrides. The field NAMES never change (the controller
     validates company, company_url, role, category), only what the visitor
     reads, so a creator is asked for a channel rather than a company:
       $badge, $emailLabel, $emailPlaceholder, $companyLabel,
       $companyPlaceholder, $urlLabel, $roleLabel, $rolePlaceholder,
       $categoryLabel, $messagePlaceholder
--}}
<x-layouts.app.header :title="$title.' | '.$siteName">

    @php
        $field = 'w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15';
    @endphp

    {{-- Hero --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1000px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">{{ $badge ?? strtoupper($kind) }}</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl lg:text-5xl">{{ $title }}</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base">{{ $tagline }}</p>
        </div>
    </section>

    <section class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">
        @include('shop._inquiry-form-body')
    </section>

</x-layouts.app.header>
