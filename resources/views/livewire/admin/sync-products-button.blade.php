<?php

use App\Jobs\SyncAiraloEsimsJob;
use App\Jobs\SyncZenditEsimsJob;
use App\Jobs\SyncZenditGiftCardsJob;
use Livewire\Volt\Component;

new class extends Component
{
    /** Set once a sync has been queued this session — flips the button to its confirmed state. */
    public bool $queued = false;

    /**
     * Queue a full Zendit sync. Each job walks every catalog page itself (each page
     * self-dispatches the next), so a single dispatch syncs the ENTIRE catalog:
     *   - SyncZenditGiftCardsJob: gift-card products + variants + prices, then it
     *     auto-dispatches the brand-asset sync (logos, hero art, redemption text)
     *     on completion.
     *   - SyncZenditEsimsJob: the eSIM catalog (independent product type).
     * The work runs on the queue, so a queue worker must be running to process it —
     * the button only enqueues the jobs.
     */
    public function sync(): void
    {
        SyncZenditGiftCardsJob::dispatch();
        SyncZenditEsimsJob::dispatch();
        SyncAiraloEsimsJob::dispatch();

        $this->queued = true;
    }
}; ?>

<div class="flex flex-col items-stretch gap-1 sm:items-end">
    <button
        type="button"
        wire:click="sync"
        wire:target="sync"
        wire:loading.attr="disabled"
        @disabled($queued)
        @class([
            'inline-flex items-center justify-center gap-2 rounded-[12px] px-4 py-2.5 text-sm font-semibold shadow-sm transition-colors',
            'bg-emerald-600 text-white' => $queued,
            'bg-blue-600 text-white hover:bg-blue-700' => ! $queued,
        ])
    >
        {{-- Idle: a sync icon, or a check once queued --}}
        <svg wire:loading.remove wire:target="sync" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            @if ($queued)
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
            @else
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>
            @endif
        </svg>
        {{-- Dispatching --}}
        <svg wire:loading wire:target="sync" class="h-4 w-4 shrink-0 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>

        <span wire:loading.remove wire:target="sync">{{ $queued ? 'Sync started' : 'Sync products' }}</span>
        <span wire:loading wire:target="sync">Starting...</span>
    </button>

    @if ($queued)
        <p class="text-[11px] leading-tight text-zinc-500">
            Full sync queued (gift cards, images &amp; eSIMs) — it runs in the background. Reload later to see new products.
        </p>
    @endif
</div>
