{{--
    Saved Cards — customer payment cards.
    There is no card-vault backend yet (card storage is PCI-scoped and handled by
    the payment gateway), so this page is an empty-state shell. Cards will list
    here once the gateway tokenisation is wired.
--}}
<x-layouts.dashboard>
    <div class="flex w-full flex-col gap-5">

        {{-- Heading (desktop only — mobile uses the layout's slim top bar) --}}
        <div class="hidden lg:block">
            <h1 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-3xl">Saved Cards</h1>
            <p class="mt-1 text-sm text-zinc-600">Cards you save at checkout for faster payments.</p>
        </div>

        {{-- Empty state --}}
        <div class="rounded-[12px] bg-[#eff6ff] px-6 py-16 text-center border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-[12px] bg-blue-50">
                <img src="{{ asset('assets/' . rawurlencode('savedcard.svg')) }}" alt="" class="h-7 w-7" loading="lazy">
            </span>
            <p class="mt-4 text-base font-semibold text-zinc-900">No saved cards yet</p>
            <p class="mx-auto mt-1 max-w-sm text-sm text-zinc-600">
                When you pay with a card at checkout you can save it here for one-tap payments next time.
            </p>
            <a href="{{ route('shop.gift-cards') }}" wire:navigate class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700">
                Start shopping
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                </svg>
            </a>
        </div>

        {{-- Security note --}}
        <div class="flex items-start gap-3 rounded-[12px] bg-[#eff6ff] p-4 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-emerald-50">
                <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/>
                </svg>
            </span>
            <div class="min-w-0">
                <p class="text-sm font-bold text-zinc-900">Your cards are kept safe</p>
                <p class="mt-0.5 text-xs text-zinc-600">Card details are tokenised by our secure payment processor. {{ $siteName }} never stores your full card number.</p>
            </div>
        </div>

    </div>
</x-layouts.dashboard>
