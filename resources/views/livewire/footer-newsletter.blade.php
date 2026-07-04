<?php

/**
 * Guest-friendly newsletter signup for the storefront footer. Anyone can enter an
 * email (unlike the dashboard newsletter-card, which one-tap subscribes the logged-in
 * user). Writes to newsletter_subscribers via NotificationService, source "footer".
 */

use App\Domain\Notification\Services\NotificationService;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new class extends Component
{
    #[Validate('required|email|max:255')]
    public string $email = '';

    public bool $subscribed = false;

    public function subscribe(NotificationService $service): void
    {
        $this->validate();

        $service->subscribeNewsletter($this->email, 'footer');

        $this->subscribed = true;
        $this->reset('email');
    }
}; ?>

<div>
    @if ($subscribed)
        <p class="mt-3 flex items-center gap-2 text-sm font-medium text-emerald-600">
            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            You're subscribed - watch your inbox.
        </p>
    @else
        <form wire:submit="subscribe" class="mt-3 flex w-full max-w-sm items-center gap-2">
            <input
                wire:model="email"
                type="email"
                required
                placeholder="Your email address"
                aria-label="Email address"
                class="min-w-0 flex-1 rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white"
            >
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="subscribe"
                class="inline-flex shrink-0 items-center justify-center rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="subscribe">Subscribe</span>
                <span wire:loading wire:target="subscribe" style="display:none;">Subscribing...</span>
            </button>
        </form>
        @error('email') <p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
