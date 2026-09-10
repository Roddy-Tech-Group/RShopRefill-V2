<x-layouts.app.header :title="$disableable ? 'Unsubscribed' : 'Email preferences'">
    <div class="flex min-h-screen items-center justify-center bg-zinc-50 py-12 px-4 sm:px-6 lg:px-8">
        <div class="w-full max-w-md space-y-8 rounded-2xl bg-white p-8 text-center shadow-xl">
            <div>
                @if ($disableable)
                    <svg class="mx-auto h-12 w-12 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <h2 class="mt-6 text-3xl font-extrabold text-zinc-900">You are unsubscribed</h2>
                    <p class="mt-2 text-sm text-zinc-600">
                        You will no longer receive {{ strtolower($label) }} emails from us. Emails about your account's security and your receipts still arrive, because you need those.
                    </p>
                @else
                    <svg class="mx-auto h-12 w-12 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    <h2 class="mt-6 text-3xl font-extrabold text-zinc-900">This email cannot be turned off</h2>
                    <p class="mt-2 text-sm text-zinc-600">
                        It concerns the security of your account or a payment you made, so it always arrives. Everything optional can be managed in your notification settings.
                    </p>
                @endif
            </div>
            <div class="mt-8 space-y-3">
                <a href="{{ $manageUrl }}" class="block text-sm font-medium text-blue-600 hover:text-blue-500">
                    {{ $disableable ? 'Changed your mind? Manage notification settings' : 'Manage notification settings' }}
                </a>
                <a href="{{ route('home') }}" class="block text-sm text-zinc-500 hover:text-zinc-700">Return home</a>
            </div>
        </div>
    </div>
</x-layouts.app.header>
