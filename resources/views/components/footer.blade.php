{{-- Storefront footer. Used inside the <x-layouts.app.header> shell. --}}
<footer class="bg-[#eff6ff] dark:bg-[#0c1a36] text-zinc-900">
    <div class="mx-auto max-w-[1550px] px-4 sm:px-6 lg:px-8 py-12 lg:py-16">

        {{-- Top: brand + link columns --}}
        <div class="grid grid-cols-2 gap-x-6 gap-y-10 lg:grid-cols-12 lg:gap-10">

            {{-- Brand column --}}
            <div class="col-span-2 lg:col-span-4">
                <a href="{{ route('home') }}" wire:navigate class="-ml-3 flex flex-col rounded-[12px] group focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40">
                    <span class="flex h-12 items-center">
                        <img
                            src="{{ asset('assets/Rshoprefillslogo.webp') }}"
                            alt="{{ $siteName }}"
                            class="h-full w-auto object-contain transition-opacity duration-200 group-hover:opacity-90"
                        />
                    </span>
                    <span class="mt-1 pl-1 text-[11px] font-medium leading-none text-zinc-600">Digital Marketplace</span>
                </a>

                <p class="mt-6 max-w-sm text-base leading-relaxed text-zinc-600">
                    Making global digital services accessible to everyone, and direct brand shopping with zero payment failure while keeping your connected with family and friends world wide
                </p>

                {{-- Social links --}}
                <div class="mt-6 flex flex-wrap items-center gap-2">
                    <a href="{{ \App\Models\SiteSetting::get('social.facebook', 'https://facebook.com/rshoprefills') }}" target="_blank" rel="noopener noreferrer" aria-label="Visit our Facebook page" class="flex h-10 w-10 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-700 transition-colors hover:bg-zinc-200 hover:text-zinc-900">
                        <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="currentColor" aria-hidden="true">
                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                        </svg>
                    </a>
                    <a href="{{ \App\Models\SiteSetting::get('social.x', 'https://x.com/rshoprefills') }}" target="_blank" rel="noopener noreferrer" aria-label="Follow us on X" class="flex h-10 w-10 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-700 transition-colors hover:bg-zinc-200 hover:text-zinc-900">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="currentColor" aria-hidden="true">
                            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/>
                        </svg>
                    </a>
                    <a href="{{ \App\Models\SiteSetting::get('social.tiktok', 'https://tiktok.com/@rshoprefills') }}" target="_blank" rel="noopener noreferrer" aria-label="Follow us on TikTok" class="flex h-10 w-10 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-700 transition-colors hover:bg-zinc-200 hover:text-zinc-900">
                        <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="currentColor" aria-hidden="true">
                            <path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>
                        </svg>
                    </a>
                    <a href="{{ \App\Models\SiteSetting::get('social.instagram', 'https://instagram.com/rshoprefills') }}" target="_blank" rel="noopener noreferrer" aria-label="Follow us on Instagram" class="flex h-10 w-10 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-700 transition-colors hover:bg-zinc-200 hover:text-zinc-900">
                        <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="currentColor" aria-hidden="true">
                            <path d="M12 2.163c3.204 0 3.584.012 4.849.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.07 1.644.07 4.849 0 3.205-.012 3.584-.07 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.849.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>
                        </svg>
                    </a>
                </div>

                {{-- Get the app --}}
                <p class="mt-8 text-xs font-semibold uppercase tracking-wider text-zinc-500">Get the app</p>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <a href="{{ route('shop.mobile-app') }}" wire:navigate aria-label="Download on the App Store (in development)" class="inline-flex items-center gap-2 rounded-[8px] px-4 py-2 text-white transition-opacity hover:opacity-90" style="background-color: #18181b;">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>
                        <span class="text-left leading-none">
                            <span class="block text-[10px]">Get it on</span>
                            <span class="block text-sm font-semibold">App Store</span>
                        </span>
                    </a>
                    <a href="{{ route('shop.mobile-app') }}" wire:navigate aria-label="Get it on Google Play (in development)" class="inline-flex items-center gap-2 rounded-[8px] px-4 py-2 text-white transition-opacity hover:opacity-90" style="background-color: #18181b;">
                        <img src="{{ asset('assets/'.rawurlencode('Playstore 2.webp')) }}" alt="" width="24" height="24" class="block h-6 w-6 shrink-0 object-contain" loading="lazy">
                        <span class="text-left leading-tight">
                            <span class="block text-[10px]">Download on</span>
                            <span class="block text-sm font-semibold">Google Play</span>
                        </span>
                    </a>
                </div>
            </div>

            {{-- Popular --}}
            <nav class="lg:col-span-2" aria-label="Popular">
                <h3 class="text-base font-semibold text-zinc-900">Popular</h3>
                <ul class="mt-4 space-y-2.5 text-base">
                    <li><a href="{{ route('shop.brand', 'amazon') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Amazon</a></li>
                    <li><a href="{{ route('shop.brand', 'apple') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Apple</a></li>
                    <li><a href="{{ route('shop.brand', 'steam') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Steam</a></li>
                    <li><a href="{{ route('shop.brand', 'playstation') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">PlayStation</a></li>
                    <li><a href="{{ route('shop.brand', 'xbox') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Xbox</a></li>
                    <li><a href="{{ route('shop.brand', 'netflix') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Netflix</a></li>
                    <li><a href="{{ route('shop.gift-cards') }}" wire:navigate class="font-medium text-blue-600 transition-colors hover:text-blue-700">View all gift cards</a></li>
                    <li><a href="{{ route('shop.esim.country', 'US') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">United States eSIM</a></li>
                    <li><a href="{{ route('shop.esim.country', 'GB') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">United Kingdom eSIM</a></li>
                    <li><a href="{{ route('shop.esims') }}" wire:navigate class="font-medium text-blue-600 transition-colors hover:text-blue-700">View all eSIMs</a></li>
                </ul>
            </nav>

            {{-- Help --}}
            <nav class="lg:col-span-2" aria-label="Help">
                <h3 class="text-base font-semibold text-zinc-900">Help</h3>
                <ul class="mt-4 space-y-2.5 text-base">
                    <li><a href="{{ route('shop.help') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Help center</a></li>
                    <li><a href="{{ route('shop.faq') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">FAQs</a></li>
                    <li><a href="{{ route('shop.contact') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Contact us</a></li>
                    <li><a href="{{ route('shop.how-it-works') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">How it works</a></li>
                    <li><a href="{{ route('shop.earn-points') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Earn points</a></li>
                    <li><a href="{{ route('dashboard.orders') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Order status</a></li>
                </ul>
            </nav>

            {{-- Company --}}
            <nav class="lg:col-span-2" aria-label="Company">
                <h3 class="text-base font-semibold text-zinc-900">Company</h3>
                <ul class="mt-4 space-y-2.5 text-base">
                    <li><a href="{{ route('shop.about') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">About us</a></li>
                    <li><a href="{{ route('shop.blog') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Blog</a></li>
                    <li><a href="{{ route('shop.reviews') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Reviews</a></li>
                    <li><a href="{{ route('shop.press') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Press &amp; Media</a></li>
                    <li>
                        <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center gap-2 text-zinc-600 transition-colors hover:text-zinc-900">
                            <span>WebApp Active</span>
                            <span class="shrink-0 rounded-[12px]" style="display: inline-block; width: 0.5rem; height: 0.5rem; background-color: #22c55e;"></span>
                        </a>
                    </li>
                </ul>
            </nav>

            {{-- Legal --}}
            <nav class="lg:col-span-2" aria-label="Legal">
                <h3 class="text-base font-semibold text-zinc-900">Legal</h3>
                <ul class="mt-4 space-y-2.5 text-base">
                    <li><a href="{{ route('shop.privacy') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Privacy</a></li>
                    <li><a href="{{ route('shop.terms') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Terms of Service</a></li>
                    <li><a href="{{ route('shop.cookie-policy') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Cookie Policy</a></li>
                    <li><a href="{{ route('shop.compliance') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Compliance</a></li>
                    <li><a href="{{ route('shop.refund-policy') }}" wire:navigate class="text-zinc-600 transition-colors hover:text-zinc-900">Refund policy</a></li>
                </ul>
            </nav>

        </div>

        {{-- Utility row: theme picker, version, cookie settings --}}
        <div class="mt-10 flex flex-col gap-4 text-sm text-zinc-600 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-6 sm:gap-y-3">
            {{-- Segmented theme picker - shared component, segmented variant.
                 `self-start` keeps it from stretching full-width inside the
                 flex-column parent on mobile. --}}
            <x-theme-toggle variant="segmented" class="self-start" />

            <span class="hidden h-4 w-px bg-zinc-200 sm:inline-block" aria-hidden="true"></span>

            <p class="text-xs">Version 2.0.0</p>

            <a href="{{ route('shop.cookie-policy') }}#manage" class="text-xs font-medium transition-colors hover:text-zinc-900">Cookie settings</a>
        </div>

        {{-- Divider --}}
        <div class="my-6 h-px bg-zinc-200" aria-hidden="true"></div>

        {{-- Bottom bar --}}
        <div class="flex flex-col gap-4 text-sm text-zinc-600 sm:flex-row sm:items-center sm:justify-between">
            <p class="max-w-xl">&copy; {{ date('Y') }} {{ $siteName }} All rights reserved. {{ $siteName }} is a wholly-owned product of Roddy Technologies LTD, RC - 9385422 <a href="https://icrp.cac.gov.ng/public-search" target="_blank" rel="noopener noreferrer" class="font-medium text-blue-600 hover:underline dark:text-blue-400">Verify</a>.</p>

            <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                {{-- Locale chip: opens the same modal the nav uses. `.stop` is
                     critical here - without it the click bubbles up past the
                     button, the backdrop appears at z-40 directly under the
                     cursor, and the same click's mouseup/click then lands on
                     the backdrop's `@click="localeModalOpen = false"` and
                     immediately closes the modal again. --}}
                <button type="button" @click.stop="localeModalOpen = true" class="flex items-center gap-2 transition-colors hover:text-zinc-900 focus:outline-none focus-visible:text-zinc-900">
                    <img :src="'https://flagcdn.com/w40/' + (countryCode || 'us').toLowerCase() + '.png'" alt="" class="h-3.5 w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200">
                    <span x-text="country">United States</span>
                    <span class="text-zinc-600" aria-hidden="true">/</span>
                    <img src="{{ asset('assets/' . rawurlencode('global svg.svg')) }}" alt="" class="h-3.5 w-3.5 shrink-0 opacity-70">
                    <span x-text="language">English</span>
                </button>
                <a href="{{ route('shop.sitemap') }}" wire:navigate class="transition-colors hover:text-zinc-900">Sitemap</a>
                <a href="{{ route('shop.accessibility') }}" wire:navigate class="transition-colors hover:text-zinc-900">Accessibility</a>
            </div>
        </div>

    </div>
</footer>
