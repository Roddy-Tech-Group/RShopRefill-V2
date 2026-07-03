<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\GiftCardRate;
use App\Domain\GiftCardTrading\Services\TradeSubmissionService;
use App\Domain\GiftCardTrading\Services\RateEngine;
use App\Models\BankAccount;
use Illuminate\Validation\Rule;

new
#[Layout('components.layouts.dashboard')]
#[Title('Trade Gift Card')]
class extends Component {
    use WithFileUploads;

    public $selected_card = '';
    public $payout_currency = '';
    public $rate_id;
    
    public $declared_value;
    public $payout_method = 'wallet';
    public $bank_account_id;
    public $code_pin;
    // Media uploads
    public $card_image;
    public $receipt_image;
    
    // Contact
    public $whatsapp_country_code = '+234';
    public $whatsapp_number;

    public $calculated_payout = 0;
    public $payout_currency_label = '';
    public $input_currency_label = '';

    // Bank Account Modal
    public $flutterwave_banks = [];
    public $new_bank_code = '';
    public $new_account_number = '';
    public $resolved_account_name = '';
    public $is_verifying_bank = false;
    public $manual_name_entry = false;

    public function updatedSelectedCard()
    {
        $this->payout_currency = '';
        $this->rate_id = null;
        $this->calculatePayout();
        
        if ($this->selected_card) {
            [$bId, $cCode] = explode('_', $this->selected_card);
            $brand = \App\Models\GiftCardBrand::find($bId);
            if ($brand) {
                $map = [
                    'US' => 'USD',
                    'GB' => 'GBP',
                    'CA' => 'CAD',
                    'AU' => 'AUD',
                    'EU' => 'EUR',
                ];
                $this->input_currency_label = $map[$cCode] ?? $brand->currency;
            }
        }
    }

    public function updatedPayoutCurrency()
    {
        $this->resolveRateId();
        $this->calculatePayout();
    }

    public function updatedDeclaredValue()
    {
        $this->calculatePayout();
    }

    protected function resolveRateId()
    {
        $this->rate_id = null;
        if ($this->selected_card && $this->payout_currency) {
            [$bId, $cCode] = explode('_', $this->selected_card);
            $rate = GiftCardRate::where('brand_id', $bId)
                ->where('country_code', $cCode)
                ->where('currency', $this->payout_currency)
                ->active()
                ->first();
            
            if ($rate) {
                $this->rate_id = $rate->id;
            }
        }
    }

    public function calculatePayout()
    {
        if (!$this->rate_id || !$this->declared_value) {
            $this->calculated_payout = 0;
            $this->payout_currency_label = '';
            return;
        }

        $rate = GiftCardRate::find($this->rate_id);
        if ($rate) {
            try {
                $engine = app(RateEngine::class);
                $this->calculated_payout = $engine->calculatePayout($rate, (float) $this->declared_value);
                $this->payout_currency_label = \App\Domain\Shared\Enums\Currency::tryFrom($rate->currency)?->symbol() ?? $rate->currency . ' ';
            } catch (\Exception $e) {
                $this->calculated_payout = 0;
                $this->payout_currency_label = '';
            }
        }
    }

    public function updatedNewAccountNumber()
    {
        $this->verifyBankAccount();
    }

    public function updatedNewBankCode()
    {
        $this->verifyBankAccount();
    }

    public function openAddBankModal()
    {
        $this->new_bank_code = '';
        $this->new_account_number = '';
        $this->resolved_account_name = '';
        $this->is_verifying_bank = false;
        $this->manual_name_entry = false;
        
        $countryCode = match ($this->payout_currency) {
            'XAF' => 'CM',
            'GHS' => 'GH',
            'KES' => 'KE',
            'UGX' => 'UG',
            'TZS' => 'TZ',
            'ZAR' => 'ZA',
            'USD' => 'US',
            'GBP' => 'GB',
            'EUR' => 'EU',
            'NGN' => 'NG',
            default => 'NG'
        };
        
        $this->flutterwave_banks = \Illuminate\Support\Facades\Cache::remember('flutterwave_banks_' . $countryCode, 3600, function () use ($countryCode) {
            return app(\App\Domain\Payment\Services\FlutterwaveService::class)->getBanks($countryCode);
        });
        
        $this->dispatch('modal-open', name: 'add-bank-modal'); // For alpine fallback
    }

    public function verifyBankAccount()
    {
        $this->resolved_account_name = '';
        $this->manual_name_entry = false;
        
        if (strlen($this->new_account_number) >= 8 && $this->new_bank_code) {
            $this->is_verifying_bank = true;
            
            try {
                $flw = app(\App\Domain\Payment\Services\FlutterwaveService::class);
                $result = $flw->resolveBankAccount($this->new_account_number, $this->new_bank_code);
                
                if ($result && isset($result['account_name'])) {
                    $this->resolved_account_name = $result['account_name'];
                } else {
                    $this->manual_name_entry = true;
                    Flux::toast('Automatic verification failed. Please enter your name manually.', variant: 'warning');
                }
            } catch (\Exception $e) {
                $this->manual_name_entry = true;
                Flux::toast('Automatic verification unavailable for this bank.', variant: 'warning');
            }
            
            $this->is_verifying_bank = false;
        }
    }

    public function saveBankAccount()
    {
        $this->validate([
            'new_bank_code' => 'required|string',
            'new_account_number' => 'required|string|min:10',
            'resolved_account_name' => 'required|string',
        ], [
            'resolved_account_name.required' => 'Please wait for the account to be verified before saving.',
        ]);
        
        $bankName = collect($this->flutterwave_banks)->firstWhere('code', $this->new_bank_code)['name'] ?? $this->new_bank_code;

        $bankAccount = \App\Models\BankAccount::create([
            'user_id' => auth()->id(),
            'bank_name' => $bankName,
            'bank_code' => $this->new_bank_code,
            'account_number' => $this->new_account_number,
            'account_name' => $this->resolved_account_name,
        ]);

        $this->bank_account_id = $bankAccount->id;
        $this->payout_method = 'bank';
        Flux::toast('Bank account added successfully!', variant: 'success');
        $this->dispatch('modal-close', name: 'add-bank-modal');
    }

    public function submitTrade(TradeSubmissionService $service)
    {
        $this->resolveRateId();

        $this->validate([
            'rate_id' => 'required|exists:gift_card_rates,id',
            'declared_value' => 'required|numeric|min:1',
            'payout_method' => 'required|in:wallet,bank',
            // For a bank payout the account must exist AND belong to this user - stops a
            // tampered bank_account_id from redirecting the payout to someone else.
            'bank_account_id' => [
                'required_if:payout_method,bank',
                Rule::when($this->payout_method === 'bank', [
                    Rule::exists('bank_accounts', 'id')->where('user_id', auth()->id()),
                ]),
            ],
            'code_pin' => 'required|string',
            'card_image' => 'required|image|max:5120', // 5MB max
            'receipt_image' => 'nullable|image|max:5120',
            'whatsapp_country_code' => 'required|string',
            'whatsapp_number' => 'required|string|max:20',
        ], [
            'rate_id.required' => 'Please select a valid gift card and payout currency.',
        ]);

        $rate = GiftCardRate::findOrFail($this->rate_id);

        $images = [
            ['file' => $this->card_image, 'type' => 'front'],
        ];

        if ($this->receipt_image) {
            $images[] = ['file' => $this->receipt_image, 'type' => 'receipt'];
        }

        $fullWhatsapp = null;
        if ($this->whatsapp_number) {
            $fullWhatsapp = $this->whatsapp_country_code . ' ' . $this->whatsapp_number;
        }

        try {
            $trade = $service->submitTrade(
                userId: auth()->id(),
                rate: $rate,
                declaredValue: $this->declared_value,
                payoutMethod: $this->payout_method,
                bankAccountId: $this->payout_method === 'bank' ? $this->bank_account_id : null,
                codePin: $this->code_pin,
                whatsappNumber: $fullWhatsapp,
                images: $images
            );

            Flux::toast('Trade submitted successfully!', variant: 'success');
            return $this->redirectRoute('dashboard.gift-cards.trades.show', ['trade' => $trade->id], navigate: true);

        } catch (\Exception $e) {
            Flux::toast($e->getMessage(), variant: 'danger');
        }
    }

    public function with(): array
    {
        $allRates = GiftCardRate::with('brand')->active()->get();
        
        $cards = $allRates->map(function ($r) {
            return [
                'id' => $r->brand_id . '_' . $r->country_code,
                'name' => $r->brand->name . ' (' . $r->country_code . ')',
            ];
        })->unique('id')->values();

        $availableCurrencies = [];
        if ($this->selected_card) {
            [$bId, $cCode] = explode('_', $this->selected_card);
            $availableCurrencies = $allRates->where('brand_id', $bId)->where('country_code', $cCode)->map(function ($r) {
                return [
                    'currency' => $r->currency,
                    'rate' => $r->rate,
                ];
            })->values();
        }

        $dialOptions = [];
        $countries = config('countries.codes') ?? [];
        $dials = config('dial_codes.codes') ?? [];
        foreach ($countries as $name => $code) {
            if (isset($dials[$code])) {
                $dial = $dials[$code];
                $dialOptions[] = ['value' => $dial, 'label' => "$name ($dial)"];
            }
        }
        usort($dialOptions, fn($a, $b) => strcmp($a['label'], $b['label']));

        return [
            'cards' => $cards,
            'availableCurrencies' => $availableCurrencies,
            'banks' => BankAccount::where('user_id', auth()->id())->get(),
            'cardOptions' => collect($cards)->map(fn($c) => ['value' => $c['id'], 'label' => $c['name']])->toArray(),
            'currencyOptions' => collect($availableCurrencies)->map(fn($c) => ['value' => $c['currency'], 'label' => $c['currency'] . ' (Rate: ' . number_format($c['rate'], 2) . ')'])->toArray(),
            'savedBankOptions' => BankAccount::where('user_id', auth()->id())->get()->map(fn($b) => ['value' => $b->id, 'label' => $b->bank_name . ' - ' . $b->account_number])->toArray(),
            'dialOptions' => $dialOptions,
        ];
    }
};
?>

<div class="mx-auto max-w-2xl">
    <x-slot:heading>Trade Gift Card</x-slot:heading>
    <x-slot:subheading>Sell your unused gift cards for instant cash.</x-slot:subheading>

    <form wire:submit="submitTrade" class="mt-6 flex flex-col gap-6">
        
        {{-- Card Selection --}}
        <div class="rounded-[12px] border border-zinc-200 bg-[#eff6ff] p-6 shadow-sm shadow-zinc-900/[0.04] dark:border-zinc-700 dark:shadow-none">
            <h3 class="mb-4 text-base font-semibold text-zinc-900 dark:text-white">1. Card Details</h3>
            
            <div class="flex flex-col gap-4">
                <x-custom-select 
                    wire:model.live="selected_card" 
                    label="Select Gift Card" 
                    :options="$cardOptions" 
                    placeholder="Choose a card to trade..." 
                    searchable="true"
                    required="true" 
                />

                <flux:input 
                    wire:model.live.debounce.500ms="declared_value" 
                    type="number" 
                    step="0.01" 
                    label="Card Value{{ $input_currency_label ? ' (' . $input_currency_label . ')' : '' }}" 
                    placeholder="e.g. 100" 
                    required
                />

                <x-custom-select 
                    wire:model.live="payout_currency" 
                    label="Payout Currency" 
                    :options="$currencyOptions" 
                    placeholder="Choose payout currency..." 
                    required="true" 
                />

                @if($rate_id && $declared_value)
                    <div class="mt-2 rounded-[12px] bg-blue-50 p-4 dark:bg-blue-500/10">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-blue-900 dark:text-blue-300">You will receive:</span>
                            <span class="text-2xl font-bold text-blue-700 dark:text-blue-400">
                                {{ $payout_currency_label }}{{ number_format($calculated_payout, 2) }}
                            </span>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- E-Code / PIN --}}
        <div class="rounded-[12px] border border-zinc-200 bg-[#eff6ff] p-6 shadow-sm shadow-zinc-900/[0.04] dark:border-zinc-700 dark:shadow-none">
            <h3 class="mb-4 text-base font-semibold text-zinc-900 dark:text-white">2. Card Code / PIN</h3>
            <p class="mb-4 text-sm text-zinc-500">Enter the redemption code or PIN exactly as it appears on the card.</p>
            
            <flux:input wire:model="code_pin" label="Claim Code or PIN" placeholder="XXXX-XXXX-XXXX-XXXX" required />
        </div>

        {{-- Image Uploads --}}
        <div class="rounded-[12px] border border-zinc-200 bg-[#eff6ff] p-6 shadow-sm shadow-zinc-900/[0.04] dark:border-zinc-700 dark:shadow-none">
            <h3 class="mb-4 text-base font-semibold text-zinc-900 dark:text-white">3. Upload Image</h3>
            <p class="mb-4 text-sm text-zinc-500">Upload a clear image of the gift card showing the scratched code.</p>
            
            <flux:input type="file" wire:model.live="card_image" label="Card Image" accept="image/*" required />
            
            
            @if($card_image)
                <div class="mt-4 relative rounded-xl border border-zinc-200 overflow-hidden max-w-[200px] shadow-sm">
                    <img src="{{ $card_image->temporaryUrl() }}" class="w-full h-auto object-cover" alt="Card Preview">
                    <button type="button" wire:click="$set('card_image', null)" class="absolute top-2 right-2 p-1 bg-red-500/80 hover:bg-red-600 text-white rounded-full transition shadow-sm backdrop-blur-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="size-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            @endif

            <div class="mt-6">
                <flux:input type="file" wire:model.live="receipt_image" label="Receipt Image (Optional)" accept="image/*" />
                @if($receipt_image)
                    <div class="mt-4 relative rounded-xl border border-zinc-200 overflow-hidden max-w-[200px] shadow-sm">
                        <img src="{{ $receipt_image->temporaryUrl() }}" class="w-full h-auto object-cover" alt="Receipt Preview">
                        <button type="button" wire:click="$set('receipt_image', null)" class="absolute top-2 right-2 p-1 bg-red-500/80 hover:bg-red-600 text-white rounded-full transition shadow-sm backdrop-blur-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="size-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                @endif
            </div>
            
            <div class="mt-6 flex flex-col sm:flex-row gap-4 items-end">
                <div class="w-full sm:w-1/3">
                    <x-custom-select 
                        wire:model="whatsapp_country_code" 
                        label="Country Code" 
                        :options="$dialOptions" 
                        searchable="true" 
                    />
                </div>
                <div class="w-full sm:w-2/3">
                    <flux:input wire:model="whatsapp_number" label="Contact Details" required />
                </div>
            </div>
        </div>

        {{-- Payout Details --}}
        <div class="rounded-[12px] border border-zinc-200 bg-[#eff6ff] p-6 shadow-sm shadow-zinc-900/[0.04] dark:border-zinc-700 dark:shadow-none" x-data="{ method: @entangle('payout_method') }">
            <h3 class="mb-4 text-base font-semibold text-zinc-900 dark:text-white">4. How do you want to be paid?</h3>
            
            <div class="flex flex-col gap-4">
                <flux:radio.group wire:model="payout_method" label="Payout Method">
                    <flux:radio value="wallet" label="Rshop Wallet" />
                    <flux:radio value="bank" label="Bank Transfer" />
                </flux:radio.group>

                <div x-show="method === 'bank'" style="display: none;" class="mt-4">
                    <x-custom-select 
                        wire:model="bank_account_id" 
                        label="Select Saved Bank Account" 
                        :options="$savedBankOptions" 
                        placeholder="Choose a saved bank account..." 
                    />
                    
                    <div class="mt-2 text-sm text-zinc-500 flex items-center gap-2">
                        <button type="button" wire:click="openAddBankModal" class="text-blue-600 hover:underline dark:text-blue-400">Add a new bank account &rarr;</button>
                        <flux:icon.arrow-path wire:loading wire:target="openAddBankModal" class="size-4 animate-spin text-blue-600" />
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end pt-4">
            <flux:button type="submit" variant="primary" class="w-full sm:w-auto">
                Submit Trade for Review
            </flux:button>
        </div>
    </form>

    <flux:modal name="add-bank-modal" class="md:w-96" @modal-open.window="if ($event.detail.name === 'add-bank-modal') $flux.modal('add-bank-modal').show()" @modal-close.window="if ($event.detail.name === 'add-bank-modal') $flux.modal('add-bank-modal').close()">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Add Bank Account</flux:heading>
                <flux:subheading>Link a new bank account to receive payouts.</flux:subheading>
            </div>

            <div class="space-y-4">
                <!-- If Flutterwave returns banks, show the searchable dropdown -->
                <div x-data="{ 
                    banks: @entangle('flutterwave_banks'),
                    get hasBanks() {
                        if (!this.banks) return false;
                        if (Array.isArray(this.banks)) return this.banks.length > 0;
                        return Object.keys(this.banks).length > 0;
                    }
                }">
                    <template x-if="hasBanks">
                        <div x-data="{
                            search: '',
                            open: false,
                            selectedBankCode: @entangle('new_bank_code'),
                            get filteredBanks() {
                                let b = Array.isArray(this.banks) ? this.banks : (Object.values(this.banks || {}));
                                if (this.search === '') return b;
                                return b.filter(bank => bank.name && bank.name.toLowerCase().includes(this.search.toLowerCase()));
                            },
                            get selectedBankName() {
                                if (!this.selectedBankCode) return 'Choose a bank...';
                                let b = Array.isArray(this.banks) ? this.banks : (Object.values(this.banks || {}));
                                const bank = b.find(bk => bk.code === this.selectedBankCode);
                                return bank ? bank.name : 'Choose a bank...';
                            },
                            selectBank(code) {
                                this.selectedBankCode = code;
                                this.search = '';
                                this.open = false;
                            }
                        }" @click.away="open = false" class="relative w-full">
                            <flux:label>Select Bank / Network</flux:label>
                            
                            <button type="button" @click="open = !open" class="w-full flex items-center justify-between mt-1 px-3 py-2 border rounded-[12px] text-left shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white">
                                <span x-text="selectedBankName"></span>
                                <svg class="h-5 w-5 text-zinc-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            
                            <div x-show="open" x-transition.opacity class="absolute z-50 w-full mt-1 bg-[#eff6ff] border border-zinc-200 dark:border-zinc-700 rounded-[12px] shadow-lg" style="display: none;">
                                <div class="p-2 border-b border-zinc-200 dark:border-zinc-700">
                                    <input type="text" x-model="search" placeholder="Search banks..." class="w-full px-3 py-1.5 text-sm border rounded-[12px] focus:outline-none focus:ring-2 focus:ring-blue-500 border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white">
                                </div>
                                <ul class="max-h-60 overflow-y-auto py-1 text-sm text-zinc-700 dark:text-zinc-300">
                                    <template x-for="bank in filteredBanks" :key="bank.code">
                                        <li @click="selectBank(bank.code)" class="px-3 py-2 cursor-pointer hover:bg-zinc-100 dark:hover:bg-zinc-700" :class="{ 'bg-zinc-100 dark:bg-zinc-700 font-semibold': selectedBankCode === bank.code }">
                                            <span x-text="bank.name"></span>
                                        </li>
                                    </template>
                                    <template x-if="filteredBanks.length === 0">
                                        <li class="px-3 py-2 text-zinc-500 italic">No banks found.</li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                    </template>
                    
                    <!-- Fallback: if no banks found for this country, let user type it -->
                    <template x-if="!hasBanks">
                        <flux:input wire:model="new_bank_code" label="Bank Name" placeholder="e.g. Bank of America" required />
                    </template>
                </div>

                <flux:input wire:model.live.debounce.1000ms="new_account_number" label="Account Number" placeholder="e.g. 0123456789" />

                @if($is_verifying_bank)
                    <div class="text-sm text-zinc-500 flex items-center gap-2">
                        <flux:icon.arrow-path class="size-4 animate-spin" /> Verifying account...
                    </div>
                @elseif($manual_name_entry)
                    <flux:input wire:model="resolved_account_name" label="Account Name" placeholder="Enter the account holder's name" required />
                @elseif($resolved_account_name)
                    <div class="rounded-[12px] bg-green-50 p-3 text-sm text-green-700 dark:bg-green-500/10 dark:text-green-400">
                        Verified: <strong>{{ $resolved_account_name }}</strong>
                    </div>
                @endif
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:button x-on:click="$flux.modal('add-bank-modal').close()">Cancel</flux:button>
                <flux:button wire:click="saveBankAccount" variant="primary">Save Account</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
