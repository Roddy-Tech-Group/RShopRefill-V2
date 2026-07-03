<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Page not found | {{ $siteName }}</title>
        <link rel="icon" href="{{ asset('assets/favicon.ico') }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-white dark:bg-[#0c1a36] text-zinc-900">

        {{-- Self-contained 404 — no app layout, so it renders even when the
             request never reached the storefront middleware/region resolution. --}}
        <main class="mx-auto flex min-h-screen max-w-md flex-col items-center justify-center px-6 py-12 text-center">
            <x-illo name="notFound" class="w-full max-w-sm" />

            <h1 class="mt-8 text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl">
                It seems like you missed your way
            </h1>
            <p class="mt-2 text-sm leading-relaxed text-zinc-600 sm:text-base">
                The page you are looking for does not exist or has been moved. Let us get you back on track.
            </p>

            <a
                href="{{ route('home') }}"
                class="mt-7 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75"/>
                </svg>
                Back to shop
            </a>
        </main>

    </body>
</html>
