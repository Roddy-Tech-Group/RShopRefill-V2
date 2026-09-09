<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    <title>{{ $alreadyVerified ? 'Email already verified' : 'Email verified' }} · {{ config('app.name') }}</title>
</head>
<body class="min-h-screen bg-[#eff6ff] text-zinc-900 antialiased dark:bg-[#0c1a36] dark:text-zinc-100">

<div class="relative flex min-h-screen items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
    <div class="relative z-10 w-full max-w-md">
        <div class="overflow-hidden rounded-[12px] border border-emerald-200 bg-white p-8 shadow-sm dark:border-emerald-500/20 dark:bg-[#122343] sm:p-10">

            {{-- Check icon --}}
            <div class="mb-8 flex justify-center">
                <div class="flex h-20 w-20 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                    <svg class="h-10 w-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>

            <div class="text-center">
                <h1 class="text-2xl font-extrabold tracking-tight text-zinc-900 dark:text-white">
                    {{ $alreadyVerified ? 'Already verified' : 'Email verified' }}
                </h1>
                <p class="mt-4 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
                    @if ($alreadyVerified)
                        This address was verified earlier. There is nothing more to do here.
                    @else
                        Thanks for confirming. Your account is now fully set up.
                    @endif
                </p>
            </div>

            {{-- Verified address --}}
            <div class="relative mt-8 overflow-hidden rounded-[12px] border border-zinc-200 bg-zinc-50 p-5 dark:border-white/10 dark:bg-[#0c1a36]">
                <div class="absolute left-0 top-0 h-full w-1 bg-emerald-500"></div>
                <p class="text-center text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Verified address</p>
                <div class="mt-2 flex items-center justify-center rounded-[12px] bg-white px-4 py-3 shadow-sm dark:bg-[#122343]">
                    <p class="break-all text-center text-base font-semibold text-zinc-900 dark:text-white">{{ $email }}</p>
                </div>
            </div>

            <p class="mt-8 text-center text-lg font-semibold text-zinc-900 dark:text-white">
                You can close this tab.
            </p>
            <p class="mt-2 text-center text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                Head back to the device where you signed up and carry on. If this is the phone you will shop from, sign in here whenever you are ready.
            </p>

            <div class="mt-8 flex justify-center">
                <a href="{{ route('login') }}" class="flex items-center gap-2 rounded-[12px] bg-zinc-100 px-6 py-2.5 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">
                    Sign in on this device
                </a>
            </div>
        </div>

        <p class="mt-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
            Did not sign up for {{ config('app.name') }}? You can ignore this and nothing will be created in your name.
        </p>
    </div>
</div>

</body>
</html>
