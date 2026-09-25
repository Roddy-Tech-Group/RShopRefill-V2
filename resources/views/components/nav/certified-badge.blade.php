{{--
    "Certified reseller" badge. A statement about the shop, not a link: it goes
    nowhere, so it is a span and carries no hover.

    Rendered TWICE by x-nav.main-nav, always next to x-nav.invite-link and
    always ahead of it, one copy visible at a time:

      - md and up, on the category line, at the right end.
      - below md, in the menu sheet's foot, where the phone nav keeps its
        secondary controls.

    Same glass as the category rail and the invite pill (frosted white on a
    #dee3e1 hairline). From md it is sized to the category links on the same
    line - px-4 py-2 on 16/16 type - so every pill on that row reads at one
    height; below md it keeps the sheet pill's tighter padding.
--}}
<span {{ $attributes->class('flex shrink-0 items-center gap-2 rounded-full bg-white/50 backdrop-blur-md backdrop-saturate-150 ring-1 ring-[#dee3e1] px-3.5 py-1.5 nav-text text-zinc-900 md:px-4 md:py-2 dark:bg-white/5 dark:ring-white/10 dark:text-zinc-200') }}>
    <svg class="h-[18px] w-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6.633 10.25c.806 0 1.533-.446 2.031-1.08a9.041 9.041 0 012.861-2.4c.723-.384 1.35-.956 1.653-1.715a4.498 4.498 0 00.322-1.672V3a.75.75 0 01.75-.75 2.25 2.25 0 012.25 2.25c0 1.152-.26 2.243-.723 3.218-.266.558.107 1.282.725 1.282h3.126c1.026 0 1.945.694 2.054 1.715.045.422.068.85.068 1.285a11.95 11.95 0 01-2.649 7.521c-.388.482-.987.729-1.605.729H13.48c-.483 0-.964-.078-1.423-.23l-3.114-1.04a4.501 4.501 0 00-1.423-.23H5.904M14.25 9h2.25M5.904 18.75c.083.205.173.405.27.602.197.4-.078.898-.523.898h-.908c-.889 0-1.713-.518-1.972-1.368a12 12 0 01-.521-3.507c0-1.553.295-3.036.831-4.398C3.387 10.203 4.167 9.75 5 9.75h1.053c.472 0 .745.556.5.96a8.958 8.958 0 00-1.302 4.665c0 1.194.232 2.333.654 3.375z" />
    </svg>
    <span>{{ __('common.certified_reseller') }}</span>
</span>
