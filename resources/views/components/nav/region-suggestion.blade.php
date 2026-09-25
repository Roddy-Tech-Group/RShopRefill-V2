{{--
    Region suggestion: "you are seeing US products, want your own country's?"

    Sits under the header's region pill and does exactly one thing - offer to
    open the locale modal with a country pre-selected. It does NOT change the
    region itself. That stays with the modal, which owns country, language and
    currency together and is the only place any of them is saved. A popup that
    set the region on its own would be a second way to do it, and the two would
    drift.

    Rendered only when there is something to say: App\Support\RegionSuggestion
    returns null for a missing or sentinel CF-IPCountry, an unsupported code,
    or a visitor already on their own region. So the markup is simply absent
    rather than present-and-hidden, which is also why it cannot shift the
    layout - it is absolutely positioned under a relatively positioned anchor.

    Dismissal is checked SERVER-side too, not only in JS. Rendering it and then
    hiding it on boot would flash it at someone who already said no.
--}}
@props(['region' => 'US'])

@php
    $suggestion = \App\Support\RegionSuggestion::forRegion($region);

    // The 30-day "no thanks". Checked here so a dismissed popup never reaches
    // the browser at all.
    $dismissed = request()->cookie('region_suggest_dismissed') !== null;
@endphp

@if ($suggestion && ! $dismissed)
    <div
        x-data="{
            open: false,

            init() {
                /* A cookie check again on the client: wire:navigate swaps the
                   body without a fresh request, so a dismissal made on the
                   previous page would otherwise not be known here. */
                if (this.dismissed()) { return; }

                /* 800ms after load, so it arrives once the page has settled
                   rather than competing with it. */
                setTimeout(() => { this.open = true; }, 800);
            },

            dismissed() {
                return document.cookie.split('; ').some((c) => c.startsWith('region_suggest_dismissed='));
            },

            /* Every exit writes the cookie. Saying no by clicking away is
               still saying no, and asking again on the next page would be
               the same as not having asked. */
            remember() {
                document.cookie = 'region_suggest_dismissed=1; path=/; max-age=' + (60 * 60 * 24 * 30) + '; samesite=lax';
            },

            close() {
                this.open = false;
                this.remember();
            },

            /* Hands off to the modal that already exists. The suggested
               country rides along; the modal still owns confirming and saving
               it, along with language and currency. */
            switchRegion() {
                this.open = false;
                this.remember();
                window.dispatchEvent(new CustomEvent('open-locale-modal', {
                    detail: { country: @js($suggestion['iso']) },
                }));
            },
        }"
        x-on:keydown.escape.window="if (open) close()"
        class="absolute right-0 top-full z-[60] pt-3"
    >
        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            x-on:click.outside="if (open) close()"
            role="dialog"
            aria-modal="false"
            aria-labelledby="region-suggest-title"
            class="relative w-[min(92vw,420px)] rounded-[14px] bg-[#0c1a36] p-4 text-white shadow-2xl shadow-zinc-900/30 dark:bg-[var(--ash)]"
        >
            {{-- The pointer. Drawn as a rotated square behind the panel's top
                 edge so it inherits the same fill, rather than a second colour
                 to keep in step. --}}
            <span
                aria-hidden="true"
                class="absolute -top-1.5 right-6 h-3 w-3 rotate-45 rounded-[2px] bg-[#0c1a36] dark:bg-[var(--ash)]"
            ></span>

            <button
                type="button"
                x-on:click="close()"
                class="absolute right-2.5 top-2.5 flex h-7 w-7 items-center justify-center rounded-full text-white/70 transition-colors hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
                aria-label="{{ __('navigation.region_suggest_dismiss') }}"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>

            <p id="region-suggest-title" class="pr-8 text-[13px] leading-relaxed text-white/80">
                {{ __('navigation.region_suggest_body', ['country' => $suggestion['current']]) }}
            </p>

            <div class="mt-3 flex items-center gap-3">
                {{-- The detected country, shown as a control so it reads as the
                     thing being offered rather than as a label. It is not a
                     picker: choosing a different country is the modal's job,
                     which is where this button leads. --}}
                <button
                    type="button"
                    x-on:click="switchRegion()"
                    class="flex min-w-0 flex-1 items-center gap-2.5 rounded-full bg-white/10 px-3 py-2 text-left transition-colors hover:bg-white/15 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
                >
                    <img
                        src="https://flagcdn.com/w40/{{ strtolower($suggestion['iso']) }}.png"
                        alt=""
                        class="h-5 w-5 shrink-0 rounded-full object-cover ring-1 ring-white/20"
                        loading="lazy"
                        width="20"
                        height="20"
                        decoding="async"
                    >
                    <span class="truncate text-sm font-semibold text-white">{{ $suggestion['name'] }}</span>
                </button>

                <button
                    type="button"
                    x-on:click="switchRegion()"
                    class="shrink-0 rounded-full bg-[#ffffff] px-4 py-2 text-sm font-bold text-[#0c1a36] transition-colors hover:bg-[#f4f6f5] focus:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
                >
                    {{ __('navigation.region_suggest_switch') }}
                </button>
            </div>
        </div>
    </div>
@endif
