@props(['title', 'open' => false])

{{--
    One accordion row: a card with its title and a round toggle that reads + when
    shut and - when open. Built on <details> so it opens without JavaScript and
    stays reachable by keyboard. Rows sit in a space-y stack, one topic each.
--}}
<details
    @if ($open) open @endif
    {{ $attributes->class('group pure-card rounded-[12px] border border-zinc-200 bg-white px-5 shadow-sm shadow-zinc-900/[0.04] transition-colors dark:border-white/10 dark:shadow-none') }}
>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-4 text-base font-bold text-zinc-900 marker:content-[''] dark:text-white [&::-webkit-details-marker]:hidden">
        {{ $title }}
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-700 transition-colors group-hover:bg-zinc-200 dark:bg-white/10 dark:text-zinc-200 dark:group-hover:bg-white/15" aria-hidden="true">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <path d="M5 12h14"/>
                {{-- The upright stroke folds away, so the mark reads as a minus once open. --}}
                <path d="M12 5v14" class="origin-center transition-transform duration-200 group-open:scale-y-0"/>
            </svg>
        </span>
    </summary>

    <div class="pb-5 text-base leading-relaxed text-zinc-700 dark:text-zinc-300">
        {{ $slot }}
    </div>
</details>
