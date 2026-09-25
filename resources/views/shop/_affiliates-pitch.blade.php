{{--
    The partner page's case, between its hero and the application form.

    Every figure here is the catalogue's or the customer table's own, read
    through the same cached queries the homepage and the dashboard use, so the
    page can never claim a number the store does not hold, and each one moves
    on its own as the store grows. On an empty catalogue a line falls back to a
    plain description rather than a zero.

    The copy promises no rates, commissions or response times: those are
    business terms nobody has set yet, and a page that states them is making
    an offer (see affiliates.blade.php and the FAQ seeder).
--}}
@php
    $stats = \App\Support\CatalogFigures::purchaseStats();
    $counts = \App\Support\CatalogFigures::headlineCounts();

    $stat = fn (string $slug, string $key): int => (int) ($stats[$slug][$key] ?? 0);
    $plural = fn (int $n, string $word): string => number_format($n).' '.\Illuminate\Support\Str::plural($word, $n);
    $countries = fn (int $n): string => $plural($n, 'country');

    // Headline figures. Rounded down, so the page only ever states a number the
    // business can defend, and left out entirely when there is nothing worth
    // reporting rather than shown as a zero.
    $floorTo = fn (int $n, int $step): int => intdiv($n, $step) * $step;
    $figures = array_values(array_filter([
        $counts['customers'] >= 100 ? [number_format($floorTo($counts['customers'], 100)).'+', 'customers already shopping with us'] : null,
        $counts['countries'] >= 10 ? [number_format($counts['countries']), 'countries with products on the shelf'] : null,
        $stat('gift-cards', 'brands') >= 10 ? [number_format($stat('gift-cards', 'brands')), 'gift card brands to feature'] : null,
    ]));

    // Who the page speaks to. Three audiences, each with the products that fit
    // what they already talk about.
    $audiences = [
        ['gift', 'Creators and influencers', 'You make videos, posts or streams, and your audience trusts what you recommend. Gift cards for games, apps and shops sit naturally beside what you already cover.'],
        ['globe', 'Publishers and bloggers', 'You run a site, a newsletter or a deals page. Travel eSIMs and international top-ups give your readers something useful wherever they are going.'],
        ['mobile', 'Community leaders', 'You run a messaging group, a campus network or a diaspora community. Airtime, data and bills for home are things your members already pay for.'],
    ];

    // The four stores, each with the catalogue's own figure, linking to the
    // storefront page a creator would send their audience to.
    $stores = [
        ['Gift cards', $stat('gift-cards', 'brands') > 0 ? $plural($stat('gift-cards', 'brands'), 'brand').' to choose from' : 'Digital gift cards, delivered as codes', 'purchase/giftcards.svg', route('shop.gift-cards')],
        ['eSIMs', $stat('esims', 'countries') > 0 ? 'Data plans for '.$countries($stat('esims', 'countries')) : 'Travel data plans', 'purchase/esim.svg', route('shop.esims')],
        ['Mobile top-ups', $stat('mobile-airtime', 'brands') > 0 ? $plural($stat('mobile-airtime', 'brands'), 'network').' in '.$countries($stat('mobile-airtime', 'countries')) : 'Airtime and data, credited to the number', 'purchase/airtime.svg', route('shop.topups')],
        ['Bill payments', $stat('bill-payments', 'brands') > 0 ? $plural($stat('bill-payments', 'brands'), 'biller').' in '.$countries($stat('bill-payments', 'countries')) : 'Utilities and services, paid online', 'purchase/bills.svg', route('shop.bills')],
    ];

    // What the store does for the people a creator sends here. Facts about the
    // product, not promises to the creator.
    $reasons = [
        ['delivery', 'Delivered the moment payment clears', 'Codes, PINs and eSIM installs land in the customer\'s account and inbox as soon as payment is confirmed. Nobody you send us waits for a parcel.'],
        ['payWeb', 'Many ways to pay', 'Cards, mobile money, bank transfer and crypto, so your audience can pay the way they already do.'],
        ['globe', 'Built for an audience anywhere', $counts['countries'] >= 10
            ? 'Products for '.$countries($counts['countries']).' and prices shown in local currencies, so one recommendation works for followers at home and abroad.'
            : 'Products across many countries and prices shown in local currencies, so one recommendation works for followers at home and abroad.'],
        ['shield', 'Support that stands behind every order', 'A help centre and a support team answer questions about orders, so a question from your audience does not land on you.'],
    ];

    $card = 'rounded-[12px] bg-white p-6 shadow-md shadow-zinc-900/[0.06] dark:shadow-none';
    $heading = 'text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white';
    $lead = 'mt-2 text-base text-zinc-600 dark:text-zinc-400';
@endphp

<div class="mx-auto w-full max-w-[1140px] px-4 pt-12 sm:px-6 sm:pt-16">

    @if ($figures)
        <ul class="grid grid-cols-1 gap-4 sm:grid-cols-3" aria-label="Headline figures">
            @foreach ($figures as [$figure, $label])
                <li class="{{ $card }}">
                    <p class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ $figure }}</p>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $label }}</p>
                </li>
            @endforeach
        </ul>
    @endif

    <section class="{{ $figures ? 'mt-14 sm:mt-20' : '' }}" aria-labelledby="affiliates-who-heading">
        <div class="max-w-2xl">
            <h2 id="affiliates-who-heading" class="{{ $heading }}">Who this is for</h2>
            <p class="{{ $lead }}">If people come to you for recommendations, there is a place for you here. Fit matters more than audience size.</p>
        </div>
        <ul class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach ($audiences as [$illo, $title, $body])
                <li class="{{ $card }}">
                    <span class="flex h-16 w-16 items-center justify-center"><x-illo :name="$illo" class="h-full w-full" /></span>
                    <h3 class="mt-5 text-lg font-bold text-zinc-900 dark:text-white">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $body }}</p>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="mt-14 sm:mt-20" aria-labelledby="affiliates-stores-heading">
        <div class="max-w-2xl">
            <h2 id="affiliates-stores-heading" class="{{ $heading }}">What you can share with your audience</h2>
            <p class="{{ $lead }}">Four stores, all digital and delivered online, so nothing you recommend needs shipping.</p>
        </div>
        <ul class="mt-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($stores as [$label, $blurb, $icon, $href])
                <li>
                    <a href="{{ $href }}" wire:navigate class="flex h-full flex-col {{ $card }} transition-shadow hover:shadow-lg hover:shadow-zinc-900/[0.10]">
                        <img src="{{ asset('assets/'.$icon) }}" alt="" class="no-dark-invert h-10 w-10 object-contain" loading="lazy">
                        <span class="mt-4 text-base font-bold text-zinc-900 dark:text-white">{{ $label }}</span>
                        <span class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $blurb }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="mt-14 sm:mt-20" aria-labelledby="affiliates-why-heading">
        <div class="max-w-2xl">
            <h2 id="affiliates-why-heading" class="{{ $heading }}">Why creators work with us</h2>
            <p class="{{ $lead }}">What the people you send here get, which is what makes a recommendation easy to stand behind.</p>
        </div>
        <ul class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2">
            @foreach ($reasons as [$illo, $title, $body])
                <li class="flex items-start gap-5 {{ $card }}">
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center"><x-illo :name="$illo" class="h-full w-full" /></span>
                    <div class="min-w-0">
                        <h3 class="text-lg font-bold text-zinc-900 dark:text-white">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $body }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

</div>

{{-- The topic's questions, from the faqs table through the same component the
     store pages use. Renders nothing until the topic has published rows. --}}
<x-home.faq :topics="['Influencers and Affiliates']" :limit="8" heading-id="affiliates-faq-heading" top-margin="mt-14 sm:mt-20" />

{{-- The closing ask, linking down to the form on this page. --}}
<section class="mt-14 bg-blue-600 sm:mt-20" aria-labelledby="affiliates-cta-heading">
    <div class="mx-auto flex w-full max-w-[1140px] flex-col items-start gap-5 px-4 py-12 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div>
            <h2 id="affiliates-cta-heading" class="text-2xl font-bold text-white sm:text-3xl">Ready to work together?</h2>
            <p class="mt-1 text-sm text-blue-100">Tell us about your channel. It takes a couple of minutes and commits you to nothing.</p>
        </div>
        {{-- The same white pill the how-it-works page closes with. --}}
        <a href="#apply" class="inline-flex shrink-0 items-center justify-center rounded-full bg-white px-6 py-3 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-50">Apply now</a>
    </div>
</section>
