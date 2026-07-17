<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.dashboard')]
#[Title('Currency Exchange')]
class extends Component {
    public string $fromCurrency = 'USD';
    public string $toCurrency = 'NGN';
    public float $amount = 0.00;
    
    // Mock exchange rate
    public float $rate = 1500.00;

    public function with(): array
    {
        return [
            'convertedAmount' => $this->amount * $this->rate,
        ];
    }
    
    public function swapCurrencies()
    {
        $temp = $this->fromCurrency;
        $this->fromCurrency = $this->toCurrency;
        $this->toCurrency = $temp;
        
        // Mock swap rate logic
        $this->rate = $this->fromCurrency === 'USD' ? 1500.00 : (1 / 1500.00);
    }
}; ?>

<div class="mx-auto max-w-2xl" x-data="{ 
    amount: @entangle('amount').live,
    confirming: false,
    processing: false,
    pin: '',
    pinDigits: ['', '', '', ''],
    focusNext(index) {
        if (this.pinDigits[index].length === 1 && index < 3) {
            this.$refs['pin' + (index + 1)].focus();
        }
        this.pin = this.pinDigits.join('');
    },
    handleBackspace(event, index) {
        if (event.key === 'Backspace' && !this.pinDigits[index] && index > 0) {
            this.$refs['pin' + (index - 1)].focus();
        }
    },
    async submitExchange() {
        if (this.pin.length !== 4) return;
        this.processing = true;
        
        // Simulate network delay
        await new Promise(r => setTimeout(r, 1500));
        
        window.dispatchEvent(new CustomEvent('flux-toast', {
            detail: {
                text: 'Exchange successful!',
                variant: 'success'
            }
        }));
        
        this.confirming = false;
        this.processing = false;
        this.amount = 0;
        this.pinDigits = ['', '', '', ''];
        this.pin = '';
    }
}">
    {{-- Header --}}
    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Exchange Currency</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Convert between your wallets instantly at market rates.</p>
        </div>
        <a href="{{ route('dashboard') }}" wire:navigate class="rounded-full bg-white p-2 text-zinc-500 shadow-sm ring-1 ring-inset ring-zinc-200 transition-colors hover:bg-zinc-50 dark:bg-zinc-800 dark:ring-zinc-700 dark:hover:bg-zinc-700">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </a>
    </div>

    {{-- Main Calculator --}}
    <div class="overflow-hidden rounded-[24px] bg-white shadow-xl shadow-zinc-900/5 ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:ring-zinc-700/50">
        <div class="p-6">
            
            {{-- You Send --}}
            <div class="relative rounded-[16px] bg-zinc-50 p-4 transition-colors focus-within:bg-blue-50/50 focus-within:ring-2 focus-within:ring-blue-500/20 dark:bg-white/5 dark:focus-within:bg-blue-500/10 dark:focus-within:ring-blue-500/30">
                <div class="flex items-center justify-between">
                    <label for="sendAmount" class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">You Send</label>
                    <span class="text-xs font-medium text-zinc-500">Balance: $12,450.00</span>
                </div>
                <div class="mt-2 flex items-center gap-4">
                    <div class="flex items-center gap-2 rounded-full bg-white px-3 py-1.5 shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700">
                        <img src="{{ asset('assets/USD.svg') }}" x-show="$wire.fromCurrency === 'USD'" class="h-5 w-5 rounded-full object-cover">
                        <img src="{{ asset('assets/NGN.svg') }}" x-show="$wire.fromCurrency === 'NGN'" class="h-5 w-5 rounded-full object-cover">
                        <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ $fromCurrency }}</span>
                    </div>
                    <input type="number" id="sendAmount" x-model="amount" placeholder="0.00" class="w-full bg-transparent text-right text-3xl font-bold tracking-tight text-zinc-900 outline-none placeholder:text-zinc-300 dark:text-white dark:placeholder:text-zinc-600" />
                </div>
            </div>

            {{-- Swap Button --}}
            <div class="relative z-10 -my-4 flex justify-center">
                <button type="button" wire:click="swapCurrencies" class="group flex h-10 w-10 items-center justify-center rounded-full bg-white shadow-md ring-1 ring-zinc-200 transition-transform hover:scale-105 hover:bg-zinc-50 active:scale-95 dark:bg-zinc-800 dark:ring-zinc-700 dark:hover:bg-zinc-700">
                    <svg class="h-5 w-5 text-blue-600 transition-transform group-hover:rotate-180 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v4a1 1 0 001 1h3m10-9l3 3m0 0l-3 3m3-3H8m13 0v4a1 1 0 01-1 1h-3m-10 9l-3-3m0 0l3-3m-3 3h12"/>
                    </svg>
                </button>
            </div>

            {{-- You Receive --}}
            <div class="relative rounded-[16px] bg-zinc-50 p-4 transition-colors dark:bg-white/5">
                <div class="flex items-center justify-between">
                    <label class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">You Receive</label>
                    <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">Rate: 1 {{ $fromCurrency }} = {{ number_format($rate, 2) }} {{ $toCurrency }}</span>
                </div>
                <div class="mt-2 flex items-center gap-4">
                    <div class="flex items-center gap-2 rounded-full bg-white px-3 py-1.5 shadow-sm ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700">
                        <img src="{{ asset('assets/NGN.svg') }}" x-show="$wire.toCurrency === 'NGN'" class="h-5 w-5 rounded-full object-cover">
                        <img src="{{ asset('assets/USD.svg') }}" x-show="$wire.toCurrency === 'USD'" class="h-5 w-5 rounded-full object-cover">
                        <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ $toCurrency }}</span>
                    </div>
                    <div class="w-full text-right text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">
                        {{ number_format($convertedAmount, 2) }}
                    </div>
                </div>
            </div>

        </div>

        {{-- Action --}}
        <div class="bg-zinc-50 p-6 dark:bg-white/5">
            <button type="button" 
                @click="amount > 0 ? confirming = true : null"
                :class="amount > 0 ? 'bg-blue-600 hover:bg-blue-700 text-white shadow-md shadow-blue-900/20' : 'bg-zinc-200 text-zinc-400 cursor-not-allowed dark:bg-zinc-800 dark:text-zinc-600'"
                class="w-full rounded-[16px] py-4 text-base font-bold transition-all">
                Continue
            </button>
        </div>
    </div>

    {{-- PIN Confirmation Modal --}}
    <div x-show="confirming" 
         style="display: none;"
         class="fixed inset-0 z-[100] flex items-end sm:items-center justify-center bg-zinc-950/60 p-4 backdrop-blur-sm sm:p-6"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
         
        <div class="w-full max-w-sm overflow-hidden rounded-[24px] bg-white shadow-2xl ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:ring-white/10"
             @click.away="!processing && (confirming = false)"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-8 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0 scale-100"
             x-transition:leave-end="opacity-0 translate-y-8 scale-95">
             
            <div class="p-6 text-center">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                
                <h3 class="text-xl font-bold text-zinc-900 dark:text-white">Confirm Exchange</h3>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Enter your 4-digit PIN to authorize the conversion of <strong x-text="amount"></strong> {{ $fromCurrency }}.</p>

                <div class="mt-6 flex justify-center gap-3">
                    <template x-for="(digit, index) in pinDigits" :key="index">
                        <input type="password" 
                               maxlength="1"
                               x-model="pinDigits[index]"
                               x-ref="'pin' + index"
                               @input="focusNext(index)"
                               @keydown="handleBackspace($event, index)"
                               class="h-14 w-12 rounded-[12px] border-0 bg-zinc-50 text-center text-2xl font-bold text-zinc-900 shadow-inner ring-1 ring-inset ring-zinc-200 focus:bg-white focus:ring-2 focus:ring-inset focus:ring-blue-600 dark:bg-[#1a2b4b] dark:text-white dark:ring-white/10 dark:focus:bg-[#0c1a36] dark:focus:ring-blue-500" />
                    </template>
                </div>

                <div class="mt-8 flex gap-3">
                    <button type="button" @click="confirming = false" :disabled="processing" class="flex-1 rounded-[12px] px-4 py-3 text-sm font-semibold text-zinc-900 ring-1 ring-inset ring-zinc-200 hover:bg-zinc-50 disabled:opacity-50 dark:text-white dark:ring-white/10 dark:hover:bg-white/5">Cancel</button>
                    <button type="button" @click="submitExchange" :disabled="pin.length !== 4 || processing" class="relative flex-1 overflow-hidden rounded-[12px] bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 disabled:bg-zinc-300 disabled:text-zinc-500 dark:disabled:bg-zinc-800 dark:disabled:text-zinc-600">
                        <span :class="processing && 'opacity-0'">Authorize</span>
                        <div x-show="processing" class="absolute inset-0 flex items-center justify-center">
                            <svg class="h-5 w-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
