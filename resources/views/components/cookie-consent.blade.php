{{--
    Full-width cookie consent banner, fixed to the bottom. Shows on first visit
    until the customer accepts or declines; the choice is stored in localStorage so
    it does not reappear. Dark-mode safe (bg-white -> navy; bg-blue-600 stays blue).
--}}
<div
    x-data="{ show: false }"
    x-init="$nextTick(() => { show = ! localStorage.getItem('cookie_consent') })"
    x-show="show"
    x-cloak
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-full"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-full"
    class="fixed inset-x-0 bottom-0 z-[70] border-t border-zinc-200 bg-white shadow-2xl shadow-zinc-900/10"
    role="region"
    aria-label="Cookie consent"
>
    <div class="mx-auto flex w-full max-w-[1550px] flex-col items-start gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
        <p class="text-sm leading-relaxed text-zinc-600">
            We use cookies to keep you signed in, secure your payments and improve {{ $siteName }}.
            See our <a href="{{ route('shop.cookie-policy') }}" wire:navigate class="font-medium text-blue-600 hover:underline">Cookie Policy</a>.
        </p>
        <div class="flex w-full shrink-0 items-center gap-3 sm:w-auto">
            <button
                type="button"
                @click="localStorage.setItem('cookie_consent', 'declined'); show = false"
                class="flex-1 rounded-[12px] border border-zinc-300 bg-white px-5 py-2.5 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 sm:flex-none"
            >
                Decline
            </button>
            <button
                type="button"
                @click="localStorage.setItem('cookie_consent', 'accepted'); show = false"
                class="flex-1 rounded-[12px] bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700 sm:flex-none"
            >
                Accept cookies
            </button>
        </div>
    </div>
</div>
