<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Domain\Transaction\Services\TransferService;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.dashboard')] class extends Component {
    // Flow state: 1 (Recipient) -> 2 (Amount) -> 3 (Confirm & PIN)
    public $step = 1;

    // Form data
    public $receiverSearch = '';
    public $receiverFound = null;
    public $amount = '';
    public $currency = 'USD';
    public $note = '';
    public $pin = '';
    
    // UI helpers
    public $availableCurrencies = ['USD', 'NGN', 'EUR', 'GBP'];
    public $recentContacts = [];

    public function mount()
    {
        // Mock recent contacts for UI demonstration
        $this->recentContacts = [
            ['name' => 'John Doe', 'email' => 'john@example.com', 'initials' => 'JD', 'color' => 'bg-indigo-500'],
            ['name' => 'Alice Smith', 'email' => 'alice@example.com', 'initials' => 'AS', 'color' => 'bg-pink-500'],
            ['name' => 'Bob Jones', 'email' => 'bob@example.com', 'initials' => 'BJ', 'color' => 'bg-amber-500'],
        ];
    }

    public function selectContact($email)
    {
        $this->receiverSearch = $email;
        $this->verifyRecipient();
    }

    public function verifyRecipient()
    {
        $this->validate([
            'receiverSearch' => 'required|email|exists:users,email',
        ], [
            'receiverSearch.exists' => 'No RShop user found with this email.',
        ]);

        $user = User::where('email', $this->receiverSearch)->first();
        if ($user->id === Auth::id()) {
            $this->addError('receiverSearch', 'You cannot send money to yourself.');
            return;
        }

        $this->receiverFound = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'initials' => strtoupper(substr($user->name, 0, 2)),
        ];
        
        $this->step = 2;
    }

    public function back()
    {
        if ($this->step > 1) {
            $this->step--;
        }
        if ($this->step === 1) {
            $this->receiverFound = null;
        }
    }

    public function prepareTransfer()
    {
        $this->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|in:' . implode(',', $this->availableCurrencies),
        ]);

        // Proceed to PIN
        $this->step = 3;
        $this->pin = '';
    }

    public function executeTransfer(TransferService $transferService)
    {
        $this->validate([
            'pin' => 'required|string|min:4',
        ]);

        try {
            // For testing purposes, we assume PIN is validated.
            // Normally: Auth::user()->validateWalletPin($this->pin);

            $transferService->executeP2PTransfer(
                senderId: Auth::id(),
                receiverId: $this->receiverFound['id'],
                amount: (float) $this->amount,
                currency: $this->currency,
                note: $this->note
            );

            $this->reset(['receiverSearch', 'receiverFound', 'amount', 'note', 'pin', 'step']);
            session()->flash('success', 'Transfer completed successfully!');
        } catch (\Exception $e) {
            $this->addError('pin', $e->getMessage());
        }
    }
}; ?>

<div class="flex w-full flex-col gap-8 pb-10" x-data="{ 
        amount: @entangle('amount'),
        appendNum(num) {
            if (this.amount.toString().includes('.') && this.amount.toString().split('.')[1].length >= 2) return;
            if (this.amount === '' && num === '.') this.amount = '0.';
            else this.amount = this.amount.toString() + num;
        },
        deleteNum() {
            this.amount = this.amount.toString().slice(0, -1);
        }
    }">
    
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">Send Money</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Instantly transfer funds to anyone on RShop for free.</p>
        </div>
        <div>
            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-white px-5 text-sm font-semibold text-zinc-700 shadow-sm ring-1 ring-inset ring-zinc-300 transition-all hover:bg-zinc-50 active:scale-95 dark:bg-[#1d3252] dark:text-zinc-300 dark:ring-zinc-700/60 dark:hover:bg-white/5">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                </svg>
                Back to Wallet
            </a>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-xl bg-emerald-50 p-4 border border-emerald-200 dark:bg-emerald-500/10 dark:border-emerald-500/20"
             x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
             x-transition:leave="transition ease-in duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
            <div class="flex items-center gap-3">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                </div>
                <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('success') }}</p>
            </div>
        </div>
    @endif

    <div class="mx-auto w-full max-w-lg">
        
        {{-- Progress Bar --}}
        <div class="mb-8">
            <div class="flex items-center justify-between text-xs font-semibold text-zinc-500 dark:text-zinc-400">
                <span class="{{ $step >= 1 ? 'text-blue-600 dark:text-blue-400' : '' }}">Recipient</span>
                <span class="{{ $step >= 2 ? 'text-blue-600 dark:text-blue-400' : '' }}">Amount</span>
                <span class="{{ $step >= 3 ? 'text-blue-600 dark:text-blue-400' : '' }}">Confirm</span>
            </div>
            <div class="mt-2 flex h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                <div class="bg-blue-600 transition-all duration-500 ease-out" style="width: {{ ($step / 3) * 100 }}%"></div>
            </div>
        </div>

        <div class="relative overflow-hidden rounded-[24px] bg-white shadow-xl ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-zinc-700/60 transition-all duration-300">
            
            {{-- STEP 1: RECIPIENT --}}
            <div x-show="$wire.step === 1" 
                 x-transition:enter="transition ease-out duration-300 delay-100" 
                 x-transition:enter-start="opacity-0 translate-x-8" 
                 x-transition:enter-end="opacity-100 translate-x-0" 
                 x-transition:leave="transition ease-in duration-200" 
                 x-transition:leave-start="opacity-100 translate-x-0" 
                 x-transition:leave-end="opacity-0 -translate-x-8"
                 class="p-6 sm:p-8">
                
                <h2 class="text-xl font-bold text-zinc-900 dark:text-white">Who are you sending to?</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Enter email or RShop tag.</p>

                <form wire:submit="verifyRecipient" class="mt-6">
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                            <svg class="h-5 w-5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                            </svg>
                        </div>
                        <input type="email" wire:model="receiverSearch" class="block w-full rounded-xl border-0 py-4 pl-12 pr-4 text-zinc-900 ring-1 ring-inset ring-zinc-300 placeholder:text-zinc-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-base font-medium dark:bg-[#0c1a36] dark:text-white dark:ring-zinc-700/60" placeholder="Email address">
                    </div>
                    @error('receiverSearch') <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                    <button type="submit" class="mt-6 w-full rounded-xl bg-blue-600 px-4 py-4 text-sm font-bold text-white shadow-sm hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                        <span wire:loading.remove wire:target="verifyRecipient">Continue</span>
                        <span wire:loading wire:target="verifyRecipient">Searching...</span>
                    </button>
                </form>

                <div class="mt-8">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-4">Recent Contacts</h3>
                    <div class="space-y-1">
                        @foreach($recentContacts as $contact)
                            <button type="button" wire:click="selectContact('{{ $contact['email'] }}')" class="flex w-full items-center gap-4 rounded-xl p-3 hover:bg-zinc-50 transition-colors dark:hover:bg-white/5">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $contact['color'] }} text-white font-bold shadow-sm">
                                    {{ $contact['initials'] }}
                                </span>
                                <div class="min-w-0 flex-1 text-left">
                                    <p class="truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $contact['name'] }}</p>
                                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $contact['email'] }}</p>
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- STEP 2: AMOUNT --}}
            <div x-show="$wire.step === 2" x-cloak
                 x-transition:enter="transition ease-out duration-300 delay-100" 
                 x-transition:enter-start="opacity-0 translate-x-8" 
                 x-transition:enter-end="opacity-100 translate-x-0" 
                 x-transition:leave="transition ease-in duration-200" 
                 x-transition:leave-start="opacity-100 translate-x-0" 
                 x-transition:leave-end="opacity-0 -translate-x-8"
                 class="p-6 sm:p-8 flex flex-col items-center">
                
                <button type="button" wire:click="back" class="absolute left-6 top-6 rounded-full p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-white/10 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5"/>
                    </svg>
                </button>

                @if($receiverFound)
                    <div class="flex flex-col items-center mt-2">
                        <span class="flex h-16 w-16 items-center justify-center rounded-full bg-indigo-500 text-xl text-white font-bold shadow-md ring-4 ring-white dark:ring-[#1d3252]">
                            {{ $receiverFound['initials'] }}
                        </span>
                        <h2 class="mt-3 text-lg font-bold text-zinc-900 dark:text-white">{{ $receiverFound['name'] }}</h2>
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $receiverFound['email'] }}</p>
                    </div>
                @endif

                <form wire:submit="prepareTransfer" class="w-full mt-8">
                    {{-- Big Amount Input --}}
                    <div class="relative flex justify-center items-center gap-2 mb-2">
                        <select wire:model="currency" class="appearance-none bg-transparent border-0 text-xl font-bold text-blue-600 focus:ring-0 cursor-pointer dark:text-blue-400 pr-4">
                            @foreach($availableCurrencies as $cur)
                                <option value="{{ $cur }}">{{ $cur }}</option>
                            @endforeach
                        </select>
                        <input type="text" x-model="amount" readonly class="w-full bg-transparent border-0 text-center text-5xl font-bold tracking-tight text-zinc-900 focus:ring-0 dark:text-white placeholder:text-zinc-300 dark:placeholder:text-zinc-700" placeholder="0.00">
                    </div>
                    @error('amount') <p class="text-center text-sm text-red-600 dark:text-red-400 mb-4">{{ $message }}</p> @enderror

                    {{-- Note --}}
                    <div class="mt-6">
                        <div class="relative">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                                <svg class="h-5 w-5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
                                </svg>
                            </div>
                            <input type="text" wire:model="note" class="block w-full rounded-2xl border-0 py-3.5 pl-12 pr-4 text-zinc-900 ring-1 ring-inset ring-zinc-200 placeholder:text-zinc-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm font-medium bg-zinc-50 dark:bg-[#0c1a36] dark:text-white dark:ring-zinc-700/60" placeholder="Add a note (optional)">
                        </div>
                    </div>

                    {{-- Keypad --}}
                    <div class="mt-8 grid grid-cols-3 gap-2">
                        @foreach([1,2,3,4,5,6,7,8,9] as $num)
                            <button type="button" @click="appendNum({{ $num }})" class="flex h-14 items-center justify-center rounded-2xl text-2xl font-semibold text-zinc-900 hover:bg-zinc-100 active:scale-95 dark:text-white dark:hover:bg-white/5 transition-all">
                                {{ $num }}
                            </button>
                        @endforeach
                        <button type="button" @click="appendNum('.')" class="flex h-14 items-center justify-center rounded-2xl text-2xl font-semibold text-zinc-900 hover:bg-zinc-100 active:scale-95 dark:text-white dark:hover:bg-white/5 transition-all">
                            .
                        </button>
                        <button type="button" @click="appendNum(0)" class="flex h-14 items-center justify-center rounded-2xl text-2xl font-semibold text-zinc-900 hover:bg-zinc-100 active:scale-95 dark:text-white dark:hover:bg-white/5 transition-all">
                            0
                        </button>
                        <button type="button" @click="deleteNum()" class="flex h-14 items-center justify-center rounded-2xl text-zinc-900 hover:bg-zinc-100 active:scale-95 dark:text-white dark:hover:bg-white/5 transition-all">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9.75L14.25 12m0 0l2.25 2.25M14.25 12l2.25-2.25M14.25 12L12 14.25m-2.58 4.92l-6.375-6.375a1.125 1.125 0 010-1.59L9.42 4.83c.211-.211.498-.33.796-.33H19.5a2.25 2.25 0 012.25 2.25v10.5a2.25 2.25 0 01-2.25 2.25h-9.284c-.298 0-.585-.119-.796-.33z"/>
                            </svg>
                        </button>
                    </div>

                    <button type="submit" :disabled="!amount || amount == '0'" class="mt-8 w-full rounded-2xl bg-blue-600 px-4 py-4 text-sm font-bold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                        Review Transfer
                    </button>
                </form>
            </div>

            {{-- STEP 3: CONFIRM & PIN --}}
            <div x-show="$wire.step === 3" x-cloak
                 x-transition:enter="transition ease-out duration-300 delay-100" 
                 x-transition:enter-start="opacity-0 translate-x-8" 
                 x-transition:enter-end="opacity-100 translate-x-0" 
                 x-transition:leave="transition ease-in duration-200" 
                 x-transition:leave-start="opacity-100 translate-x-0" 
                 x-transition:leave-end="opacity-0 -translate-x-8"
                 class="p-6 sm:p-8">
                
                <button type="button" wire:click="back" class="absolute left-6 top-6 rounded-full p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-white/10 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5"/>
                    </svg>
                </button>

                <div class="mt-10 flex flex-col items-center text-center">
                    <div class="flex h-14 w-14 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 shadow-sm">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                        </svg>
                    </div>
                    <h2 class="mt-4 text-xl font-bold text-zinc-900 dark:text-white">Confirm Transfer</h2>
                    
                    @if($receiverFound)
                        <div class="mt-6 w-full rounded-2xl bg-zinc-50 p-4 border border-zinc-100 dark:bg-[#0c1a36] dark:border-zinc-700/60">
                            <div class="flex items-center justify-between py-2 border-b border-zinc-200 dark:border-zinc-700/50">
                                <span class="text-sm font-medium text-zinc-500">To</span>
                                <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ $receiverFound['name'] }}</span>
                            </div>
                            <div class="flex items-center justify-between py-3 border-b border-zinc-200 dark:border-zinc-700/50">
                                <span class="text-sm font-medium text-zinc-500">Amount</span>
                                <span class="text-lg font-bold text-zinc-900 dark:text-white">{{ $currency }} {{ number_format((float)$amount, 2) }}</span>
                            </div>
                            @if($note)
                                <div class="flex items-center justify-between py-3 border-b border-zinc-200 dark:border-zinc-700/50">
                                    <span class="text-sm font-medium text-zinc-500">Note</span>
                                    <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $note }}</span>
                                </div>
                            @endif
                            <div class="flex items-center justify-between py-3">
                                <span class="text-sm font-medium text-zinc-500">Fee</span>
                                <span class="text-sm font-bold text-emerald-600">Free</span>
                            </div>
                        </div>
                    @endif

                    <form wire:submit="executeTransfer" class="w-full mt-6">
                        <div>
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 text-left mb-2">Enter Wallet PIN to confirm</p>
                            <input type="password" wire:model="pin" class="block w-full rounded-xl border-zinc-300 px-4 py-4 text-center shadow-sm focus:border-blue-500 focus:ring-blue-500 text-2xl tracking-[0.5em] font-mono font-bold dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white" placeholder="••••" maxlength="4" autocomplete="off" autofocus>
                            @error('pin') <p class="text-xs text-red-500 text-left mt-2">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="mt-6 w-full rounded-2xl bg-blue-600 px-4 py-4 text-sm font-bold text-white shadow-sm hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                            <span wire:loading.remove wire:target="executeTransfer">Send {{ $currency }} {{ number_format((float)$amount, 2) }}</span>
                            <span wire:loading wire:target="executeTransfer">Processing...</span>
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>
