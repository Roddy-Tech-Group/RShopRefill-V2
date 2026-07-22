<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\GiftCardTrade;
use App\Enums\TradeStatus;

new
#[Layout('components.layouts.admin')]
#[Title('Trade Details')]
class extends Component {
    use WithFileUploads;

    public GiftCardTrade $trade;
    
    public string $new_status = '';
    public string $status_reason = '';
    public string $new_message = '';
    public $chat_image;

    public function mount(GiftCardTrade $trade)
    {
        $this->trade = $trade->load(['user', 'rate.brand', 'media', 'messages.sender', 'bankAccount', 'payout']);
        $this->new_status = $this->trade->status->value;
    }
    
    public function updateStatus()
    {
        $tradeStatus = TradeStatus::tryFrom($this->new_status);
        if (!$tradeStatus || $tradeStatus === $this->trade->status) return;

        if (in_array($this->new_status, ['rejected', 'need_more_info'])) {
            $this->validate([
                'status_reason' => 'required|string|min:5|max:1000'
            ], [
                'status_reason.required' => 'An explanation is required for this status update.'
            ]);
        }

        $admin = auth('admin')->user();
        $reviewService = app(\App\Domain\GiftCardTrading\Services\TradeReviewService::class);

        if ($this->new_status === 'paying_out') {
            // Let the PayoutOrchestrator handle the transition and funds
            try {
                app(\App\Domain\GiftCardTrading\Services\PayoutOrchestrator::class)->dispatchPayout($this->trade);
                \Flux::toast('The automated payout process has been initiated successfully.', variant: 'success');
            } catch (\Exception $e) {
                \Flux::toast('Payout initiation failed: ' . $e->getMessage(), variant: 'danger');
                return;
            }
        } else {
            // Standard transition via Review Service
            $reviewService->updateStatus(
                $this->trade, 
                $tradeStatus, 
                $admin, 
                $this->new_status === 'rejected' ? $this->status_reason : null,
                null
            );

            if ($this->new_status === 'need_more_info') {
                $this->trade->update([
                    'admin_notes' => trim($this->trade->admin_notes . "\nNeed More Info: " . $this->status_reason),
                ]);
                $this->trade->messages()->create([
                    'sender_type' => \App\Models\Admin::class,
                    'sender_id' => $admin->id,
                    'message' => "Update: We need more info. " . $this->status_reason,
                ]);
            }
        }

        $this->trade->refresh();

        // Notify User via the Communication Platform (orders@ mailbox)
        if (in_array($this->new_status, ['under_review', 'approved', 'paid', 'need_more_info', 'rejected'])) {
            app(\App\Domain\Communication\Services\CommunicationManager::class)->send(
                recipient: $this->trade->user,
                category: \App\Domain\Communication\Enums\CommunicationCategory::TradeUpdate,
                title: 'Trade Status Updated',
                message: "Your trade #".substr($this->trade->uuid, 0, 8)." is now ".$this->trade->status->label()."." . ($this->status_reason ? " Admin Note: ".$this->status_reason : ""),
                mailable: new \App\Domain\Communication\Mail\Orders\TradeStatusMail($this->trade, $this->status_reason),
                correlationId: 'trade_status_'.$this->trade->id.'_'.$this->new_status,
                metadata: ['url' => '/dashboard/gift-cards/trades/'.$this->trade->id]
            );
        }

        if ($this->new_status !== 'paying_out') {
            \Flux::toast('Trade status updated to ' . $this->trade->status->label() . ' successfully.', variant: 'success');
        }
        $this->status_reason = '';
        $this->new_status = $this->trade->status->value;
        $this->trade->load(['messages.sender', 'payout']);
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
            'sender_type' => \App\Models\Admin::class,
            'sender_id' => auth('admin')->id(),
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
        \Illuminate\Support\Facades\Cache::put("trade_{$this->trade->id}_typing_admin", true, now()->addSeconds(4));
    }

    public function refreshData()
    {
        $this->trade->refresh();
        $this->trade->load(['messages.sender']);
    }
}; ?>

<div class="mx-auto max-w-6xl space-y-6">
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
                Submitted by <strong>{{ $trade->user->name }}</strong> ({{ $trade->user->email }}) on {{ $trade->created_at->format('M j, Y g:i A') }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            @if($trade->status === TradeStatus::Paid)
                <flux:button href="{{ route('admin.gift-cards.trades.receipt', $trade) }}" variant="primary" icon="document-text">View Receipt</flux:button>
            @endif
            <flux:button href="{{ route('admin.gift-cards.trades.index') }}" variant="subtle" icon="arrow-left">Back to List</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        {{-- Left Column: Details --}}
        <div class="lg:col-span-2 space-y-6">
            
            {{-- Card Details --}}
            <div class="rounded-[12px] border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">Gift Card Details</h2>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-6">
                    <div>
                        <div class="text-sm font-medium text-zinc-500">Brand & Region</div>
                        <div class="mt-1 text-base text-zinc-900 dark:text-white font-medium">
                            {{ $trade->rate->brand->name ?? 'Unknown' }} ({{ $trade->rate->country_code }})
                        </div>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-zinc-500">Declared Value</div>
                        <div class="mt-1 text-base text-zinc-900 dark:text-white font-medium">
                            ${{ number_format($trade->declared_value, 2) }}
                        </div>
                    </div>
                    <div class="sm:col-span-2">
                        <div class="text-sm font-medium text-zinc-500">Claim Code / PIN</div>
                        <div class="mt-1 p-3 bg-zinc-50 dark:bg-zinc-800 rounded-lg font-mono text-lg text-zinc-900 dark:text-white tracking-widest break-all">
                            {{ $trade->code_pin }}
                        </div>
                    </div>
                </div>
            </div>

            {{-- User Contact Details --}}
            <div class="rounded-[12px] border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">User Contact Details</h2>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-6">
                    <div>
                        <div class="text-sm font-medium text-zinc-500">Name</div>
                        <div class="mt-1 text-base text-zinc-900 dark:text-white font-medium">
                            {{ $trade->user->name }}
                        </div>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-zinc-500">Email Address</div>
                        <div class="mt-1 text-base text-zinc-900 dark:text-white font-medium">
                            {{ $trade->user->email }}
                        </div>
                    </div>
                    @if($trade->whatsapp_number)
                        <div class="sm:col-span-2">
                            <div class="text-sm font-medium text-zinc-500">Contact Number</div>
                            <div class="mt-1 text-base text-zinc-900 dark:text-white font-medium">
                                {{ $trade->whatsapp_number }}
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Payout Details --}}
            <div class="rounded-[12px] border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-zinc-900">
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
                        <div class="sm:col-span-2 mt-2 p-4 rounded-lg border border-zinc-100 bg-zinc-50 dark:bg-zinc-800 dark:border-zinc-700">
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

                    @if($trade->payout)
                        <div class="sm:col-span-2 mt-4 p-4 rounded-lg border border-zinc-100 bg-zinc-50 dark:bg-zinc-800 dark:border-zinc-700">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 mb-3">Payout Receipt / Status</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <div class="text-xs text-zinc-500">Status</div>
                                    <div class="text-sm font-medium text-zinc-900 dark:text-white uppercase">{{ $trade->payout->status }}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-zinc-500">Reference</div>
                                    <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ $trade->payout->reference }}</div>
                                </div>
                                <div class="sm:col-span-2">
                                    <div class="text-xs text-zinc-500 mb-1">Gateway Response / Receipt</div>
                                    <div class="text-xs font-mono bg-zinc-100 dark:bg-zinc-900 p-2 rounded border border-zinc-200 dark:border-zinc-700 overflow-x-auto text-zinc-700 dark:text-zinc-300">
                                        @if($trade->payout->gateway_response)
                                            <pre>{{ json_encode($trade->payout->gateway_response, JSON_PRETTY_PRINT) }}</pre>
                                        @else
                                            <span class="italic text-zinc-400">No receipt data or gateway response available yet.</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Uploaded Images --}}
            <div class="rounded-[12px] border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">Uploaded Images</h2>
                
                @if($trade->media->count() > 0)
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        @foreach($trade->media as $media)
                            <a href="{{ Storage::url($media->file_path) }}" target="_blank" class="block group relative aspect-[4/3] rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 bg-zinc-100 dark:bg-zinc-800">
                                <img src="{{ Storage::url($media->file_path) }}" alt="Card Image" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                                <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition duration-300 flex items-center justify-center">
                                    <flux:icon.magnifying-glass-plus class="text-white opacity-0 group-hover:opacity-100 size-6" />
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-zinc-500 italic">No images were uploaded for this trade.</p>
                @endif
            </div>

        </div>

        {{-- Right Column: Actions & Chat --}}
        <div class="space-y-6">
            
            {{-- Action Controls --}}
            <div class="rounded-[12px] border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">Trade Actions</h2>
                
                <form wire:submit="updateStatus" class="space-y-4">
                    <x-custom-select wire:model.live="new_status" label="Update Status" placeholder="Select new status..." :options="[
                        ['value' => 'pending_review', 'label' => 'Pending Review'],
                        ['value' => 'under_review', 'label' => 'Under Review'],
                        ['value' => 'need_more_info', 'label' => 'Need More Information'],
                        ['value' => 'approved', 'label' => 'Approved'],
                        ['value' => 'paying_out', 'label' => 'Paying Out'],
                        ['value' => 'paid', 'label' => 'Paid'],
                        ['value' => 'rejected', 'label' => 'Rejected'],
                    ]" />
                    
                    @if(in_array($new_status, ['need_more_info', 'rejected']))
                        <flux:textarea wire:model="status_reason" label="Explanation / Reason" placeholder="Explain what is needed or why it was rejected..." required rows="3" />
                    @endif
                    
                    <flux:button type="submit" variant="primary" class="w-full" :disabled="$new_status === $trade->status->value" wire:target="updateStatus">
                        <span wire:loading.remove wire:target="updateStatus">Confirm Status Change</span>
                        <span wire:loading wire:target="updateStatus" class="flex items-center justify-center gap-2">
                            <svg class="h-4 w-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Updating Status...
                        </span>
                    </flux:button>
                </form>
            </div>

            {{-- Chat / Communication --}}
            <div class="rounded-[12px] border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900 flex flex-col h-[500px]">
                <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon.chat-bubble-left-right class="size-5 text-zinc-500" /> Trade Communication
                    </h2>
                </div>
                
                <div wire:poll.2s="refreshData" class="flex-1 p-4 overflow-y-auto bg-zinc-50 dark:bg-zinc-900/50 flex flex-col gap-4">
                    @forelse($trade->messages as $msg)
                        @php
                            $isAdmin = $msg->sender_type === \App\Models\Admin::class;
                        @endphp
                        <div class="flex flex-col {{ $isAdmin ? 'items-end' : 'items-start' }}">
                            <div class="text-[10px] text-zinc-400 mb-1 px-1">
                                {{ $isAdmin ? 'You (Admin)' : $msg->sender->name }} • {{ $msg->created_at->format('g:i A') }}
                            </div>
                            <div class="px-4 py-2 rounded-2xl max-w-[85%] text-sm {{ $isAdmin ? 'bg-blue-600 text-white rounded-br-none' : 'bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-zinc-900 dark:text-zinc-100 rounded-bl-none shadow-sm' }}">
                                @if($msg->image_path)
                                    <a href="{{ Storage::url($msg->image_path) }}" target="_blank" class="block mb-2">
                                        <img src="{{ Storage::url($msg->image_path) }}" class="rounded-lg max-w-full h-auto object-cover max-h-48 border border-white/20" alt="Attachment">
                                    </a>
                                @endif
                                {{ $msg->message }}
                            </div>
                        </div>
                    @empty
                        <div class="flex-1 flex flex-col items-center justify-center text-center my-auto opacity-50">
                            <flux:icon.chat-bubble-oval-left-ellipsis class="size-10 text-zinc-400 mb-2" />
                            <p class="text-sm text-zinc-500">No messages yet.</p>
                            <p class="text-xs text-zinc-400 mt-1">Send a message to request more info.</p>
                        </div>
                    @endforelse

                    @if(\Illuminate\Support\Facades\Cache::get("trade_{$trade->id}_typing_user"))
                        <div class="flex items-start">
                            <div class="text-[10px] text-zinc-400 mb-1 px-1 w-full">{{ $trade->user->name }} is typing...</div>
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
                            <img src="{{ $chat_image->temporaryUrl() }}" class="h-20 w-20 object-cover rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <button type="button" wire:click="$set('chat_image', null)" class="absolute -top-2 -right-2 p-1 bg-red-500 text-white rounded-full hover:bg-red-600 shadow">
                                <flux:icon.x-mark class="size-3" />
                            </button>
                        </div>
                    @endif
                    <div class="flex gap-2 items-center">
                        <label class="cursor-pointer text-zinc-400 hover:text-blue-500 transition p-2 bg-zinc-100 dark:bg-zinc-800 rounded-lg">
                            <flux:icon.photo class="size-5" />
                            <input type="file" wire:model="chat_image" accept="image/*" class="hidden">
                        </label>
                        <flux:input wire:model="new_message" wire:keydown.throttle.2000ms="notifyTyping" class="flex-1" placeholder="Type a message..." />
                        <flux:button type="submit" variant="primary" :disabled="empty($new_message) && empty($chat_image)">Send</flux:button>
                    </div>
                </form>
            </div>
