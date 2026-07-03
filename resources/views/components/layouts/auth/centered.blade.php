<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')

        {{-- Fonts: Satoshi only (from partials/head). The old Instrument Sans
             link was dropped - nothing on these pages referenced it. --}}

        {{-- Entrance: a bottom-sheet that slides up from the bottom edge on
             mobile (matching the eSIM package details modal), and a gentle rise
             on sm+ where the card is centered. --}}
        <style>
            @keyframes authSheetUp {
                from { transform: translateY(100%); opacity: 0; }
                to   { transform: translateY(0);    opacity: 1; }
            }
            @keyframes authCardRise {
                from { transform: translateY(28px) scale(0.98); opacity: 0; }
                to   { transform: translateY(0)    scale(1);    opacity: 1; }
            }
            .auth-slide-up {
                animation: authSheetUp 460ms cubic-bezier(0.22, 1, 0.36, 1);
                will-change: transform, opacity;
            }
            @media (min-width: 640px) {
                .auth-slide-up { animation-name: authCardRise; }
            }
        </style>
        <script>
            document.addEventListener('livewire:navigated', () => {
                document.querySelectorAll('.auth-slide-up').forEach((el) => {
                    el.classList.remove('auth-slide-up');
                    void el.offsetWidth;
                    el.classList.add('auth-slide-up');
                });
            });
        </script>
    </head>
    <body class="min-h-screen bg-zinc-100 text-zinc-900 antialiased">

        <div class="flex min-h-screen items-end justify-center p-0 sm:items-center sm:p-6 lg:p-[60px]">
            <div class="auth-slide-up w-full max-w-xl overflow-hidden rounded-t-3xl bg-white shadow-2xl shadow-zinc-900/20 ring-1 ring-zinc-900/5 sm:rounded-[12px] sm:shadow-xl sm:shadow-zinc-900/10 lg:shadow-2xl lg:shadow-zinc-900/15">

                <main class="relative flex flex-col bg-white px-6 py-[50px] sm:px-10 sm:py-10">
                    {{-- Brand (centered at the top) --}}
                    <a href="{{ route('home') }}" wire:navigate class="flex shrink-0 flex-col items-center rounded-[12px] group focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40">
                        <span class="flex h-10 items-center">
                            <img
                                src="{{ asset('assets/Rshoprefillslogo.webp') }}"
                                alt="{{ $siteName }}"
                                class="h-full w-auto object-contain transition-opacity duration-200 group-hover:opacity-90"
                            />
                        </span>
                        <span class="mt-0.5 text-[10px] font-medium leading-none text-zinc-600">Digital Marketplace</span>
                    </a>

                    {{ $slot }}
                </main>

            </div>
        </div>

        @fluxScripts
    </body>
</html>
