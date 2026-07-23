<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Domain\Transaction\Services\WithdrawalService;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.dashboard')] class extends Component {
    // Flow state: 1 (Account details) -> 2 (Amount) -> 3 (Confirm & PIN)
    public $step = 1;

    // Form data
    public $bankCode = '';
    public $accountNumber = '';
    public $resolvedName = null;
    public $amount = '';
    public $currency = 'NGN'; // Typical payout currency
    public $pin = '';

    // Mock Banks for UI demonstration
    public $banks = [
        ['code' => '044', 'name' => 'Access Bank'],
        ['code' => '058', 'name' => 'Guaranty Trust Bank'],
        ['code' => '033', 'name' => 'United Bank for Africa'],
        ['code' => '057', 'name' => 'Zenith Bank'],
        ['code' => '011', 'name' => 'First Bank of Nigeria'],
    ];

    public function resolveAccount()
    {
        $this->validate([
            'bankCode' => 'required|string',
            'accountNumber' => 'required|string|min:10|max:10',
        ], [
            'accountNumber.min' => 'Account number must be 10 digits.',
        ]);

        // Mock external API account resolution
        // In real life, use Flutterwave/Paystack API: flutterwave->banks->resolveAccount()
        $this->resolvedName = "JANE DOE OLUWASEUN";
        $this->step = 2;
    }

    public function back()
    {
        if ($this->step > 1) {
            $this->step--;
        }
        if ($this->step === 1) {
            $this->resolvedName = null;
        }
    }

    public function prepareWithdrawal()
    {
        $this->validate([
            'amount' => 'required|numeric|min:100',
            'currency' => 'required|string',
        ]);

        $this->step = 3;
        $this->pin = '';
    }

    public function executeWithdrawal(WithdrawalService $withdrawalService)
    {
        $this->validate([
            'pin' => 'required|string|min:4',
        ]);

        try {
            // Initiate real-time withdrawal via Flutterwave
            $withdrawalService->initiateWithdrawal(
                user: Auth::user(),
                amount: (float) $this->amount,
                currency: $this->currency,
                bankCode: $this->bankCode,
                accountNumber: $this->accountNumber,
                note: 'User initiated withdrawal'
            );

            $this->reset(['bankCode', 'accountNumber', 'resolvedName', 'amount', 'pin', 'step']);
            session()->flash('success', 'Withdrawal initiated successfully! Your funds are on the way.');
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
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">Withdraw Funds</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Transfer funds from your wallet to any local bank account.</p>
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
                <span class="{{ $step >= 1 ? 'text-blue-600 dark:text-blue-400' : '' }}">Account</span>
                <span class="{{ $step >= 2 ? 'text-blue-600 dark:text-blue-400' : '' }}">Amount</span>
                <span class="{{ $step >= 3 ? 'text-blue-600 dark:text-blue-400' : '' }}">Confirm</span>
            </div>
            <div class="mt-2 flex h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                <div class="bg-blue-600 transition-all duration-500 ease-out" style="width: {{ ($step / 3) * 100 }}%"></div>
            </div>
        </div>

        <div class="relative overflow-hidden rounded-[12px] bg-white shadow-xl ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-zinc-700/60 transition-all duration-300">
            
            {{-- STEP 1: ACCOUNT DETAILS --}}
            <div x-show="$wire.step === 1" 
                 x-transition:enter="transition ease-out duration-300 delay-100" 
                 x-transition:enter-start="opacity-0 translate-x-8" 
                 x-transition:enter-end="opacity-100 translate-x-0" 
                 x-transition:leave="transition ease-in duration-200" 
                 x-transition:leave-start="opacity-100 translate-x-0" 
                 x-transition:leave-end="opacity-0 -translate-x-8"
                 class="p-6 sm:p-8">
                
                <h2 class="text-xl font-bold text-zinc-900 dark:text-white">Bank Details</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Where are we sending the funds?</p>

                <form wire:submit="resolveAccount" class="mt-8 space-y-6">
                    <div>
                        <label class="block text-sm font-semibold text-zinc-900 dark:text-zinc-300">Select Bank</label>
                        <select wire:model="bankCode" class="mt-1 block w-full rounded-xl border-0 py-3.5 pl-4 pr-10 text-zinc-900 ring-1 ring-inset ring-zinc-300 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-base font-medium dark:bg-[#0c1a36] dark:text-white dark:ring-zinc-700/60">
                            <option value="">Select a bank...</option>
                            @foreach($banks as $bank)
                                <option value="{{ $bank['code'] }}">{{ $bank['name'] }}</option>
                            @endforeach
                        </select>
                        @error('bankCode') <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-zinc-900 dark:text-zinc-300">Account Number</label>
                        <input type="text" wire:model="accountNumber" maxlength="10" class="mt-1 block w-full rounded-xl border-0 py-3.5 px-4 text-zinc-900 ring-1 ring-inset ring-zinc-300 placeholder:text-zinc-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-base font-medium dark:bg-[#0c1a36] dark:text-white dark:ring-zinc-700/60" placeholder="e.g. 0690000031">
                        @error('accountNumber') <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <button type="submit" class="mt-8 w-full rounded-xl bg-blue-600 px-4 py-4 text-sm font-bold text-white shadow-sm hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                        <span wire:loading.remove wire:target="resolveAccount">Next</span>
                        <span wire:loading wire:target="resolveAccount">Verifying Account...</span>
                    </button>
                </form>
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

                @if($resolvedName)
                    <div class="mt-2 flex w-full flex-col items-center rounded-[12px] bg-zinc-50 p-4 border border-zinc-100 dark:bg-[#0c1a36] dark:border-zinc-700/60 text-center">
                        <span class="text-[10px] font-bold uppercase tracking-widest text-emerald-600 dark:text-emerald-400 mb-1">Account Verified</span>
                        <h2 class="text-base font-bold text-zinc-900 dark:text-white">{{ $resolvedName }}</h2>
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ collect($banks)->firstWhere('code', $bankCode)['name'] ?? 'Bank' }} • {{ $accountNumber }}</p>
                    </div>
                @endif

                <form wire:submit="prepareWithdrawal" class="w-full mt-8">
                    {{-- Big Amount Input --}}
                    <div class="relative flex justify-center items-center gap-2 mb-2">
                        <span class="text-xl font-bold text-blue-600 dark:text-blue-400">{{ $currency }}</span>
                        <input type="text" x-model="amount" readonly class="w-[80%] bg-transparent border-0 text-center text-5xl font-bold tracking-tight text-zinc-900 focus:ring-0 dark:text-white placeholder:text-zinc-300 dark:placeholder:text-zinc-700" placeholder="0.00">
                    </div>
                    @error('amount') <p class="text-center text-sm text-red-600 dark:text-red-400 mb-4">{{ $message }}</p> @enderror

                    {{-- Available Balance Hint --}}
                    <p class="text-center text-sm font-semibold text-zinc-500 mt-4">Available Balance: NGN 150,000.00</p>

                    {{-- Keypad --}}
                    <div class="mt-8 grid grid-cols-3 gap-2">
                        @foreach([1,2,3,4,5,6,7,8,9] as $num)
                            <button type="button" @click="appendNum({{ $num }})" class="flex h-14 items-center justify-center rounded-[12px] text-2xl font-semibold text-zinc-900 hover:bg-zinc-100 active:scale-95 dark:text-white dark:hover:bg-white/5 transition-all">
                                {{ $num }}
                            </button>
                        @endforeach
                        <button type="button" @click="appendNum('.')" class="flex h-14 items-center justify-center rounded-[12px] text-2xl font-semibold text-zinc-900 hover:bg-zinc-100 active:scale-95 dark:text-white dark:hover:bg-white/5 transition-all">
                            .
                        </button>
                        <button type="button" @click="appendNum(0)" class="flex h-14 items-center justify-center rounded-[12px] text-2xl font-semibold text-zinc-900 hover:bg-zinc-100 active:scale-95 dark:text-white dark:hover:bg-white/5 transition-all">
                            0
                        </button>
                        <button type="button" @click="deleteNum()" class="flex h-14 items-center justify-center rounded-[12px] text-zinc-900 hover:bg-zinc-100 active:scale-95 dark:text-white dark:hover:bg-white/5 transition-all">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9.75L14.25 12m0 0l2.25 2.25M14.25 12l2.25-2.25M14.25 12L12 14.25m-2.58 4.92l-6.375-6.375a1.125 1.125 0 010-1.59L9.42 4.83c.211-.211.498-.33.796-.33H19.5a2.25 2.25 0 012.25 2.25v10.5a2.25 2.25 0 01-2.25 2.25h-9.284c-.298 0-.585-.119-.796-.33z"/>
                            </svg>
                        </button>
                    </div>

                    <button type="submit" :disabled="!amount || amount == '0'" class="mt-8 w-full rounded-[12px] bg-blue-600 px-4 py-4 text-sm font-bold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                        Review Withdrawal
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
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                        </svg>
                    </div>
                    <h2 class="mt-4 text-xl font-bold text-zinc-900 dark:text-white">Confirm Withdrawal</h2>
                    
                    @if($resolvedName)
                        <div class="mt-6 w-full rounded-[12px] bg-zinc-50 p-4 border border-zinc-100 dark:bg-[#0c1a36] dark:border-zinc-700/60">
                            <div class="flex items-center justify-between py-2 border-b border-zinc-200 dark:border-zinc-700/50">
                                <span class="text-sm font-medium text-zinc-500">Bank</span>
                                <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ collect($banks)->firstWhere('code', $bankCode)['name'] ?? 'Bank' }}</span>
                            </div>
                            <div class="flex items-center justify-between py-3 border-b border-zinc-200 dark:border-zinc-700/50">
                                <span class="text-sm font-medium text-zinc-500">Account Name</span>
                                <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ $resolvedName }}</span>
                            </div>
                            <div class="flex items-center justify-between py-3 border-b border-zinc-200 dark:border-zinc-700/50">
                                <span class="text-sm font-medium text-zinc-500">Amount</span>
                                <span class="text-lg font-bold text-zinc-900 dark:text-white">{{ $currency }} {{ number_format((float)$amount, 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between py-3 border-b border-zinc-200 dark:border-zinc-700/50">
                                <span class="text-sm font-medium text-zinc-500">Fee</span>
                                <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ $currency }} 50.00</span>
                            </div>
                            <div class="flex items-center justify-between py-3">
                                <span class="text-sm font-medium text-zinc-500">Total Debit</span>
                                <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ $currency }} {{ number_format((float)$amount + 50, 2) }}</span>
                            </div>
                        </div>
                    @endif

                    <form wire:submit="executeWithdrawal" class="w-full mt-6">
                        <div>
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 text-left mb-2">Enter Wallet PIN to confirm</p>
                            <input type="password" wire:model="pin" class="block w-full rounded-xl border-zinc-300 px-4 py-4 text-center shadow-sm focus:border-blue-500 focus:ring-blue-500 text-2xl tracking-[0.5em] font-mono font-bold dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white" placeholder="••••" maxlength="4" autocomplete="off" autofocus>
                            @error('pin') <p class="text-xs text-red-500 text-left mt-2">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="mt-6 w-full rounded-[12px] bg-blue-600 px-4 py-4 text-sm font-bold text-white shadow-sm hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                            <span wire:loading.remove wire:target="executeWithdrawal">Withdraw {{ $currency }} {{ number_format((float)$amount, 2) }}</span>
                            <span wire:loading wire:target="executeWithdrawal">Processing...</span>
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>
