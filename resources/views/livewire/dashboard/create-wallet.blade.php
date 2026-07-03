<?php

use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\WalletService;
use Livewire\Volt\Component;

/**
 * Create Wallet — lets a customer open an empty wallet in a currency they do
 * not hold yet. Wires to WalletService::getOrCreateWallet (idempotent), so a
 * wallet starts at a zero balance and can then be funded.
 */
new class extends Component
{
    /** Chosen currency code. */
    public string $currency = '';

    public function mount(): void
    {
        // Default to USD (the currency auto-create uses) when it is still
        // available, otherwise the first currency the customer does not hold.
        $codes = array_map(fn (Currency $c) => $c->value, $this->availableCurrencies());
        $this->currency = in_array('USD', $codes, true) ? 'USD' : ($codes[0] ?? '');
    }

    /**
     * Currencies the customer does not already hold a wallet in.
     *
     * @return array<int, Currency>
     */
    public function availableCurrencies(): array
    {
        $held = auth()->user()->wallets()->pluck('currency')
            ->map(fn ($c) => $c instanceof Currency ? $c->value : (string) $c)
            ->all();

        // RCOIN is a rewards balance, not a fundable wallet - it can never be
        // topped up, so it must never appear in the "open a wallet" list.
        return array_values(array_filter(
            Currency::cases(),
            fn (Currency $c) => $c->value !== 'RCOIN' && ! in_array($c->value, $held, true),
        ));
    }

    /**
     * Open the wallet, then reload the page so the new card shows.
     */
    public function create(WalletService $wallets)
    {
        $this->validate([
            'currency' => ['required', 'in:'.implode(',', array_values(array_filter(array_column(Currency::cases(), 'value'), fn ($v) => $v !== 'RCOIN')))],
        ]);

        $currency = Currency::from($this->currency);

        $wallets->getOrCreateWallet(auth()->user(), $currency);

        session()->flash('wallet_created', $currency->value.' wallet is ready.');

        return $this->redirect(route('dashboard.wallet'), navigate: true);
    }
}; ?>

<div x-data="{ open: false, ccyOpen: false }" class="shrink-0">
    {{-- Trigger --}}
    <button
        type="button"
        @click="open = true"
        class="inline-flex shrink-0 items-center gap-1.5 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700"
    >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Create wallet
    </button>

    {{-- Modal --}}
    <template x-if="open">
        <div
            x-on:keydown.escape.window="open = false"
            class="fixed inset-0 z-[80] flex items-center justify-center p-4"
        >
            <div x-transition.opacity @click="open = false" class="absolute inset-0 bg-zinc-900/45" aria-hidden="true"></div>

            <div
                x-transition
                class="relative w-full max-w-md rounded-[12px] bg-white p-6 text-left shadow-2xl shadow-zinc-900/25"
                role="dialog"
                aria-modal="true"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-zinc-900">Create a wallet</h2>
                        <p class="mt-0.5 text-xs text-zinc-600">Open a new currency wallet, then fund it any time.</p>
                    </div>
                    <x-close-button @click="open = false" />
                </div>

                @if (count($this->availableCurrencies()) === 0)
                    <div class="mt-6 rounded-[12px] bg-zinc-50 px-4 py-6 text-center ring-1 ring-zinc-100">
                        <p class="text-sm font-semibold text-zinc-900">You hold every currency</p>
                        <p class="mt-1 text-xs text-zinc-600">There are no more wallet currencies to add right now.</p>
                    </div>
                @else
                    {{-- Currency picker --}}
                    <label class="mt-5 block text-xs font-semibold text-zinc-700">Currency</label>
                    <div class="relative mt-1.5" @click.outside="ccyOpen = false">
                        <button
                            type="button"
                            @click="ccyOpen = ! ccyOpen"
                            :aria-expanded="ccyOpen.toString()"
                            class="flex w-full items-center justify-between gap-2 rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-sm font-medium text-zinc-900 outline-none transition-colors hover:border-zinc-300 focus:outline-none focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-500/15"
                        >
                            <span>{{ $currency }} &middot; {{ Currency::tryFrom($currency)?->label() }}</span>
                            <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-150" :class="ccyOpen && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <div
                            x-show="ccyOpen"
                            x-cloak
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            class="absolute left-0 right-0 z-20 mt-1.5 max-h-60 overflow-y-auto rounded-[12px] border border-zinc-200 bg-[#eff6ff] p-1 shadow-xl shadow-zinc-900/10"
                            role="listbox"
                        >
                            @foreach ($this->availableCurrencies() as $c)
                                <button
                                    type="button"
                                    wire:click="$set('currency', '{{ $c->value }}')"
                                    @click="ccyOpen = false"
                                    role="option"
                                    aria-selected="{{ $currency === $c->value ? 'true' : 'false' }}"
                                    class="flex w-full items-center justify-between gap-2 rounded-[12px] px-3 py-2 text-left text-sm font-medium transition-colors {{ $currency === $c->value ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-100' }}"
                                >
                                    <span>{{ $c->value }} &middot; {{ $c->label() }}</span>
                                    @if ($currency === $c->value)
                                        <svg class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                        </svg>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @error('currency') <p class="mt-1.5 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror

                    <button
                        type="button"
                        wire:click="create"
                        wire:target="create"
                        wire:loading.attr="disabled"
                        class="mt-5 flex w-full items-center justify-center gap-2 rounded-[15px] bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-60"
                    >
                        <svg wire:loading wire:target="create" class="h-4 w-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span wire:loading.remove wire:target="create">Create wallet</span>
                        <span wire:loading wire:target="create">Creating...</span>
                    </button>
                @endif
            </div>
        </div>
    </template>
</div>
