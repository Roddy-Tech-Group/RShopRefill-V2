@props(['suggestion' => false])

{{-- $suggestion is passed by ONE caller only. This component is mounted twice
     (the mobile menu sheet and the desktop utility bar), so the region popup
     must not live inside it unconditionally or it renders in both, including
     the copy that is hidden at the current breakpoint. --}}
{{--
    Utility links - Help and the region/currency pill.

    Language is NOT here. It used to sit as an EN | FR pill immediately
    left of the pill that opens the locale modal - which is the same
    control twice, since that modal's Preferences list holds Language.
    One way in, one place to look.

    The theme picker is NOT here. It has to be the last control on the right
    in both rows, and this partial sits mid-row in each of them, so main-nav
    places x-theme-toggle itself after whatever closes the row.

    Rendered TWICE by x-nav.main-nav, and only ever one copy is visible:

      - md and up, inside the logo row's right-hand column, so these share the
        logo's line.
      - below md, in a row of their own above the logo, which is the mobile
        arrangement: utilities on top, logo + action buttons under them,
        categories under that.

    Duplicating the markup is deliberate. The alternative - one copy re-placed
    with grid - has to move it ABOVE the logo on mobile and BESIDE it on
    desktop, which no single flex or grid cell can do. Nothing here holds
    state: the locale pill reads the shared storefrontLocale scope opened in
    layouts/app/header. (The theme picker main-nav places after this is
    duplicated on the same terms - x-theme-toggle is explicitly safe to repeat,
    its instances syncing on the theme-changed window event.)

    Account and cart are NOT here - they stay on the logo line at every width,
    see x-nav.account-cart.

    No wrapper element on purpose: the parent supplies the flex row.
--}}
{{-- Help --}}
<a href="{{ route('shop.help') }}" wire:navigate class="flex shrink-0 items-center gap-1.5 px-3 py-1.5 rounded-full nav-text text-zinc-900 hover:bg-[#f4f6f5] transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:text-zinc-200 dark:hover:bg-white/10 dark:hover:text-white">
    <img src="{{ asset('assets/help.svg') }}" alt="" class="w-5 h-5 shrink-0" loading="lazy">
    <span>{{ __('navigation.help_center') }}</span>
</a>

<x-nav.region-pill :suggestion="$suggestion" />
