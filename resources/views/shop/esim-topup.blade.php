@php
    /** @var \App\Models\OrderItem $orderItem */
    /** @var string $iccid */
    /** @var array<int, array<string, mixed>> $packages */

    $snap = $orderItem->product_snapshot ?? [];
    $countryNames = array_flip(config('countries.codes', []));
    $cc = $snap['country_code'] ?? null;
    $countryName = $cc ? ($countryNames[strtoupper($cc)] ?? $cc) : 'your eSIM';

    // Sort by validity then by data so the cheapest-shortest sit at the top
    // — most buyers refilling are topping up a current trip, not stockpiling.
    $sorted = collect($packages)
        ->sortBy([['day', 'asc'], ['retail_usd', 'asc']])
        ->values();
@endphp

<x-layouts.app.header :title="'Top up your eSIM | '.$siteName">

    <div class="mx-auto w-full max-w-[820px] px-4 pb-32 pt-6 sm:px-6 lg:pt-10">

        <nav class="mb-6 flex flex-wrap items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400" aria-label="Breadcrumb">
            <a href="{{ route('dashboard.orders') }}" wire:navigate class="font-medium transition-colors hover:text-zinc-900 dark:hover:text-white">My orders</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span class="font-semibold text-zinc-900 dark:text-white">Top up eSIM</span>
        </nav>

        @if (session('status'))
            <div class="mb-4 flex items-center gap-2 rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-[12px] bg-red-50 px-4 py-3 text-sm font-medium text-red-700 ring-1 ring-red-200">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- Header card --}}
        <div class="esim-tile rounded-[12px] bg-[#eff6ff] p-6 ring-1 ring-zinc-200 shadow-md shadow-zinc-900/[0.06] sm:p-8 dark:ring-zinc-700/60 dark:shadow-none">
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">Top up your {{ $countryName }} eSIM</h1>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Refill the same eSIM you installed last time. No new QR. The eSIM auto-renews to the bundle you choose. Don't let your plan days finish if you plan to keep using it.</p>
            <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">ICCID</p>
            <p class="font-mono text-sm font-bold tracking-wider text-zinc-900 dark:text-white">{{ $iccid }}</p>
        </div>

        {{-- Package list --}}
        <h2 class="mt-8 text-lg font-bold text-zinc-900 dark:text-white">Choose a top-up</h2>

        @if (! auth()->user()->hasTransactionPin())
            <div class="mt-6 rounded-[12px] bg-amber-50 p-6 text-center ring-1 ring-amber-200">
                <p class="text-base font-semibold text-amber-900">Wallet PIN Required</p>
                <p class="mt-1 text-sm text-amber-700">You must set up a Wallet Transaction PIN before you can top up using your wallet balance.</p>
                <a href="{{ route('dashboard.password') }}" class="mt-4 inline-flex items-center gap-1.5 rounded-[12px] bg-amber-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-amber-700">
                    Set up PIN in Settings
                </a>
            </div>
        @elseif ($sorted->isEmpty())
            <div class="esim-tile mt-4 rounded-[12px] bg-[#eff6ff] px-6 py-12 text-center ring-1 ring-zinc-200 dark:ring-zinc-700/60">
                <p class="text-base font-semibold text-zinc-900 dark:text-white">No top-ups available right now</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">This eSIM either expired or the carrier doesn't offer refills. Buy a fresh one to keep going.</p>
                <a href="{{ route('shop.esims') }}" wire:navigate class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400">Browse eSIMs</a>
            </div>
        @else
            <div class="mt-4 space-y-3" x-data="{
                pinModalOpen: false,
                selectedPkgId: '',
                selectedNetPrice: '',
                walletPin: '',
                openModal(pkgId, netPrice) {
                    this.selectedPkgId = pkgId;
                    this.selectedNetPrice = netPrice;
                    this.walletPin = '';
                    this.pinModalOpen = true;
                    setTimeout(() => this.$refs.pinInput.focus(), 100);
                }
            }">
                @foreach ($sorted as $pkg)
                    @php
                        $dataLabel = $pkg['data'] ?? null;
                        $days = (int) ($pkg['day'] ?? 0);
                        $retail = (float) ($pkg['retail_usd'] ?? 0);
                        $netPrice = (float) ($pkg['net_price'] ?? $pkg['price'] ?? 0);
                        $voice = (int) ($pkg['voice'] ?? 0);
                        $sms = (int) ($pkg['text'] ?? 0);
                    @endphp
                        <button type="button" @click="openModal('{{ $pkg['id'] }}', '{{ $netPrice }}')" class="esim-tile group flex w-full items-center justify-between gap-4 rounded-[12px] border-2 border-transparent bg-[#eff6ff] px-4 py-4 text-left ring-1 ring-zinc-200 shadow-sm transition-all hover:-translate-y-0.5 hover:border-green-200 hover:shadow-md dark:ring-zinc-700/60 dark:hover:border-white">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-baseline gap-x-2 text-base">
                                    <span class="font-bold text-zinc-900 dark:text-white">{{ $dataLabel ?: 'Data' }}</span>
                                    @if ($days > 0)
                                        <span class="text-zinc-600 dark:text-zinc-300">·</span>
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $days }} {{ $days === 1 ? 'day' : 'days' }}</span>
                                    @endif
                                    @if ($voice > 0)
                                        <span class="text-zinc-600 dark:text-zinc-300">·</span>
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $voice }} mins</span>
                                    @endif
                                    @if ($sms > 0)
                                        <span class="text-zinc-600 dark:text-zinc-300">·</span>
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $sms }} SMS</span>
                                    @endif
                                </p>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Lands on your existing eSIM, no new install.</p>
                            </div>
                            <span class="shrink-0 text-right">
                                <span class="block text-base font-bold tabular-nums text-zinc-900 dark:text-white">${{ number_format($retail, 2) }}</span>
                                <span class="block text-[10px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Pay from wallet</span>
                            </span>
                        </button>
                @endforeach

                {{-- PIN Modal --}}
                <div x-show="pinModalOpen" x-cloak class="relative z-50" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                    <div x-show="pinModalOpen" x-transition.opacity class="fixed inset-0 bg-zinc-900/50 backdrop-blur-sm transition-opacity"></div>
                    <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                        <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                            <div x-show="pinModalOpen" x-transition.opacity.translate @click.away="pinModalOpen = false" class="relative w-full max-w-sm transform overflow-hidden rounded-[20px] bg-white p-6 text-left shadow-xl transition-all dark:bg-[#1d3252]">
                                <h3 class="text-lg font-bold text-zinc-900 dark:text-white" id="modal-title">Authorize Payment</h3>
                                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Enter your 4-digit transaction PIN to debit your wallet.</p>
                                
                                <form method="POST" action="{{ route('dashboard.esim.topup.purchase', $orderItem) }}" class="mt-5">
                                    @csrf
                                    <input type="hidden" name="package_id" :value="selectedPkgId">
                                    <input type="hidden" name="net_price" :value="selectedNetPrice">
                                    
                                    <input type="password" name="wallet_pin" x-model="walletPin" x-ref="pinInput" maxlength="4" required class="mt-2 block w-full rounded-[12px] border border-zinc-300 px-3 py-3 text-center text-xl tracking-[0.5em] text-zinc-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 sm:text-2xl" placeholder="••••">
                                    
                                    <div class="mt-6 flex items-center justify-end gap-3">
                                        <button type="button" @click="pinModalOpen = false" class="rounded-[12px] bg-zinc-100 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-200">Cancel</button>
                                        <button type="submit" :disabled="walletPin.length !== 4" class="rounded-[12px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-50">Confirm Top-up</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">Top-ups are billed from your USD wallet balance. Insufficient balance? <a href="{{ route('dashboard.wallet') }}" wire:navigate class="font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400">Fund your wallet</a> first.</p>
        @endif

    </div>

</x-layouts.app.header>
