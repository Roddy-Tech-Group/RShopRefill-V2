{{--
    Cookie consent, as a card anchored bottom-LEFT rather than a full-width bar
    across the viewport. The bar version took a strip out of every page on first
    visit and covered whatever sat at the bottom of the fold; a card asks the
    same question while leaving the page usable behind it.

    Left, not right: the floating FEEDBACK tab lives on the right edge, and a
    card there would sit on top of it.

    Shows on first visit until the customer accepts or declines; the choice is
    stored in localStorage so it does not reappear. Dark-mode safe (app.css
    repaints bg-white to navy; bg-blue-600 stays blue).
--}}
<div
    x-data="{ show: false }"
    x-init="$nextTick(() => { show = ! localStorage.getItem('cookie_consent') })"
    @open-cookie-settings.window="show = true"
    x-show="show"
    x-cloak
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-4"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-4"
    {{-- w-[calc(100%-2rem)] keeps the 16px gutter on a phone; max-w-sm stops it
         growing into a bar again on a desktop. The bottom offset clears the
         home indicator in the installed PWA, same as the old bar did. --}}
    class="fixed bottom-[max(1rem,env(safe-area-inset-bottom))] left-4 z-[70] w-[calc(100%-2rem)] max-w-sm rounded-[12px] border border-zinc-200 bg-white p-4 shadow-2xl shadow-zinc-900/20 sm:w-full"
    role="region"
    aria-label="{{ __('navigation.cookie_consent') }}"
>
    {{-- The sentence is one key so a translation can reorder it around the
         site name and the link. Both placeholders are escaped before they go
         in; only the anchor markup itself is raw. --}}
    <p class="text-sm leading-relaxed text-zinc-600">
        {!! __('navigation.cookie_body', [
            'site' => e($siteName),
            'policy' => '<a href="'.e(route('shop.cookie-policy')).'" wire:navigate class="font-medium text-blue-600 hover:underline">'.e(__('navigation.cookie_policy')).'</a>',
        ]) !!}
    </p>

    <div class="mt-4 flex items-center gap-3">
        <button
            type="button"
            @click="localStorage.setItem('cookie_consent', 'declined'); show = false"
            class="flex-1 rounded-[12px] border border-zinc-300 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-50"
        >
            {{ __('navigation.cookie_decline') }}
        </button>
        <button
            type="button"
            @click="localStorage.setItem('cookie_consent', 'accepted'); show = false; window.dispatchEvent(new Event('cookie-consent-accepted'))"
            class="flex-1 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700"
        >
            {{ __('navigation.cookie_accept') }}
        </button>
    </div>
</div>
