<?php

use App\Domain\Fulfillment\Services\EsimRefundService;
use App\Domain\Fulfillment\Services\EsimUsageSync;
use App\Livewire\Traits\RefundsEsims;
use App\Models\Admin;
use App\Models\Esim;
use App\Models\OrderItem;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('eSIM details')]
class extends Component
{
    use RefundsEsims;

    public int $esimId;

    public string $rejectionNote = '';

    public ?string $notice = null;

    public ?string $refundActionError = null;

    public function mount(Esim $esim): void
    {
        $this->esimId = $esim->id;
    }

    #[Computed]
    public function esim(): Esim
    {
        return Esim::with([
            'user:id,name,email',
            'order',
            'orderItem.product:id,name',
            'refund',
            'refundRequestedBy:id,name',
            'refundResolvedBy:id,name',
        ])->findOrFail($this->esimId);
    }

    /**
     * Top-ups bought for this eSIM, newest first.
     */
    #[Computed]
    public function topups()
    {
        return OrderItem::query()
            ->with('order:id,order_number,created_at')
            ->where('metadata->parent_iccid', $this->esim->iccid)
            ->latest()
            ->get();
    }

    public function refreshUsage(): void
    {
        $this->notice = app(EsimUsageSync::class)->sync($this->esim)
            ? 'Status and data refreshed.'
            : 'The provider did not answer. The last known status is shown.';

        unset($this->esim);
    }

    public function fileClaim(): void
    {
        $this->notice = app(EsimRefundService::class)->fileSupplierClaim($this->esim)
            ? 'The refund request is with the provider.'
            : 'The provider did not accept the request. Try again later.';

        unset($this->esim);
    }

    /**
     * The provider confirmed the refund: credit the customer's wallet.
     */
    public function completeRefund(): void
    {
        $this->applyResult(app(EsimRefundService::class)->complete($this->esim, $this->admin()));
    }

    public function rejectRefund(): void
    {
        $this->validate(['rejectionNote' => ['required', 'string', 'min:5', 'max:255']], [
            'rejectionNote.*' => 'Give the reason for the decline.',
        ]);

        $this->applyResult(app(EsimRefundService::class)->reject($this->esim, $this->admin(), $this->rejectionNote));
    }

    protected function afterEsimRefunded(): void
    {
        unset($this->esim);
    }

    /**
     * @param  array{ok: bool, message: string}  $result
     */
    private function applyResult(array $result): void
    {
        $this->refundActionError = $result['ok'] ? null : $result['message'];
        $this->notice = $result['ok'] ? $result['message'] : null;

        unset($this->esim);
    }

    private function admin(): Admin
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    public function with(): array
    {
        return [
            'blocked' => app(EsimRefundService::class)->blockedReason($this->esim),
        ];
    }
}; ?>

@php
    $esim = $this->esim;
    $payload = (array) $esim->orderItem?->fulfillment_payload;
    $label = 'text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400';
    $card = 'rounded-[12px] bg-white p-5 shadow-sm shadow-zinc-900/[0.04] ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10';
    $when = fn ($date) => $date ? $date->format('M j, Y') . ' at ' . $date->format('g:i A') : null;
    $refundTone = match ($esim->refund_status) {
        \App\Models\Esim::REFUND_COMPLETED => 'purple',
        \App\Models\Esim::REFUND_REJECTED => 'red',
        default => 'amber',
    };
    $refundLabel = match ($esim->refund_status) {
        \App\Models\Esim::REFUND_COMPLETED => 'Refunded',
        \App\Models\Esim::REFUND_REJECTED => 'Declined by provider',
        default => $esim->refund_claim_reference ? 'Waiting for provider' : 'Not sent to provider',
    };
    $refundMoney = $esim->refund_currency . ' ' . number_format((float) $esim->refund_amount, 2);
    $activatedWhilePending = $esim->isRefundPending() && ! in_array($esim->provider_status, [null, 'NOT_ACTIVE', 'UNKNOWN'], true);
@endphp

<div>
    <x-slot:heading>{{ $esim->orderItem?->product?->name ?? 'eSIM' }}</x-slot:heading>
    <x-slot:subheading>{{ $esim->package_name }}</x-slot:subheading>

    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('admin.esims') }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                All eSIMs
            </a>
            <div class="flex items-center gap-2">
                @if ($esim->provider === 'airalo')
                    <button type="button" wire:click="refreshUsage" wire:loading.attr="disabled" wire:target="refreshUsage" class="inline-flex items-center rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-zinc-300 dark:hover:bg-[#26416b]">
                        <span wire:loading.remove wire:target="refreshUsage">Refresh</span>
                        <span wire:loading wire:target="refreshUsage">Refreshing...</span>
                    </button>
                @endif
                @if ($blocked)
                    <span class="inline-flex cursor-not-allowed items-center rounded-[12px] bg-zinc-100 px-3 py-2 text-xs font-semibold text-zinc-400 dark:bg-[#26416b]/60 dark:text-zinc-500" title="{{ $blocked }}" aria-disabled="true">Refund</span>
                @else
                    <button type="button" wire:click="openRefund({{ $esim->id }})" class="inline-flex items-center rounded-[12px] bg-red-600 px-3.5 py-2 text-xs font-semibold text-white transition-colors hover:bg-red-700">Refund</button>
                @endif
            </div>
        </div>

        @if (session('status') || $notice)
            <div class="rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30" role="status">{{ session('status') ?? $notice }}</div>
        @endif

        <div class="grid gap-4 lg:grid-cols-3">
            {{-- Status and data --}}
            <section class="{{ $card }} lg:col-span-2" aria-labelledby="usage-heading">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="usage-heading" class="{{ $label }}">Status</h2>
                        <div class="mt-2"><x-admin.badge :tone="$esim->status->tone()">{{ $esim->status->label() }}</x-admin.badge></div>
                    </div>
                    <div class="text-right">
                        <p class="{{ $label }}">Last checked</p>
                        <p class="mt-1 text-xs text-zinc-700 dark:text-zinc-300">{{ $esim->last_synced_at?->diffForHumans() ?? 'Never' }}</p>
                    </div>
                </div>

                <div class="mt-5">
                    <p class="{{ $label }}">Data remaining</p>
                    @if ($esim->is_unlimited)
                        <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">Unlimited</p>
                    @elseif ($esim->data_total_mb !== null)
                        <p class="mt-1 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ \App\Models\Esim::formatMb((int) $esim->data_remaining_mb) }} <span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">of {{ \App\Models\Esim::formatMb($esim->data_total_mb) }}</span></p>
                        @php $percent = $esim->dataRemainingPercent(); @endphp
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-[#0c1a36]" role="img" aria-label="{{ $percent }}% of data left">
                            <div @class(['h-full rounded-full', 'bg-amber-500' => $percent <= 25, 'bg-emerald-500' => $percent > 25]) style="width: {{ $percent }}%"></div>
                        </div>
                    @else
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Not checked with the provider yet.</p>
                    @endif
                </div>

                @if ($esim->sync_error)
                    <p class="mt-4 text-xs font-medium text-amber-700 dark:text-amber-300">{{ $esim->sync_error }}</p>
                @endif

                <dl class="mt-6 grid gap-4 border-t border-zinc-100 pt-5 text-sm sm:grid-cols-2 dark:border-zinc-700/60">
                    <div>
                        <dt class="{{ $label }}">Purchased</dt>
                        <dd class="mt-1 text-zinc-800 dark:text-zinc-200">{{ $when($esim->purchased_at) }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $label }}">Activated</dt>
                        <dd class="mt-1 text-zinc-800 dark:text-zinc-200">{{ $esim->activated_at ? 'First seen active ' . $when($esim->activated_at) : 'Not activated' }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $label }}">Expires</dt>
                        <dd class="mt-1 text-zinc-800 dark:text-zinc-200">{{ $when($esim->expires_at) ?? 'Starts counting when activated' }}</dd>
                    </div>
                    @if ($esim->refunded_at)
                        <div>
                            <dt class="{{ $label }}">Refunded</dt>
                            <dd class="mt-1 text-zinc-800 dark:text-zinc-200">{{ $when($esim->refunded_at) }}</dd>
                        </div>
                    @endif
                </dl>
            </section>

            {{-- Customer and order --}}
            <section class="{{ $card }}" aria-labelledby="customer-heading">
                <h2 id="customer-heading" class="{{ $label }}">eSIM user</h2>
                @if ($esim->user)
                    <a href="{{ route('admin.customer', $esim->user) }}" wire:navigate class="mt-1 block font-semibold text-zinc-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400">{{ $esim->user->name }}</a>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $esim->user->email }}</p>
                @else
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Deleted account</p>
                @endif

                <h2 class="{{ $label }} mt-5">Order</h2>
                <a href="{{ route('admin.order', $esim->order_id) }}" wire:navigate class="mt-1 block font-mono text-sm font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400">{{ $esim->order?->order_number }}</a>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Paid {{ $esim->order?->display_currency }} {{ number_format((float) $esim->orderItem?->subtotal_amount / max(1, (int) $esim->orderItem?->quantity), 2) }} for this eSIM</p>

                <h2 class="{{ $label }} mt-5">Provider</h2>
                <p class="mt-1 text-sm capitalize text-zinc-800 dark:text-zinc-200">{{ $esim->provider }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Cost USD {{ number_format((float) $esim->orderItem?->provider_cost_usd, 2) }}</p>
            </section>
        </div>

        {{-- eSIM identity --}}
        <section class="{{ $card }}" aria-labelledby="identity-heading">
            <h2 id="identity-heading" class="text-sm font-bold text-zinc-900 dark:text-white">eSIM</h2>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="{{ $label }}">ICCID</dt>
                    <dd class="mt-1 break-all font-mono text-xs text-zinc-800 dark:text-zinc-200">{{ $esim->iccid }}</dd>
                </div>
                <div>
                    <dt class="{{ $label }}">Package code</dt>
                    <dd class="mt-1 break-all font-mono text-xs text-zinc-800 dark:text-zinc-200">{{ $esim->package_code ?? 'None' }}</dd>
                </div>
                <div>
                    <dt class="{{ $label }}">Status</dt>
                    <dd class="mt-1 font-mono text-xs text-zinc-800 dark:text-zinc-200">{{ $esim->provider_status ?? 'Not checked' }}</dd>
                </div>
                <div>
                    <dt class="{{ $label }}">SM-DP+ address</dt>
                    <dd class="mt-1 break-all font-mono text-xs text-zinc-800 dark:text-zinc-200">{{ $payload['lpa'] ?? 'None' }}</dd>
                </div>
                <div>
                    <dt class="{{ $label }}">Phone number</dt>
                    <dd class="mt-1 font-mono text-xs text-zinc-800 dark:text-zinc-200">{{ $payload['phone_number'] ?? 'Data only' }}</dd>
                </div>
                <div>
                    <dt class="{{ $label }}">Network</dt>
                    <dd class="mt-1 text-xs text-zinc-800 dark:text-zinc-200">{{ is_string($payload['network'] ?? null) ? $payload['network'] : 'Not given' }}</dd>
                </div>
            </dl>
        </section>

        {{-- Refund: requested, then completed (wallet credited) or declined --}}
        @if ($esim->refund_status)
            <section class="{{ $card }}" aria-labelledby="refund-heading">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h2 id="refund-heading" class="text-sm font-bold text-zinc-900 dark:text-white">Refund</h2>
                    <x-admin.badge :tone="$refundTone">{{ $refundLabel }}</x-admin.badge>
                </div>

                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="{{ $label }}">{{ $esim->refund_status === \App\Models\Esim::REFUND_COMPLETED ? 'Credited to wallet' : 'Wallet credit on completion' }}</dt>
                        <dd class="mt-1 font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $refundMoney }}</dd>
                        <dd class="text-xs text-zinc-500 dark:text-zinc-400">{{ $esim->refund_currency }} wallet</dd>
                    </div>
                    <div>
                        <dt class="{{ $label }}">Requested</dt>
                        <dd class="mt-1 text-zinc-800 dark:text-zinc-200">{{ $esim->refundRequestedBy?->name ?? 'Unknown admin' }}</dd>
                        <dd class="text-xs text-zinc-500 dark:text-zinc-400">{{ $when($esim->refund_requested_at) }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $label }}">Reason</dt>
                        <dd class="mt-1 text-zinc-800 dark:text-zinc-200">{{ \App\Domain\Fulfillment\Providers\AiraloFulfillmentProvider::REFUND_REASONS[$esim->refund_reason] ?? 'Not given' }}</dd>
                        <dd class="text-xs text-zinc-500 dark:text-zinc-400">{{ $esim->refund_note }}</dd>
                    </div>
                    <div>
                        <dt class="{{ $label }}">Provider reference</dt>
                        <dd class="mt-1 break-all font-mono text-xs text-zinc-800 dark:text-zinc-200">{{ $esim->refund_claim_reference ?? 'Not sent' }}</dd>
                        @if (! $esim->refund_claim_reference && data_get($esim->metadata, 'refund_claim_error'))
                            <dd class="text-xs text-amber-700 dark:text-amber-300">{{ data_get($esim->metadata, 'refund_claim_error') }}</dd>
                        @endif
                    </div>
                    @if ($esim->refund_resolved_at)
                        <div>
                            <dt class="{{ $label }}">{{ $esim->refund_status === \App\Models\Esim::REFUND_COMPLETED ? 'Completed' : 'Declined' }}</dt>
                            <dd class="mt-1 text-zinc-800 dark:text-zinc-200">{{ $esim->refundResolvedBy?->name ?? 'Unknown admin' }}</dd>
                            <dd class="text-xs text-zinc-500 dark:text-zinc-400">{{ $when($esim->refund_resolved_at) }}</dd>
                        </div>
                    @endif
                    @if ($esim->refund_status === \App\Models\Esim::REFUND_COMPLETED && $esim->refund)
                        <div>
                            <dt class="{{ $label }}">Provider credit</dt>
                            <dd class="mt-1 tabular-nums text-zinc-800 dark:text-zinc-200">USD {{ number_format((float) $esim->refund->supplier_credit_usd, 2) }}</dd>
                        </div>
                    @endif
                    @if ($esim->refund_resolution_note)
                        <div class="sm:col-span-2">
                            <dt class="{{ $label }}">Decline reason</dt>
                            <dd class="mt-1 text-zinc-800 dark:text-zinc-200">{{ $esim->refund_resolution_note }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($esim->isRefundPending())
                    <div x-data="{ confirming: false }" @keydown.escape.window="confirming = false" class="mt-5 flex flex-col gap-4 border-t border-zinc-100 pt-5 dark:border-zinc-700/60">
                        @if ($activatedWhilePending)
                            <div class="rounded-[12px] bg-red-50 px-4 py-3 text-sm font-medium text-red-700 ring-1 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30" role="alert">
                                The provider now reports this eSIM as {{ $esim->provider_status }}. It was switched on after the refund was requested, so the provider will likely decline.
                            </div>
                        @endif

                        @if ($refundActionError)
                            <div class="rounded-[12px] bg-red-50 px-4 py-3 text-sm font-medium text-red-700 ring-1 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30" role="alert">{{ $refundActionError }}</div>
                        @endif

                        @if (! $esim->refund_claim_reference)
                            <div class="flex flex-wrap items-center gap-3">
                                <p class="text-sm text-zinc-700 dark:text-zinc-300">The request has not reached the provider yet.</p>
                                <button type="button" wire:click="fileClaim" wire:loading.attr="disabled" wire:target="fileClaim" class="inline-flex items-center rounded-[12px] bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-60">Send to provider</button>
                            </div>
                        @endif

                        <div class="grid gap-4 md:grid-cols-2 md:items-end">
                            <div class="flex flex-col gap-2">
                                <p class="{{ $label }}">Provider confirmed</p>
                                <p class="text-sm text-zinc-600 dark:text-zinc-300">Complete the refund once the provider confirms it.</p>
                                <button type="button" @click="confirming = true" class="inline-flex w-fit items-center rounded-[12px] bg-emerald-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-emerald-700">Complete refund</button>

                                {{-- Our own confirmation: the browser's confirm box always prints the site address. --}}
                                <template x-teleport="body">
                                    <div x-show="confirming" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="complete-refund-title">
                                        <div @click="confirming = false" x-show="confirming" x-transition.opacity class="absolute inset-0 bg-zinc-900/40"></div>
                                        <div x-show="confirming" x-transition class="relative w-full max-w-sm overflow-hidden rounded-[12px] bg-white shadow-2xl dark:bg-[#1d3252]">
                                            <div class="px-5 pb-4 pt-5">
                                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30">
                                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 110-6h.75M21 12v6.75A2.25 2.25 0 0118.75 21H5.25A2.25 2.25 0 013 18.75V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25V12z"/></svg>
                                                </div>
                                                <h3 id="complete-refund-title" class="mt-4 text-sm font-bold text-zinc-900 dark:text-white">Complete this refund?</h3>
                                                <p class="mt-1.5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                                                    <span class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $refundMoney }}</span>
                                                    will be credited to {{ $esim->user?->name ?? 'the customer' }}'s {{ $esim->refund_currency }} wallet. This cannot be undone.
                                                </p>
                                            </div>
                                            <div class="flex items-center justify-end gap-2 border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700/60 dark:bg-[#0c1a36]/50">
                                                <button type="button" @click="confirming = false" class="inline-flex items-center rounded-[12px] px-3.5 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#26416b]">Cancel</button>
                                                <button type="button" wire:click="completeRefund" @click="confirming = false" wire:loading.attr="disabled" wire:target="completeRefund" class="inline-flex items-center rounded-[12px] bg-emerald-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-emerald-700 disabled:opacity-60">Confirm refund</button>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <form wire:submit="rejectRefund" class="flex flex-col gap-2">
                                <label for="rejection-note" class="{{ $label }}">Provider declined</label>
                                <div class="flex gap-2">
                                    <input id="rejection-note" type="text" maxlength="255" wire:model="rejectionNote" placeholder="Reason for the decline" class="w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white" />
                                    <button type="submit" wire:loading.attr="disabled" wire:target="rejectRefund" class="shrink-0 rounded-[12px] border border-zinc-200 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-zinc-300 dark:hover:bg-[#26416b]">Mark as declined</button>
                                </div>
                                @error('rejectionNote') <p class="text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </form>
                        </div>
                    </div>
                @endif
            </section>
        @endif

        {{-- Top-ups --}}
        <section class="{{ $card }}" aria-labelledby="topups-heading">
            <h2 id="topups-heading" class="text-sm font-bold text-zinc-900 dark:text-white">Top-ups</h2>
            @forelse ($this->topups as $topup)
                <div wire:key="topup-{{ $topup->id }}" class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-3 text-sm first:border-t-0 dark:border-zinc-700/60">
                    <a href="{{ route('admin.order', $topup->order_id) }}" wire:navigate class="font-mono text-xs font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400">{{ $topup->order?->order_number }}</a>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $topup->order?->created_at?->format('M j, Y') }}</span>
                    <x-admin.badge :tone="$topup->fulfillment_status->value === 'fulfilled' ? 'emerald' : 'zinc'">{{ $topup->fulfillment_status->label() }}</x-admin.badge>
                </div>
            @empty
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No top-ups on this eSIM.</p>
            @endforelse
        </section>
    </div>

    @if ($refundingEsimId && $this->refundingEsim)
        <x-admin.esim-refund-modal
            :esim="$this->refundingEsim"
            :amount="$this->refundAmountFor($this->refundingEsim)"
            :reasons="$this->refundReasons()"
            :error="$refundError"
        />
    @endif
</div>
