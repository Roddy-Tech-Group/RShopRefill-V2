<x-layouts.app.header title="Thank You">
    <div class="flex min-h-screen items-center justify-center bg-zinc-50 py-12 px-4 sm:px-6 lg:px-8">
        <div class="w-full max-w-md space-y-8 bg-white p-8 rounded-2xl shadow-xl text-center">
            <div>
                <svg class="mx-auto h-12 w-12 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <h2 class="mt-6 text-3xl font-extrabold text-zinc-900">
                    Thank You
                </h2>
                <p class="mt-2 text-sm text-zinc-600">
                    We appreciate your feedback. It helps us improve our services for everyone.
                </p>
            </div>
            <div class="mt-8">
                <a href="{{ route('home') }}" class="text-sm font-medium text-blue-600 hover:text-blue-500">
                    Return Home
                </a>
            </div>
        </div>
    </div>
</x-layouts.app.header>
