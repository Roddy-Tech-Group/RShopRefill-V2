@php
    // FAQ - comprehensive, grouped accordion. All FAQ content comes from the
    // CMS-managed `faqs` table (admin > Content > FAQs), grouped by topic and
    // ordered by sort_order. Rcoin-related FAQs are filtered out when the
    // Rcoin engine is off so the rewards-related questions don't appear when
    // the feature is disabled.
    //
    // The Rcoin filter reads the SOURCE topic, never the translated one: a
    // French visitor sees "Récompenses", which contains neither word, so
    // matching on the displayed topic let the hidden questions back in.
    $rcoinEnabled = (bool) \App\Models\Setting::get('rcoin_enabled', true);

    $groups = \App\Models\Faq::published()
        ->ordered()
        ->withTranslations()
        ->get()
        ->when(! $rcoinEnabled, fn ($faqs) => $faqs->reject(fn ($f) => stripos((string) $f->getRawOriginal('topic'), 'reward') !== false || stripos((string) $f->getRawOriginal('topic'), 'rcoin') !== false))
        ->groupBy('topic')
        ->map(fn ($items) => $items->map(fn ($f) => [$f->question, $f->answer])->all())
        ->all();
@endphp

<x-layouts.app.header :title="__('support.faq_title').' | '.$siteName">

    <div class="mx-auto w-full max-w-[1140px] px-4 py-14 sm:px-6 sm:py-20">
        <div class="grid grid-cols-1 gap-10 lg:grid-cols-3 lg:gap-14">

            {{-- Title --}}
            <div class="lg:sticky lg:top-[156px] lg:self-start">
                <h1 class="text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl lg:text-5xl dark:text-white">{{ __('support.faq_heading') }}</h1>
                <p class="mt-3 text-sm text-zinc-600 sm:text-base dark:text-zinc-400">{{ __('support.faq_subheading') }}</p>
                <p class="mt-6 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                    {!! __('support.faq_still_need_help', [
                        'help_center' => '<a href="'.route('shop.help').'" wire:navigate class="font-medium text-blue-600 hover:underline dark:text-blue-400">'.e(__('support.faq_help_center_link')).'</a>',
                        'contact_team' => '<a href="'.route('shop.contact').'" wire:navigate class="font-medium text-blue-600 hover:underline dark:text-blue-400">'.e(__('support.faq_contact_team_link')).'</a>',
                    ]) !!}
                </p>
            </div>

            {{-- Questions --}}
            <div class="lg:col-span-2">
                @foreach ($groups as $heading => $items)
                    <section class="mt-2 first:mt-0">
                        <h2 class="text-sm font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $heading }}</h2>
                        <div class="mt-3 border-t border-zinc-100 dark:border-zinc-700/60">
                            @foreach ($items as [$q, $a])
                                <div x-data="{ open: false }" class="border-b border-zinc-100 dark:border-zinc-700/60">
                                    <button type="button" @click="open = ! open" :aria-expanded="open.toString()" class="flex w-full items-center justify-between gap-4 py-4 text-left">
                                        <span class="text-sm font-semibold text-zinc-900 sm:text-base dark:text-white">{{ $q }}</span>
                                        <svg class="h-5 w-5 shrink-0 text-zinc-500 transition-transform duration-200 dark:text-zinc-400" :class="open && 'rotate-45'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                        </svg>
                                    </button>
                                    <div x-show="open" x-collapse x-cloak>
                                        <p class="pb-4 pr-8 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $a }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>

                    @if (! $loop->last)
                        <div class="h-10"></div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>

</x-layouts.app.header>
