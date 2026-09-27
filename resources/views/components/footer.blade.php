{{-- Storefront footer: a brand column, four link groups, a settings row and
     the legal lines.

     Every link lives in exactly ONE place. Privacy and Terms used to appear in
     the Legal column and again in a bottom link bar, and Earn points sat under
     Support; the bar is gone and the referral link reads Invite a Friend under
     Shop, where a shopper looks for it.

     Laid out as an inset card. The gutter around it carries the page's own
     surface classes - bg-[#eff6ff] dark:bg-[#0c1a36], exactly what <main>
     wears - so the margin reads as a continuation of the content above rather
     than a white band under it. It has to be a <section> and not a <div> for
     that: app.css sends a bare .dark .bg-[#eff6ff] to the card grey #2a2a2a,
     and only the page-level elements it names (body, main, section,
     [data-page-content], footer, the min-h wrappers) get the page tone.

     The slab is per-theme: #dee3e1 in light, the grainy black in dark (the
     --ground token #060607 plus the .tex-grain noise layer). It carries no
     dark: utility for that - .footer-ground in app.css owns both, along with
     the ink.

     The markup keeps its light-mode utility classes throughout. On the light
     slab they are already correct; the .dark .footer-ground remaps are what
     flip the ink light on the black one. --}}
@php
    // LinkedIn and Discord are seeded blank and documented "Hidden when blank",
    // unlike the four core networks which ship with real defaults. Set them
    // under System Settings -> social and the icons appear.
    $footerLinkedIn = \App\Models\SiteSetting::get('social.linkedin');
    $footerDiscord = \App\Models\SiteSetting::get('social.discord');

    // WhatsApp reuses the support number the contact page already dials, so
    // there is one number to keep current. Digits only, same as contact.blade.
    $footerWhatsApp = preg_replace('/[^0-9]/', '', (string) \App\Models\SiteSetting::get('contact.whatsapp_number', ''));

    // Shared classes, so a column reads as a list of destinations rather than
    // a wall of repeated utilities.
    $footerHeading = 'text-base font-bold tracking-tight text-zinc-900';
    $footerLink = 'text-base text-zinc-600 transition-colors hover:text-zinc-900';
    $footerSocial = 'flex h-9 w-9 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-700 transition-colors hover:bg-zinc-200 hover:text-zinc-900';

    // Below sm each column is a disclosure; from sm up they are plain columns.
    // The list is hidden by a CLASS rather than x-show, so it is already closed
    // on the first paint (no flash of an open column before Alpine boots) and
    // still open on desktop when JavaScript never runs at all.
    $footerCol = 'border-b border-zinc-200 sm:border-0';
    // lg:h-12 is what puts a heading on the logo's line: the wordmark sits in a
    // 48px band, so giving the heading the same band and centring in it lines
    // the two up horizontally instead of merely sharing a left edge. It tracks
    // the logo band below and has to move with it.
    $footerColButton = 'flex w-full items-center justify-between gap-2 py-4 text-left sm:pointer-events-none sm:py-0 lg:h-12';
    $footerColList = 'hidden space-y-3 pb-5 sm:mt-4 sm:block sm:pb-0';
    $footerColChevron = 'h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-200 sm:hidden';

    // The operator's legal identity, passed into the translated line as
    // placeholders so no translation can restate it.
    $footerOperator = ['company' => 'Roddy Technologies Ltd', 'rc' => '9385422', 'address' => '5 Tetlow Road, Njemenze, Owerri, Imo State, Nigeria'];
@endphp

{{-- White gutter in BOTH themes, which is what frames the slab now that the
     card carries no edge of its own. bg-[#ffffff] rather than bg-white on
     purpose: the plain class is remapped to the card surface in dark, and the
     arbitrary-value form is this app's existing opt-out from that flip.

     Padding is even on all four sides and deliberately thin - 8px, 12px, 16px -
     so the slab runs almost the full width of the window. Without it the slab
     runs flush to the viewport and its 15px radius cuts two notches through to
     the page.

     rounded-t-[20px] on the white band itself, so the footer region lifts off
     the page with the same corner the category cards use. Top only - the bottom
     runs into the viewport edge and a radius there would show the page behind
     it as two notches. --}}
<section class="rounded-t-[20px] bg-[#ffffff] p-2 sm:p-3 lg:px-4 lg:py-5">
    <footer class="footer-ground tex-grain mx-auto w-full max-w-[1550px] rounded-[15px]">
        {{-- The card's own inset. Not the nav's padding: this measures from the
             slab edge, not the viewport, so the copy gets its own breathing
             room inside the card. --}}
    {{-- Newsletter, on its own line above everything else the footer holds and
         ruled off from it. Reference shape: a single row carrying an icon, one
         sentence and the field, not a block with its own heading. --}}
    <div class="border-b border-zinc-200 px-4 py-5 sm:px-6 lg:px-8">
        <livewire:newsletter-signup />
    </div>

    <div class="px-4 py-7 sm:px-6 lg:px-8 lg:py-9">

            {{-- From lg: one row, the brand column on the left and the four
                 link groups running across on its line.

                 Below lg it is a single column in this order: brand, link
                 groups, social icons. The social icons belong to the brand
                 column on desktop but come AFTER the links on a phone, so the
                 brand wrapper is display:contents below lg - its two halves
                 become items of this flex column and order-1 / order-3 place
                 them either side of the links (order-2). At lg the wrapper is a
                 normal block again and the halves simply stack under the logo. --}}
            <div class="flex flex-col lg:grid lg:grid-cols-[1.4fr_repeat(4,minmax(0,1fr))] lg:gap-8">

            <div class="contents lg:block">
                {{-- Brand --}}
                <div class="order-1">
                    <a href="{{ route('home') }}" wire:navigate class="-ml-1 flex rounded-[12px] group focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40">
                        <span class="flex h-12 items-center">
                            {{-- The mark alone down here: the wordmark is at the top of
                                 the page and the name is written in the line under this
                                 one, so the footer only needs the R. site-logo is what
                                 flips it white on the dark slab, the same class the nav's
                                 phone logo carries, since this is the same file. --}}
                            <img
                                src="{{ asset('assets/PWAicon.webp') }}"
                                alt="{{ $siteName }}"
                                class="site-logo h-full w-auto object-contain transition-opacity duration-200 group-hover:opacity-90"
                            />
                        </span>
                    </a>

                    <p class="mt-4 max-w-xs text-sm leading-relaxed text-zinc-600">{{ __('footer.tagline') }}</p>
                </div>

                {{-- Social icons. Directly under the description on desktop;
                     after the link groups on a phone. --}}
                <div class="order-3 mt-8 lg:mt-6">
                    <div class="flex flex-wrap items-center gap-3">

                    <a href="{{ \App\Models\SiteSetting::get('social.facebook', 'https://facebook.com/rshoprefills') }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('footer.social_facebook') }}" class="{{ $footerSocial }}">
                        <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="currentColor" aria-hidden="true">
                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                        </svg>
                    </a>
                    <a href="{{ \App\Models\SiteSetting::get('social.x', 'https://x.com/roddytech237') }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('footer.social_x') }}" class="{{ $footerSocial }}">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="currentColor" aria-hidden="true">
                            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/>
                        </svg>
                    </a>
                    <a href="{{ \App\Models\SiteSetting::get('social.tiktok', 'https://tiktok.com/@rshoprefills') }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('footer.social_tiktok') }}" class="{{ $footerSocial }}">
                        <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="currentColor" aria-hidden="true">
                            <path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>
                        </svg>
                    </a>
                    <a href="{{ \App\Models\SiteSetting::get('social.instagram', 'https://instagram.com/rshoprefills') }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('footer.social_instagram') }}" class="{{ $footerSocial }}">
                        <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="currentColor" aria-hidden="true">
                            <path d="M12 2.163c3.204 0 3.584.012 4.849.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.07 1.644.07 4.849 0 3.205-.012 3.584-.07 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.849.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>
                        </svg>
                    </a>
                    @if (filled($footerLinkedIn))
                        <a href="{{ $footerLinkedIn }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('footer.social_linkedin') }}" class="{{ $footerSocial }}">
                            <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="currentColor" aria-hidden="true">
                                <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.225 0z"/>
                            </svg>
                        </a>
                    @endif
                    @if (filled($footerWhatsApp))
                        <a href="https://wa.me/{{ $footerWhatsApp }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('footer.social_whatsapp') }}" class="{{ $footerSocial }}">
                            <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="currentColor" aria-hidden="true">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884a9.82 9.82 0 0 1 6.988 2.896 9.82 9.82 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.82 11.82 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.88 11.88 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.82 11.82 0 0 0 20.464 3.488"/>
                            </svg>
                        </a>
                    @endif
                    @if (filled($footerDiscord))
                        {{-- The glyph keeps Discord's own blurple rather than the row's
                             grey ink, so it is not remapped by .footer-ground. --}}
                        <a href="{{ $footerDiscord }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('footer.social_discord') }}" class="flex h-9 w-9 items-center justify-center rounded-full bg-zinc-100 transition-colors hover:bg-zinc-200">
                            <svg viewBox="0 0 24 24" class="h-[18px] w-[18px] text-[#5865F2]" fill="currentColor" aria-hidden="true">
                                <path d="M20.317 4.3698a19.7913 19.7913 0 0 0-4.8851-1.5152.0741.0741 0 0 0-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 0 0-.0785-.037 19.7363 19.7363 0 0 0-4.8852 1.515.0699.0699 0 0 0-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 0 0 .0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 0 0 .0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 0 0-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 0 1-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 0 1 .0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 0 1 .0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 0 1-.0066.1276 12.2986 12.2986 0 0 1-1.873.8914.0766.0766 0 0 0-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 0 0 .0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 0 0 .0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 0 0-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z"/>
                            </svg>
                        </a>
                    @endif
                    </div>
                </div>
            </div>

            {{-- Link groups. One disclosure per row on phones, two columns on
                 small tablets, and from lg lg:contents dissolves this wrapper so
                 the four navs sit beside the brand column in the row above. --}}
            <div class="order-2 mt-10 grid grid-cols-1 sm:grid-cols-2 sm:gap-x-6 sm:gap-y-10 lg:contents">

                <nav x-data="{ open: false }" aria-label="{{ __('footer.shop') }}" class="{{ $footerCol }}">
                    <h3>
                        <button type="button" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="footer-col-shop" class="{{ $footerColButton }}">
                            <span class="{{ $footerHeading }}">{{ __('footer.shop') }}</span>
                            <svg class="{{ $footerColChevron }}" :class="open && '-rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </h3>
                    <ul id="footer-col-shop" :class="{ 'hidden': ! open }" class="{{ $footerColList }}">
                        <li><a href="{{ route('shop.gift-cards') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.gift_cards') }}</a></li>
                        <li><a href="{{ route('shop.esims') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.esims') }}</a></li>
                        <li><a href="{{ route('shop.topups') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.mobile_topups') }}</a></li>
                        {{-- The top-ups page's "Credit, Data & Calls" filter: there is no
                             data-only view to point at. --}}
                        <li><a href="{{ route('shop.topups', ['subcategory' => 'bundles']) }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.data_bundles') }}</a></li>
                        <li><a href="{{ route('shop.bills') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.bill_payments') }}</a></li>
                        @if (\App\Support\FeatureFlag::categoryVisible('flights'))
                            <li><a href="{{ route('shop.flights') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.flights') }}</a></li>
                        @endif
                        @if (\App\Support\FeatureFlag::categoryVisible('stays'))
                            <li><a href="{{ route('shop.stays') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.stays') }}</a></li>
                        @endif
                        {{-- Shop column: what a customer can ask us to START selling. The nav's Invite a Friend pill still lives in the Company column below. --}}
                        <li><a href="{{ route('shop.request-product') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.request_a_product') }}</a></li>
                    </ul>
                </nav>

                <nav x-data="{ open: false }" aria-label="{{ __('footer.help') }}" class="{{ $footerCol }}">
                    <h3>
                        <button type="button" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="footer-col-help" class="{{ $footerColButton }}">
                            <span class="{{ $footerHeading }}">{{ __('footer.help') }}</span>
                            <svg class="{{ $footerColChevron }}" :class="open && '-rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </h3>
                    <ul id="footer-col-help" :class="{ 'hidden': ! open }" class="{{ $footerColList }}">
                        <li><a href="{{ route('shop.help') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.help_center') }}</a></li>
                        <li><a href="{{ route('shop.faq') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.faqs') }}</a></li>
                        <li><a href="{{ route('shop.contact') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.contact_support') }}</a></li>
                        <li><a href="{{ route('shop.how-it-works') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.how_it_works') }}</a></li>
                        <li><a href="{{ route('shop.refund-policy') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.refund_policy') }}</a></li>
                        <li><a href="{{ route('shop.esim-guide') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.esim_installation_guide') }}</a></li>
                    </ul>
                </nav>

                <nav x-data="{ open: false }" aria-label="{{ __('footer.company') }}" class="{{ $footerCol }}">
                    <h3>
                        <button type="button" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="footer-col-company" class="{{ $footerColButton }}">
                            <span class="{{ $footerHeading }}">{{ __('footer.company') }}</span>
                            <svg class="{{ $footerColChevron }}" :class="open && '-rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </h3>
                    <ul id="footer-col-company" :class="{ 'hidden': ! open }" class="{{ $footerColList }}">
                        <li><a href="{{ route('shop.about') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.about_us') }}</a></li>
                        <li><a href="{{ route('shop.blog') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.blog') }}</a></li>
                        <li><a href="{{ route('shop.reviews') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.customer_reviews') }}</a></li>
                        <li><a href="{{ route('shop.earn-points') }}" wire:navigate class="{{ $footerLink }}">{{ __('common.invite_a_friend') }}</a></li>
                        <li><a href="{{ route('shop.affiliates') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.influencers_affiliates') }}</a></li>
                        <li><a href="{{ route('shop.press') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.press_media') }}</a></li>
                    </ul>
                </nav>

                <nav x-data="{ open: false }" aria-label="{{ __('footer.legal') }}" class="{{ $footerCol }}">
                    <h3>
                        <button type="button" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="footer-col-legal" class="{{ $footerColButton }}">
                            <span class="{{ $footerHeading }}">{{ __('footer.legal') }}</span>
                            <svg class="{{ $footerColChevron }}" :class="open && '-rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </h3>
                    <ul id="footer-col-legal" :class="{ 'hidden': ! open }" class="{{ $footerColList }}">
                        <li><a href="{{ route('shop.terms') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.terms_of_service') }}</a></li>
                        <li><a href="{{ route('shop.privacy') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.privacy_policy') }}</a></li>
                        <li><a href="{{ route('shop.cookie-policy') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.cookie_policy') }}</a></li>
                        <li><a href="{{ route('shop.compliance') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.compliance') }}</a></li>
                        <li><a href="{{ route('shop.security') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.security') }}</a></li>
                        <li><a href="{{ route('shop.accessibility') }}" wire:navigate class="{{ $footerLink }}">{{ __('footer.accessibility') }}</a></li>
                    </ul>
                </nav>

            </div>

            </div>{{-- /brand + link groups --}}

            {{-- Settings row: theme, language and currency, cookie settings,
                 version. self-start is for the stacked layout only: this is a
                 flex column on mobile, where a bare <button> stretches full width
                 and the browser centres its label. sm:self-auto hands alignment
                 back at the breakpoint. --}}
            <div class="mt-8 flex flex-col gap-4 text-sm text-zinc-600 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-6 sm:gap-y-3">
                <div class="flex items-center gap-3 self-start sm:self-auto">
                    <span class="text-sm font-semibold text-zinc-500" aria-hidden="true">{{ __('footer.theme') }}</span>
                    <x-theme-toggle variant="segmented" />
                </div>

                {{-- Locale pill. Same shape as the nav's, so the control reads
                     the same in both places. `.stop` is critical - without it the
                     click bubbles past the button, the backdrop appears directly
                     under the cursor, and the same click closes the modal again. --}}
                <button
                    type="button"
                    @click.stop="localeModalOpen = true"
                    class="flex shrink-0 items-center gap-2 self-start rounded-full px-3 py-1.5 text-sm text-zinc-900 ring-1 ring-zinc-200 transition-colors hover:bg-[#f4f6f5] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:text-zinc-200 dark:hover:bg-white/10 dark:hover:text-white sm:self-auto"
                >
                    <img
                        :src="'https://flagcdn.com/w40/' + (countryCode || 'us').toLowerCase() + '.png'"
                        alt=""
                        width="20"
                        height="20"
                        loading="lazy"
                        decoding="async"
                        class="h-5 w-5 shrink-0 rounded-full object-cover ring-1 ring-black/10"
                    >
                    {{-- The code is the active locale's own short code, as in the nav's
                         region pill. Slicing the English language name gave
                         "SP" for Spanish and "GE" for German. --}}
                    <span><span>{{ config('localization.supported.'.app()->getLocale().'.short') }}</span> /<span x-text="currency || 'USD'">USD</span></span>
                </button>

                {{-- Reopens the consent banner so the choice can actually be changed. --}}
                <button type="button" x-data @click="$dispatch('open-cookie-settings')" class="self-start sm:self-auto text-sm font-medium transition-colors hover:text-zinc-900">{{ __('footer.cookie_settings') }}</button>

                <p class="text-sm">Version 2.0.0</p>
            </div>

            {{-- Legal lines. No link bar above them any more: Terms and Privacy
                 already live in the Legal & Trust group. The year is written
                 out rather than read from the clock, per the project rule. --}}
            <div class="mt-8 space-y-2">
                <p class="text-sm text-zinc-600">&copy; 2026 {{ $siteName }}. {{ __('footer.all_rights_reserved') }}</p>
                <p class="max-w-3xl text-sm leading-relaxed text-zinc-500">
                    {{ __('footer.legal_line', $footerOperator) }} <a href="https://icrp.cac.gov.ng/public-search" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-600 hover:underline dark:text-blue-400">{{ __('footer.verify_company') }}</a>
                </p>
            </div>

        </div>
    </footer>
</section>
