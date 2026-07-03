<?php

/**
 * Newsletter signup card — sits in the customer dashboard sidebar.
 *
 * One-tap subscribe for an authenticated user: the auth email is the address,
 * NotificationService writes to the newsletter_subscribers table. On mount we
 * check whether the user is already an active subscriber and render the
 * "already subscribed" state instead of the CTA so they aren't shown the prompt
 * again on every dashboard hit.
 */

use App\Domain\Notification\Services\NotificationService;
use App\Models\NewsletterSubscriber;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $subscribed = false;
    public bool $loading = false;
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->email) {
            return;
        }

        $existing = NewsletterSubscriber::where('email', $user->email)
            ->where('status', 'active')
            ->first();

        $this->subscribed = (bool) $existing;
    }

    public function subscribe(NotificationService $service): void
    {
        $this->errorMessage = null;
        $user = Auth::user();

        if (! $user || ! $user->email) {
            $this->errorMessage = 'Sign in to subscribe.';

            return;
        }

        $this->loading = true;

        try {
            $service->subscribeNewsletter($user->email, 'dashboard_sidebar');
            $this->subscribed = true;
        } catch (\Throwable $e) {
            $this->errorMessage = 'Subscription failed. Please try again.';
        } finally {
            $this->loading = false;
        }
    }
}; ?>

<div class="rounded-[12px] bg-white p-4 ring-1 ring-zinc-200">
    @if ($subscribed)
        {{-- Already subscribed state --}}
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-emerald-50">
                <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-zinc-900">You're in</p>
                <p class="mt-0.5 text-[11px] text-zinc-600">Deals + new launches arrive in your inbox.</p>
            </div>
        </div>
    @else
        {{-- Subscribe CTA --}}
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-blue-100">
                <svg class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                </svg>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-zinc-900">Stay in the loop</p>
                <p class="mt-0.5 text-[11px] leading-snug text-zinc-600">Deals, drops, and product launches in your inbox.</p>
            </div>
        </div>

        @if ($errorMessage)
            <p class="mt-2 text-[11px] font-medium text-red-600">{{ $errorMessage }}</p>
        @endif

        <button
            type="button"
            wire:click="subscribe"
            wire:target="subscribe"
            wire:loading.attr="disabled"
            class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-[12px] bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-60"
        >
            <svg wire:loading wire:target="subscribe" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <span wire:loading.remove wire:target="subscribe">Subscribe</span>
            <span wire:loading wire:target="subscribe">Subscribing...</span>
        </button>
    @endif

    {{-- Quick WhatsApp chat. Mirrors the global Ctrl+J shortcut (partials/shortcuts);
         clicking opens the same chat for users who'd rather not memorise the key. --}}
    <a
        href="https://wa.me/19402386229?text=Hello%20Rshoprefill%20can%20i%20get%20help%3F"
        target="_blank"
        rel="noopener"
        class="mt-3 flex items-center justify-between gap-2 border-t border-zinc-100 pt-2.5 text-[11px] font-medium text-zinc-500 transition-colors hover:text-emerald-600 dark:border-zinc-700/60 dark:text-zinc-400"
    >
        <span class="inline-flex items-center gap-1.5">
            <svg class="h-3.5 w-3.5 text-emerald-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.966-.273-.099-.471-.149-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.611-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479s1.065 2.875 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413"/>
            </svg>
            Chat on WhatsApp
        </span>
        <span class="inline-flex shrink-0 items-center gap-1 rounded-[12px] border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[10px] font-semibold text-zinc-500 dark:border-zinc-700/60 dark:bg-white/5 dark:text-zinc-300">
            CTRL <span class="opacity-70">+</span> J
        </span>
    </a>
</div>
