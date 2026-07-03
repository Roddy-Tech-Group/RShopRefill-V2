<x-layouts.auth.centered>
    <x-slot:title>Admin Login</x-slot:title>

    <div class="flex flex-col sm:flex-1">
        {{-- Centered form --}}
        <div class="mx-auto flex w-full max-w-md flex-col py-3 sm:flex-1 sm:justify-center sm:py-6">

            {{-- Admin chip --}}
            <div class="flex justify-center">
                <span class="inline-flex items-center gap-1.5 rounded-[6px] bg-blue-300 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-black">
                    <img src="{{ asset('assets/' . rawurlencode('admin access.svg')) }}" alt="" class="no-dark-invert h-3.5 w-3.5" loading="lazy">
                    Administrator
                </span>
            </div>

            {{-- Admin access illustration - kept small; the chip above already
                 carries the admin mark, so this is just a compact accent. --}}
            <div class="mt-3 flex justify-center">
                <img
                    src="{{ asset('assets/' . rawurlencode('admin access.svg')) }}"
                    alt=""
                    class="h-12 w-auto select-none object-contain sm:h-14"
                    loading="eager"
                    fetchpriority="high"
                />
            </div>

            {{-- Heading --}}
            <h1 class="mt-2 text-center text-2xl font-bold tracking-tight text-zinc-900">Admin Access</h1>
            <p class="mt-1 text-center text-sm text-zinc-600">Sign in to the {{ $siteName }} administration panel.</p>

            {{-- Status flash --}}
            <x-auth-session-status class="mt-4 text-center" :status="session('status')" />

            {{-- Email/password form --}}
            <form method="POST" action="{{ route('admin.login') }}" class="mt-6 flex flex-col gap-3 sm:mt-8 sm:gap-4">
                @csrf

                {{-- Email --}}
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-zinc-700">Admin Email</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/>
                            </svg>
                        </span>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            required
                            autofocus
                            autocomplete="email"
                            placeholder="Enter your admin email"
                            class="w-full rounded-[12px] border border-zinc-300 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                        />
                    </div>
                    @error('email') <p class="mt-1 text-center text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Password --}}
                <div x-data="{ show: false }">
                    <label for="password" class="mb-1.5 block text-sm font-medium text-zinc-700">Password</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
                            </svg>
                        </span>
                        <input
                            id="password"
                            name="password"
                            :type="show ? 'text' : 'password'"
                            required
                            autocomplete="current-password"
                            placeholder="Enter your password"
                            class="w-full rounded-[12px] border border-zinc-300 bg-white py-2.5 pl-10 pr-12 text-sm text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                        />
                        <button
                            type="button"
                            @click="show = !show"
                            class="absolute right-3 top-1/2 -translate-y-1/2 rounded text-zinc-600 hover:text-zinc-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                            :aria-label="show ? 'Hide password' : 'Show password'"
                        >
                            <svg x-show="!show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/>
                            </svg>
                            <svg x-show="show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 1 0 5.249 5.249"/>
                            </svg>
                        </button>
                    </div>
                    @error('password') <p class="mt-1 text-center text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Remember me --}}
                <div class="flex items-center">
                    <label class="flex cursor-pointer select-none items-center gap-2">
                        <input name="remember" type="checkbox" class="h-4 w-4 rounded border-zinc-300 text-blue-600 focus:ring-blue-500/30" />
                        <span class="text-sm text-zinc-700">Keep me signed in on this device</span>
                    </label>
                </div>

                {{-- Submit --}}
                <button
                    type="submit"
                    class="mt-2 flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
                >
                    <span>Sign in to Admin</span>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
                    </svg>
                </button>
            </form>

            {{-- Security / audit note --}}
            <div class="mt-6 flex items-center justify-center gap-1.5 text-sm text-zinc-600 sm:mt-8">
                <svg class="h-4 w-4 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152A11.959 11.959 0 0 1 12 2.714Z"/>
                </svg>
                <span>Protected administrative area. All sign-ins are logged.</span>
            </div>

            {{-- Back to customer login --}}
            <div class="mt-3 flex justify-center text-sm">
                <a href="{{ route('login') }}" wire:navigate class="font-medium text-zinc-600 transition-colors hover:text-blue-700">Not an admin? Sign in to your customer account</a>
            </div>

        </div>
    </div>
</x-layouts.auth.centered>
