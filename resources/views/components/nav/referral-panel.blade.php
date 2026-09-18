{{--
    The "Refer friends, earn Rcoin" panel: the customer's referral link with its
    Copy button and custom-code option, under a short explanation of when the
    reward actually lands.

    Lives here rather than inline in the dashboard top bar because the storefront
    nav drops the same panel from its "Invite a Friend" pill, and two copies of
    this would drift the way the row-header controls did.

    The caller owns POSITIONING (it is the caller's dropdown), so pass the
    absolute/anchor classes in: this component only draws the card.

    Surface note: the card is bg-white, NOT bg-[#ffffff]. They are the same
    colour, but app.css repaints bg-[#ffffff] for dark mode under
    `html.dark body.dashboard` only - deliberately, so the storefront can handle
    its own dark surfaces. `.dark .bg-white` is unscoped and carries the same
    ash + grain treatment, so bg-white is the one that works on both.

    Usage:
      <x-nav.referral-panel class="absolute right-0 top-full mt-3 z-50" close="referOpen = false" />

    Props:
      close - Alpine expression run when a link inside navigates away, so the
              caller's dropdown closes behind it. Omit outside a dropdown.
--}}
@props(['close' => null])

@php
    $earnUsd = (int) config('dashboard.earn_up_to_usd', 0);
    // Whether every order a referee places pays out, or only their first.
    $rewardsOnEveryOrder = \App\Models\Setting::get('recurring_referral_rewards_enabled', true);
@endphp

<div {{ $attributes->class('w-[400px] max-w-[calc(100vw-2rem)] rounded-[15px] bg-white p-5 text-left shadow-xl shadow-zinc-900/15 dark:ring-1 dark:ring-white/10') }}>
    <div class="flex items-start gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-[#f4f6f5] text-[#0c1337] dark:bg-white/10 dark:text-zinc-200">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
        </span>
        <div class="min-w-0">
            <p class="text-base font-bold text-zinc-900 dark:text-white">@if ($earnUsd > 0)Refer friends, earn up to ${{ number_format($earnUsd) }} in Rcoin @else Refer friends, earn Rcoin @endif</p>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Share your link. You earn Rcoin when someone you refer completes {{ $rewardsOnEveryOrder ? 'an order' : 'their first order' }}. Signing up alone earns nothing.</p>
        </div>
    </div>

    @auth
        <div class="mt-4">
            <livewire:referral-code />
        </div>

        <a href="{{ route('dashboard.rewards') }}" wire:navigate @if ($close) x-on:click="{!! $close !!}" @endif class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-[#0c1337] underline-offset-2 hover:underline dark:text-zinc-200">
            See your Rcoin rewards
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
        </a>
    @else
        {{-- A guest has no link to copy: referral-code writes a code onto the
             signed-in user, so it cannot render here at all. The panel still
             explains the offer and sends them to sign in, which is where the
             link comes from. --}}
        <a href="{{ route('login') }}" wire:navigate @if ($close) x-on:click="{!! $close !!}" @endif class="mt-4 flex h-10 w-full items-center justify-center rounded-[12px] bg-blue-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40">
            Sign in to get your link
        </a>

        <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">
            New here?
            <a href="{{ route('register') }}" wire:navigate @if ($close) x-on:click="{!! $close !!}" @endif class="font-semibold text-[#0c1337] underline underline-offset-2 dark:text-zinc-200">Create an account</a>
            and your link is ready straight away.
        </p>
    @endauth
</div>
