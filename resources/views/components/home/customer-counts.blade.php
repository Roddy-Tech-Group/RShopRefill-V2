{{--
    Headline stats: a mark and a figure on one line, the label beneath.

    The live figures are read from the database, never written here. A
    hardcoded "1M+ customers" is a claim the business cannot stand behind and
    that nobody remembers to update; these move on their own.

    Two rules keep them honest:

      ROUND DOWN, never up, so the figure shown is always one we can defend.

      SHOW THE EXACT COUNT. The "+" reads as "and climbing" rather than as a
      rounding, which is true: the query is cached for 15 minutes, so new
      sign-ups are inside the number before the label changes. A stat only
      disappears when there is genuinely nothing to report.

    The first stat is a positioning claim rather than a measurement, so it has
    no floor and never hides. It is also the only unverifiable thing here,
    sitting beside figures that are verifiable, which is why it says "Trusted"
    rather than the "No.1" it used to: a superlative beside two live counts
    invites the reader to check it, and there is nothing to check it against.
--}}
@php
    // Shared with the partner page, so the two never state different numbers.
    $counts = \App\Support\CatalogFigures::headlineCounts();

    $floorTo = fn (int $n, int $step) => intdiv($n, $step) * $step;

    // Marks are stroke-only on a 24x24 grid, the same idiom as the category row.
    $stats = collect([
        [
            'value' => __('home.counts_rank_value'),
            'label' => __('home.counts_rank_label'),
            'show' => true,
            // A shield with a check, not the trophy this used to carry. The
            // trophy was drawing "No.1"; against the word "Trusted" it read as
            // a different claim than the one the label makes.
            'path' => 'M12 3.2l7 2.6v5.4c0 4.2-2.9 7.9-7 9.6-4.1-1.7-7-5.4-7-9.6V5.8l7-2.6zM8.9 12.1l2.3 2.3 4-4.4',
        ],
        [
            // The exact figure, with a "+" because it is a floor that only ever
            // grows: every new sign-up is already inside this number by the
            // time the 15-minute cache turns over. No rounding, so the figure
            // is the count rather than a marketing approximation of it.
            'value' => number_format($counts['customers']).'+',
            'label' => __('home.counts_customers'),
            'show' => $counts['customers'] > 0,
            'path' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7.6l1.4 2.8 3.1.45-2.25 2.2.53 3.1L12 14.7l-2.78 1.46.53-3.1L7.5 10.85l3.1-.45z',
        ],
        [
            'value' => number_format($counts['countries']),
            'label' => __('home.counts_countries'),
            'show' => $counts['countries'] >= 10,
            'path' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM3.5 9h17M3.5 15h17M12 3c2.3 2.5 3.5 5.6 3.5 9s-1.2 6.5-3.5 9M12 3C9.7 5.5 8.5 8.6 8.5 12s1.2 6.5 3.5 9',
        ],
    ])->filter(fn ($s) => $s['show'])->values();
@endphp

@if ($stats->isNotEmpty())
    <div class="mt-10">
        {{-- One row on a phone. These three figures are a single claim read
             together, and flex-wrap stacked them into three separate rows at
             390px, which turned the panel's evidence into a list. A 3-column
             grid keeps them side by side; from sm the original centred flex row
             returns unchanged. --}}
        <dl class="grid grid-cols-3 items-start gap-x-2 sm:flex sm:flex-wrap sm:justify-center sm:gap-x-20 sm:gap-y-8">
            @foreach ($stats as $stat)
                {{-- Wide enough for the longest label to hold one line. At
                     11rem "Digital commerce platform" broke after the second
                     word, so one stat sat two lines deep beside two that sat
                     one, and the row lost its baseline. --}}
                <div class="text-center sm:max-w-[14rem] sm:text-left">
                    <dd class="flex flex-col items-center gap-1 sm:flex-row sm:gap-2.5">
                        {{-- Blue and heavy, not the ink colour at a hairline.
                             These marks sit beside 34px bold figures, so drawn
                             in the text's own colour at 1.6 they read as part
                             of the number rather than as a mark against it.
                             Blue separates them from the figure and ties them
                             to the promise marks below, which do the same job
                             in green one section down. --}}
                        <svg class="h-6 w-6 shrink-0 text-blue-600 sm:h-7 sm:w-7 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="{{ $stat['path'] }}" />
                        </svg>
                        <span class="text-[19px] font-bold leading-none tracking-tight text-zinc-900 sm:text-[34px] dark:text-white">
                            {{ $stat['value'] }}
                        </span>
                    </dd>
                    <dt class="mt-1.5 text-[11px] leading-tight text-[#0c1a36] sm:mt-2 sm:text-[15px] sm:leading-snug dark:text-zinc-300">
                        {{ $stat['label'] }}
                    </dt>
                </div>
            @endforeach
        </dl>
    </div>
@endif
