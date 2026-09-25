{{--
    "Invite a Friend" pill. Points at the existing earn-points page - there is no
    separate referral route.

    Rendered TWICE by x-nav.main-nav, one copy visible at a time:

      - md and up, pinned to the right of the category line.
      - below md, in the utility row ABOVE the logo, where the mobile nav puts
        its secondary controls.

    Same reasoning as x-nav.utility-links: one copy cannot be both above the
    logo and beside the categories, and there is no state here to duplicate -
    it is a link. No wrapper either; the parent supplies the row.

    It stays an <a> even where it drops the referral panel (md and up), so
    middle-click and a JS-less visit still reach the earn-points page; that
    caller passes :navigate="false" and prevents the click itself.

    Props:
      navigate - wire:navigate on the link. Pass false when the caller handles
                 the click, or Livewire navigates away as the panel opens.
--}}
@props(['navigate' => true])

<a
    href="{{ route('shop.earn-points') }}"
    @if ($navigate) wire:navigate @endif
    {{-- Glass, the string x-nav.main-nav's category rail and
         x-nav.certified-badge wear: frosted white on a hairline, lit to a
         fuller white under a pointer. It used to be the page-tone wash.

         From md the padding is the category links' (px-4 py-2 on 16/16 type),
         so every pill on that line reads at one height; below md, in the
         sheet, it keeps the tighter pill. --}}
    {{ $attributes->class('flex shrink-0 items-center gap-2 rounded-full bg-white/50 backdrop-blur-md backdrop-saturate-150 ring-1 ring-[#dee3e1] px-3.5 py-1.5 nav-text text-zinc-900 transition-colors duration-150 hover:bg-white/80 hover:ring-[#c9d0cd] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 md:px-4 md:py-2 dark:bg-white/5 dark:ring-white/10 dark:text-zinc-200 dark:hover:bg-white/15 dark:hover:ring-white/25') }}
>
    {{-- Same gift glyph the account menu uses for Rcoin Rewards. --}}
    <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
    </svg>
    <span>{{ __('common.invite_a_friend') }}</span>
</a>
