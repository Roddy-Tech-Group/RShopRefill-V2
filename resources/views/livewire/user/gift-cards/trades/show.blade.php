<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\GiftCardTrade;
use App\Enums\TradeStatus;

new
#[Layout('components.layouts.dashboard')]
#[Title('Trade Details')]
class extends Component {
    use WithFileUploads;
    
    public GiftCardTrade $trade;
    public string $new_message = '';
    public $chat_image;

    public function mount(GiftCardTrade $trade)
    {
        // Ensure user can only view their own trade
        if ($trade->user_id !== auth()->id()) {
            abort(403);
        }
        $this->trade = $trade->load(['rate.brand', 'media', 'messages.sender', 'bankAccount']);
    }

    public function sendMessage()
    {
        $this->validate([
            'new_message' => 'required_without:chat_image|string|max:1000',
            'chat_image' => 'nullable|image|max:5120',
        ]);

        $imagePath = null;
        if ($this->chat_image) {
            $imagePath = $this->chat_image->store('trade-messages/' . date('Y/m'), 'public');
        }

        $this->trade->messages()->create([
            'sender_type' => \App\Models\User::class,
            'sender_id' => auth()->id(),
            'message' => $this->new_message ?: '',
            'image_path' => $imagePath,
        ]);

        $this->new_message = '';
        $this->chat_image = null;
        $this->trade->refresh();
        $this->trade->load(['messages.sender']);
    }

    public function notifyTyping()
    {
        \Illuminate\Support\Facades\Cache::put("trade_{$this->trade->id}_typing_user", true, now()->addSeconds(4));
    }

    public function refreshData()
    {
        $this->trade->refresh();
        $this->trade->load(['messages.sender']);
    }
}; ?>

<div wire:poll.5s="refreshData" class="mx-auto max-w-5xl space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                    Trade #{{ substr($trade->uuid, 0, 8) }}
                </h1>
                <flux:badge color="{{ $trade->status->color() }}" class="uppercase">{{ $trade->status->label() }}</flux:badge>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                Submitted on {{ $trade->created_at->format('M j, Y g:i A') }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            @if($trade->status === TradeStatus::Paid)
                <flux:button href="{{ route('dashboard.gift-cards.trades.receipt', $trade) }}" variant="primary" icon="document-text">View Receipt</flux:button>
            @endif
            <flux:button href="{{ route('dashboard.gift-cards.history') }}" variant="subtle" icon="arrow-left">Back to History</flux:button>
        </div>
    </div>

    @if($trade->status === TradeStatus::NeedMoreInfo)
        <div class="rounded-xl border border-orange-200 bg-orange-50 p-4 dark:border-orange-500/20 dark:bg-orange-500/10">
            <div class="flex items-start gap-3">
                <flux:icon.exclamation-triangle class="mt-0.5 size-5 text-orange-500" />
                <div>
                    <h3 class="text-sm font-semibold text-orange-800 dark:text-orange-400">Action Required</h3>
                    <p class="mt-1 text-sm text-orange-700 dark:text-orange-300">
                        The admin has requested more information regarding your trade. Please review the messages below and reply.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        {{-- Left Column: Details --}}
        <div class="lg:col-span-2 space-y-6">
            

            {{-- Payout Details --}}
            <div class="rounded-[12px] border border-zinc-200 bg-[#eff6ff] p-6 shadow-sm shadow-zinc-900/[0.04] dark:border-zinc-700 dark:shadow-none">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">Payout Information</h2>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-6">
                    <div>
                        <div class="text-sm font-medium text-zinc-500">Expected Payout</div>
                        <div class="mt-1 text-2xl font-bold text-blue-600 dark:text-blue-400">
                            {{ \App\Domain\Shared\Enums\Currency::tryFrom($trade->payout_currency)?->symbol() ?? $trade->payout_currency . ' ' }}{{ number_format($trade->calculated_payout, 2) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-zinc-500">Payout Method</div>
                        <div class="mt-1">
                            @if($trade->payout_method === 'wallet')
                                <flux:badge color="purple" icon="wallet">Rshop Wallet</flux:badge>
                            @else
                                <flux:badge color="blue" icon="building-library">Bank Transfer</flux:badge>
                            @endif
                        </div>
                    </div>
                    
                    @if($trade->payout_method === 'bank' && $trade->bankAccount)
                        <div class="sm:col-span-2 mt-2 p-4 rounded-[12px] border border-zinc-100 bg-zinc-50 dark:bg-zinc-800 dark:border-zinc-700">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 mb-3">Bank Account Details</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <div class="text-xs text-zinc-500">Bank Name</div>
                                    <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ $trade->bankAccount->bank_name }}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-zinc-500">Account Number</div>
                                    <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ $trade->bankAccount->account_number }}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-zinc-500">Account Name</div>
                                    <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ $trade->bankAccount->account_name }}</div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
            

        </div>

        {{-- Right Column: Chat --}}
        <div class="space-y-6">
            
            {{-- Chat / Communication --}}
            <div class="rounded-[12px] border border-zinc-200 bg-[#eff6ff] shadow-sm shadow-zinc-900/[0.04] dark:border-zinc-700 dark:shadow-none flex flex-col h-[600px]">
                <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon.chat-bubble-left-right class="size-5 text-zinc-500" /> Trade Support
                    </h2>
                </div>
                
                <div wire:poll.2s="refreshData" class="flex-1 p-4 overflow-y-auto bg-zinc-50 dark:bg-zinc-900/50 flex flex-col gap-4">
                    @forelse($trade->messages as $msg)
                        @php
                            $isUser = $msg->sender_type === \App\Models\User::class;
                        @endphp
                        <div class="flex flex-col {{ $isUser ? 'items-end' : 'items-start' }}">
                            <div class="text-[10px] text-zinc-400 mb-1 px-1">
                                {{ $isUser ? 'You' : 'Admin Support' }} • {{ $msg->created_at->format('g:i A') }}
                            </div>
                            <div class="px-4 py-2 rounded-2xl max-w-[85%] text-sm {{ $isUser ? 'bg-blue-600 text-white rounded-br-none' : 'bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-zinc-900 dark:text-zinc-100 rounded-bl-none shadow-sm' }}">
                                @if($msg->image_path)
                                    <a href="{{ Storage::url($msg->image_path) }}" target="_blank" class="block mb-2">
                                        <img src="{{ Storage::url($msg->image_path) }}" class="rounded-[12px] max-w-full h-auto object-cover max-h-48 border border-white/20" alt="Attachment">
                                    </a>
                                @endif
                                {{ $msg->message }}
                            </div>
                        </div>
                    @empty
                        <div class="flex-1 flex flex-col items-center justify-center text-center my-auto opacity-50">
                            <flux:icon.chat-bubble-oval-left-ellipsis class="size-10 text-zinc-400 mb-2" />
                            <p class="text-sm text-zinc-500">No messages yet.</p>
                            <p class="text-xs text-zinc-400 mt-1">If there is an issue, an admin will contact you here.</p>
                        </div>
                    @endforelse

                    @if(\Illuminate\Support\Facades\Cache::get("trade_{$trade->id}_typing_admin"))
                        <div class="flex items-start">
                            <div class="text-[10px] text-zinc-400 mb-1 px-1 w-full">Admin Support is typing...</div>
                        </div>
                        <div class="flex items-start -mt-3">
                            <div class="px-4 py-2 rounded-2xl bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 shadow-sm rounded-bl-none flex gap-1.5 items-center h-9">
                                <div class="w-1.5 h-1.5 bg-zinc-400 rounded-full animate-bounce" style="animation-delay: 0s"></div>
                                <div class="w-1.5 h-1.5 bg-zinc-400 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
                                <div class="w-1.5 h-1.5 bg-zinc-400 rounded-full animate-bounce" style="animation-delay: 0.4s"></div>
                            </div>
                        </div>
                    @endif
                </div>
                
                <form wire:submit="sendMessage" class="p-4 border-t border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                    @if($chat_image)
                        <div class="mb-3 relative inline-block">
                            <img src="{{ $chat_image->temporaryUrl() }}" class="h-20 w-20 object-cover rounded-[12px] border border-zinc-200 dark:border-zinc-700">
                            <button type="button" wire:click="$set('chat_image', null)" class="absolute -top-2 -right-2 p-1 bg-red-500 text-white rounded-full hover:bg-red-600 shadow">
                                <flux:icon.x-mark class="size-3" />
                            </button>
                        </div>
                    @endif
                    <div class="flex gap-2 items-center">
                        <label class="cursor-pointer text-zinc-400 hover:text-blue-500 transition p-2 bg-zinc-100 dark:bg-zinc-800 rounded-[12px]">
                            <flux:icon.photo class="size-5" />
                            <input type="file" wire:model="chat_image" accept="image/*" class="hidden">
                        </label>
                        <flux:input wire:model="new_message" wire:keydown.throttle.2000ms="notifyTyping" class="flex-1" placeholder="Type a message..." />
                        <flux:button type="submit" variant="primary" :disabled="empty($new_message) && empty($chat_image)">Send</flux:button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>
