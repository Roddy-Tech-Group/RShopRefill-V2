<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Domain\Card\Services\CardService;
use App\Models\VirtualCard;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.dashboard')] class extends Component {
    public $cards = [];
    public $activeCardId = null;

    // Creation modal state
    public $showCreateModal = false;
    public $createCurrency = 'USD';
    public $createAmount = 5;
    public $createPin = '';

    // PIN Verification modal state
    public $showPinModal = false;
    public $actionPending = null;
    public $verifyPin = '';

    public function mount()
    {
        $this->loadCards();
        if ($this->cards->isNotEmpty()) {
            $this->activeCardId = $this->cards->first()->id;
        }
    }

    public function loadCards()
    {
        $this->cards = VirtualCard::where('user_id', Auth::id())->latest()->get();
    }

    public function getActiveCardProperty()
    {
        return $this->cards->firstWhere('id', $this->activeCardId);
    }

    public function setActiveCard($id)
    {
        $this->activeCardId = $id;
    }

    public function createCard(CardService $cardService)
    {
        $this->validate([
            'createCurrency' => 'required|string',
            'createAmount' => 'required|numeric|min:2',
            'createPin' => 'required|string',
        ]);

        try {
            $cardService->createCard(
                user: Auth::user(),
                currency: $this->createCurrency,
                fundingAmount: $this->createAmount,
                provider: 'maplerad',
                pin: $this->createPin
            );

            $this->loadCards();
            $this->showCreateModal = false;
            $this->createPin = '';
            session()->flash('success', 'Virtual card creation initiated! Your new card will appear shortly.');
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function requirePinFor($action)
    {
        $this->actionPending = $action;
        $this->verifyPin = '';
        $this->showPinModal = true;
        // In a real implementation, you would dispatch a browser event to focus the PIN input
    }

    public function verifyAndExecute()
    {
        // Simple mock for PIN validation logic (usually via a Hash::check on User's PIN)
        // Here we just accept anything for the UI demo, but you would add actual validation
        if (empty($this->verifyPin)) {
            $this->addError('verifyPin', 'PIN is required');
            return;
        }

        $this->showPinModal = false;

        if ($this->actionPending === 'freeze') {
            session()->flash('success', 'Card has been frozen securely.');
        } elseif ($this->actionPending === 'reveal') {
            $this->dispatch('reveal-card-details');
        }
        
        $this->actionPending = null;
        $this->verifyPin = '';
    }
}; ?>

<div class="flex w-full flex-col gap-8 pb-10" x-data="virtualCardHub()">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">Virtual Cards</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Manage your global spending and subscriptions securely.</p>
        </div>
        <div>
            <button @click="$wire.showCreateModal = true" class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-blue-700 hover:shadow-md active:scale-95 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
                Create Card
            </button>
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

    @if (session()->has('error'))
        <div class="rounded-xl bg-red-50 p-4 border border-red-200 dark:bg-red-500/10 dark:border-red-500/20">
            <div class="flex items-center gap-3">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <p class="text-sm font-medium text-red-800 dark:text-red-300">{{ session('error') }}</p>
            </div>
        </div>
    @endif

    @if ($this->cards->isEmpty())
        {{-- Empty State --}}
        <div class="flex flex-col items-center justify-center rounded-[12px] bg-white border border-zinc-200 py-16 px-6 shadow-sm dark:bg-[#1d3252] dark:border-zinc-700/60 text-center">
            <div class="relative flex h-24 w-24 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-500/10 mb-6">
                <svg class="h-10 w-10 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="5" width="20" height="14" rx="2" ry="2"/>
                    <line x1="2" y1="10" x2="22" y2="10"/>
                </svg>
                <div class="absolute -bottom-1 -right-1 flex h-8 w-8 items-center justify-center rounded-full bg-white dark:bg-[#1d3252] shadow-sm">
                    <div class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-500 text-white">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    </div>
                </div>
            </div>
            <h2 class="text-xl font-bold text-zinc-900 dark:text-white">No Virtual Cards</h2>
            <p class="mt-2 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">Create a USD virtual card instantly to pay on global platforms like Netflix, AWS, and Spotify.</p>
            <button @click="$wire.showCreateModal = true" class="mt-8 rounded-xl bg-blue-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-blue-700 transition-colors">
                Create your first card
            </button>
        </div>
    @else
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
            
            {{-- Left Column: Card Visualizer & Quick Actions --}}
            <div class="lg:col-span-5 flex flex-col gap-6">
                
                {{-- 3D Card Scene --}}
                <div class="perspective-1000 relative mx-auto w-full max-w-[360px] cursor-pointer" 
                     @click="flipCard()" 
                     @mousemove="tiltCard($event)" 
                     @mouseleave="resetTilt()"
                     x-ref="cardScene">
                    
                    <div class="card-3d-wrapper relative h-[220px] w-full transform-style-3d transition-transform duration-700 ease-in-out" x-ref="cardWrapper" :class="isFlipped ? 'rotate-y-180' : ''">
                        
                        {{-- Front of Card --}}
                        <div class="card-face card-front absolute inset-0 overflow-hidden rounded-[12px] p-6 shadow-xl backface-hidden"
                             style="background: #122343; border: 1px solid rgba(255,255,255,0.1);">

                            {{-- Chip --}}
                            <div class="mt-2 h-9 w-11 rounded-md border border-amber-600/30 bg-amber-400 opacity-80 shadow-[inset_0_0_8px_rgba(0,0,0,0.2)]"></div>
                            
                            {{-- Network Logo (Mastercard placeholder) --}}
                            <div class="absolute right-5 top-5 flex">
                                <div class="h-8 w-8 rounded-full bg-red-500 opacity-80 mix-blend-screen"></div>
                                <div class="-ml-3 h-8 w-8 rounded-full bg-amber-500 opacity-80 mix-blend-screen"></div>
                            </div>
                            
                            {{-- Card Details --}}
                            <div class="absolute bottom-6 left-6 right-6">
                                <div class="flex items-end justify-between">
                                    <div>
                                        <p class="text-[10px] font-medium uppercase tracking-widest text-indigo-200">Balance</p>
                                        <p class="mt-0.5 text-2xl font-bold tracking-tight text-white">
                                            <span x-text="isRevealed ? '$' + '{{ number_format((float) $this->activeCard->balance, 2) }}' : '$ ••••'"></span>
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-[10px] font-medium tracking-widest text-indigo-200">{{ $this->activeCard->masked_pan ? '••' . substr($this->activeCard->masked_pan, -4) : '••••' }}</p>
                                    </div>
                                </div>
                                <p class="mt-3 text-sm font-semibold tracking-widest text-white/90 uppercase">{{ $this->activeCard->name_on_card ?? Auth::user()->name }}</p>
                            </div>

                            <div class="absolute top-6 left-6 rounded-full bg-white/10 px-2.5 py-0.5 text-[9px] font-bold uppercase tracking-widest text-white">
                                {{ $this->activeCard->status === 'active' ? 'Active' : ucfirst($this->activeCard->status) }}
                            </div>
                        </div>
                        
                        {{-- Back of Card (Flipped) --}}
                        <div class="card-face card-back absolute inset-0 overflow-hidden rounded-[12px] bg-[#122343] shadow-xl backface-hidden rotate-y-180"
                             style="border: 1px solid rgba(255,255,255,0.1);">
                            
                            {{-- Magnetic Stripe --}}
                            <div class="absolute left-0 right-0 top-6 h-12 bg-[#0c1a36]"></div>
                            
                            <div class="absolute left-6 right-6 top-24 flex flex-col gap-4">
                                {{-- Fake sensitive details --}}
                                <div>
                                    <p class="text-[9px] font-medium uppercase tracking-widest text-zinc-400">Card Number</p>
                                    <div class="mt-1 flex h-8 items-center rounded bg-white/10 px-3">
                                        <span class="font-mono text-sm tracking-[0.2em] text-white" x-text="isRevealed ? '4532 8812 0049 1123' : '•••• •••• •••• ' + '{{ substr($this->activeCard->masked_pan ?? '0000', -4) }}'"></span>
                                    </div>
                                </div>
                                <div class="flex gap-4">
                                    <div class="flex-1">
                                        <p class="text-[9px] font-medium uppercase tracking-widest text-zinc-400">Valid Thru</p>
                                        <div class="mt-1 flex h-8 items-center rounded bg-white/10 px-3">
                                            <span class="font-mono text-sm text-white" x-text="isRevealed ? '12/28' : '••/••'"></span>
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <p class="text-[9px] font-medium uppercase tracking-widest text-zinc-400">CVV</p>
                                        <div class="mt-1 flex h-8 items-center justify-end rounded bg-white/10 px-3">
                                            <span class="font-mono text-sm tracking-[0.2em] text-white" x-text="isRevealed ? '821' : '•••'"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            {{-- Re-flip hint --}}
                            <div class="absolute bottom-4 w-full text-center">
                                <span class="text-[10px] text-zinc-500">Tap to flip back</span>
                            </div>
                        </div>

                    </div>
                </div>

                {{-- Card Selector (if multiple cards) --}}
                @if ($this->cards->count() > 1)
                    <div class="flex justify-center gap-2 mt-2">
                        @foreach($this->cards as $c)
                            <button wire:click="setActiveCard('{{ $c->id }}')" class="h-2 w-8 rounded-full transition-colors {{ $this->activeCardId === $c->id ? 'bg-blue-600' : 'bg-zinc-300 dark:bg-zinc-700' }}"></button>
                        @endforeach
                    </div>
                @endif

                {{-- Action Grid --}}
                <div class="grid grid-cols-3 gap-3">
                    <button @click="$wire.requirePinFor('reveal')" class="flex flex-col items-center justify-center gap-2 rounded-[12px] bg-white p-4 shadow-sm ring-1 ring-zinc-200 transition-colors hover:bg-zinc-50 active:scale-95 dark:bg-[#1d3252] dark:ring-zinc-700/60 dark:hover:bg-white/5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
                            <svg class="h-5 w-5" x-show="!isRevealed" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.644C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <svg class="h-5 w-5" x-show="isRevealed" x-cloak fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/></svg>
                        </span>
                        <span class="text-[11px] font-semibold text-zinc-700 dark:text-zinc-300" x-text="isRevealed ? 'Hide Details' : 'Show Details'"></span>
                    </button>

                    <button @click="$wire.requirePinFor('freeze')" class="flex flex-col items-center justify-center gap-2 rounded-[12px] bg-white p-4 shadow-sm ring-1 ring-zinc-200 transition-colors hover:bg-zinc-50 active:scale-95 dark:bg-[#1d3252] dark:ring-zinc-700/60 dark:hover:bg-white/5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                        </span>
                        <span class="text-[11px] font-semibold text-zinc-700 dark:text-zinc-300">Freeze Card</span>
                    </button>

                    <button class="flex flex-col items-center justify-center gap-2 rounded-[12px] bg-white p-4 shadow-sm ring-1 ring-zinc-200 transition-colors hover:bg-zinc-50 active:scale-95 dark:bg-[#1d3252] dark:ring-zinc-700/60 dark:hover:bg-white/5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 010 3.46"/></svg>
                        </span>
                        <span class="text-[11px] font-semibold text-zinc-700 dark:text-zinc-300">Set Limits</span>
                    </button>
                </div>
            </div>

            {{-- Right Column: Transaction History --}}
            <div class="lg:col-span-7">
                <div class="flex h-full flex-col overflow-hidden rounded-[12px] bg-white border border-zinc-200 shadow-sm dark:bg-[#1d3252] dark:border-zinc-700/60">
                    <div class="border-b border-zinc-100 p-5 dark:border-zinc-700/60 flex items-center justify-between">
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">Recent Activity</h3>
                        <div class="text-xs font-semibold text-blue-600 dark:text-blue-400">View All</div>
                    </div>
                    
                    <div class="flex-1 p-5">
                        {{-- Placeholder Transactions --}}
                        <div class="flex flex-col gap-5">
                            @foreach([
                                ['icon' => 'spotify', 'bg' => 'bg-[#1DB954]', 'name' => 'Spotify Subscription', 'date' => 'Today, 10:42 AM', 'amt' => '-$10.99'],
                                ['icon' => 'netflix', 'bg' => 'bg-[#E50914]', 'name' => 'Netflix Premium', 'date' => 'Yesterday, 09:12 PM', 'amt' => '-$22.99'],
                                ['icon' => 'aws', 'bg' => 'bg-[#FF9900]', 'name' => 'Amazon Web Services', 'date' => 'Jul 2, 2026', 'amt' => '-$4.50'],
                                ['icon' => 'topup', 'bg' => 'bg-emerald-500', 'name' => 'Card Funded', 'date' => 'Jul 1, 2026', 'amt' => '+$50.00', 'credit' => true],
                            ] as $t)
                                <div class="flex items-center gap-4">
                                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full {{ $t['bg'] }} text-white shadow-sm">
                                        <span class="text-sm font-bold">{{ strtoupper(substr($t['icon'], 0, 1)) }}</span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $t['name'] }}</p>
                                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $t['date'] }}</p>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p class="text-sm font-bold {{ isset($t['credit']) ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-900 dark:text-white' }}">{{ $t['amt'] }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

        </div>
    @endif

    {{-- Create Card Modal --}}
    <div x-show="$wire.showCreateModal" x-cloak class="relative z-50" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div x-show="$wire.showCreateModal" x-transition.opacity class="fixed inset-0 bg-zinc-900/40 backdrop-blur-sm transition-opacity"></div>
        <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
            <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div x-show="$wire.showCreateModal" 
                     x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" 
                     x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" 
                     @click.outside="$wire.showCreateModal = false"
                     class="relative transform overflow-hidden rounded-[12px] bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md dark:bg-[#122343] dark:ring-1 dark:ring-white/10">
                    
                    <div class="px-6 pb-6 pt-8 sm:p-8">
                        <div class="flex items-center justify-center h-14 w-14 rounded-full bg-blue-50 dark:bg-blue-500/10 mx-auto mb-6">
                            <svg class="h-7 w-7 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2" ry="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        </div>
                        <h3 class="text-center text-xl font-bold leading-6 text-zinc-900 dark:text-white" id="modal-title">Create Virtual Card</h3>
                        <p class="mt-2 text-center text-sm text-zinc-500 dark:text-zinc-400">Instantly generate a USD virtual card for your global payments. Creation fee applies.</p>
                        
                        <form wire:submit="createCard" class="mt-8 space-y-5">
                            <div>
                                <label class="block text-sm font-semibold text-zinc-900 dark:text-zinc-300">Currency</label>
                                <div class="mt-1 flex items-center justify-between rounded-xl border border-zinc-300 bg-zinc-50 px-4 py-3 dark:border-zinc-700/60 dark:bg-[#1d3252]">
                                    <div class="flex items-center gap-3">
                                        <img src="{{ \App\Models\Product::flagUrl('US') }}" class="h-6 w-6 rounded-full object-cover">
                                        <span class="font-bold text-zinc-900 dark:text-white">USD</span>
                                    </div>
                                    <span class="text-xs font-semibold uppercase tracking-wider text-blue-600 dark:text-blue-400">Default</span>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold text-zinc-900 dark:text-zinc-300">Initial Funding Amount ($)</label>
                                <input type="number" wire:model="createAmount" min="2" class="mt-1 block w-full rounded-xl border-zinc-300 px-4 py-3 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-base font-bold dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white" placeholder="0.00">
                                @error('createAmount') <span class="text-xs text-red-500 mt-1">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-zinc-900 dark:text-zinc-300">Wallet PIN</label>
                                <input type="password" wire:model="createPin" class="mt-1 block w-full rounded-xl border-zinc-300 px-4 py-3 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-base tracking-[0.3em] font-mono dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white" placeholder="••••">
                                @error('createPin') <span class="text-xs text-red-500 mt-1">{{ $message }}</span> @enderror
                            </div>
                            
                            <div class="mt-6">
                                <button type="submit" class="w-full flex justify-center rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-bold text-white shadow-sm hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                                    <span wire:loading.remove wire:target="createCard">Create & Fund Card</span>
                                    <span wire:loading wire:target="createCard" class="flex items-center gap-2">
                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                        Processing...
                                    </span>
                                </button>
                                <button type="button" @click="$wire.showCreateModal = false" class="mt-3 w-full rounded-xl bg-white px-4 py-3 text-sm font-bold text-zinc-700 shadow-sm ring-1 ring-inset ring-zinc-300 hover:bg-zinc-50 dark:bg-transparent dark:text-zinc-300 dark:ring-zinc-700 dark:hover:bg-white/5 transition-colors">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Transaction PIN Interceptor Modal --}}
    <div x-show="$wire.showPinModal" x-cloak class="relative z-[100]" aria-labelledby="pin-modal-title" role="dialog" aria-modal="true">
        <div x-show="$wire.showPinModal" x-transition.opacity class="fixed inset-0 bg-zinc-900/60 backdrop-blur-md transition-opacity"></div>
        <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
            <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div x-show="$wire.showPinModal" 
                     x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" 
                     x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" 
                     @click.outside="$wire.showPinModal = false"
                     class="relative transform overflow-hidden rounded-[12px] bg-white text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-sm dark:bg-[#122343] dark:ring-1 dark:ring-white/10">
                    
                    <div class="px-6 pb-6 pt-8 sm:p-8">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-500/10 mb-4">
                            <svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                        </div>
                        <h3 class="text-center text-lg font-bold leading-6 text-zinc-900 dark:text-white" id="pin-modal-title">Security Check</h3>
                        <p class="mt-2 text-center text-sm text-zinc-500 dark:text-zinc-400">Enter your 4-digit Wallet PIN to proceed with this sensitive action.</p>
                        
                        <form wire:submit="verifyAndExecute" class="mt-6">
                            <div>
                                <input type="password" wire:model="verifyPin" x-ref="pinInput" class="block w-full rounded-xl border-zinc-300 px-4 py-4 text-center shadow-sm focus:border-blue-500 focus:ring-blue-500 text-2xl tracking-[0.5em] font-mono font-bold dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white" placeholder="••••" maxlength="4" autocomplete="off">
                                @error('verifyPin') <p class="text-xs text-red-500 text-center mt-2">{{ $message }}</p> @enderror
                            </div>
                            
                            <div class="mt-6 flex gap-3">
                                <button type="button" @click="$wire.showPinModal = false" class="w-full rounded-xl bg-white px-4 py-3 text-sm font-bold text-zinc-700 shadow-sm ring-1 ring-inset ring-zinc-300 hover:bg-zinc-50 dark:bg-transparent dark:text-zinc-300 dark:ring-zinc-700 dark:hover:bg-white/5 transition-colors">
                                    Cancel
                                </button>
                                <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-3 text-sm font-bold text-white shadow-sm hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-colors">
                                    <span wire:loading.remove wire:target="verifyAndExecute">Verify</span>
                                    <span wire:loading wire:target="verifyAndExecute">Checking...</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Inline script for 3D CSS animations and interactions --}}
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('virtualCardHub', () => ({
            isFlipped: false,
            isRevealed: false,
            
            init() {
                // Listen for Livewire event dispatched upon PIN success
                window.addEventListener('reveal-card-details', () => {
                    this.isRevealed = !this.isRevealed;
                    if (this.isRevealed && !this.isFlipped) {
                        this.flipCard(); // Auto flip to back when revealed
                    }
                });
            },
            
            flipCard() {
                this.isFlipped = !this.isFlipped;
                this.resetTilt();
            },
            
            tiltCard(e) {
                // Ignore tilt if we are on mobile (where hover is jittery)
                if(window.innerWidth < 1024) return;
                
                const card = this.$refs.cardScene;
                const rect = card.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                // Calculate rotation (max 15 degrees)
                const rotateY = ((x / rect.width) - 0.5) * 20;
                const rotateX = ((y / rect.height) - 0.5) * -20;
                
                // Apply rotation depending on if it's flipped or not
                const flipOffset = this.isFlipped ? 180 : 0;
                
                this.$refs.cardWrapper.style.transform = `rotateX(${rotateX}deg) rotateY(${rotateY + flipOffset}deg) scale3d(1.02, 1.02, 1.02)`;
                // Turn off transition during hover for instant mouse tracking
                this.$refs.cardWrapper.style.transition = 'none';
            },
            
            resetTilt() {
                const flipOffset = this.isFlipped ? 180 : 0;
                this.$refs.cardWrapper.style.transform = `rotateX(0deg) rotateY(${flipOffset}deg) scale3d(1, 1, 1)`;
                // Restore transition for smooth snapping back and flipping
                this.$refs.cardWrapper.style.transition = 'transform 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
            }
        }));
    });
</script>

<style>
    .perspective-1000 { perspective: 1000px; }
    .transform-style-3d { transform-style: preserve-3d; }
    .backface-hidden { -webkit-backface-visibility: hidden; backface-visibility: hidden; }
    .rotate-y-180 { transform: rotateY(180deg); }
    
    /* Make sure Safari doesn't flicker during flip */
    .card-face {
        -webkit-transform: translate3d(0,0,0);
    }
</style>
