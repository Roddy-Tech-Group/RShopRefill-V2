<x-layouts.auth.centered>
    <x-slot:title>Security Question</x-slot:title>

    <div class="flex flex-col sm:flex-1">
        {{-- Centered form --}}
        <div class="mx-auto flex w-full max-w-md flex-col py-3 sm:flex-1 sm:justify-center sm:py-6">

            {{-- Admin chip --}}
            <div class="flex justify-center">
                <span class="inline-flex items-center gap-1.5 rounded-[6px] bg-indigo-300 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-black dark:bg-white/10">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                    Identity Check
                </span>
            </div>

            {{-- Heading --}}
            <h1 class="mt-4 text-center text-3xl font-bold tracking-tight text-zinc-900">Security Question</h1>
            <p class="mt-2 text-center text-base text-zinc-600">
                Please answer the security question you configured for your account.
            </p>

            {{-- Question form --}}
            <form x-data="{ submitting: false }" @submit="submitting = true" method="POST" action="{{ route('admin.security-question.verify') }}" class="mt-6 flex flex-col gap-4 sm:mt-8">
                @csrf

                {{-- Security Question --}}
                <div class="rounded-[12px] bg-zinc-50 p-4 text-center">
                    <span class="block text-sm font-semibold text-zinc-500 uppercase tracking-wide">Question</span>
                    <span class="mt-1 block text-lg font-medium text-zinc-900">{{ $question }}</span>
                </div>

                {{-- Answer --}}
                <div x-data="{ show: false }">
                    <label for="security_answer" class="mb-1.5 block text-base font-medium text-zinc-700">Your Answer</label>
                    <div class="relative">
                        <input
                            id="security_answer"
                            name="security_answer"
                            :type="show ? 'text' : 'password'"
                            required
                            autofocus
                            placeholder="Enter your answer"
                            class="w-full rounded-[12px] border border-zinc-300 bg-white py-3 px-4 text-center text-lg text-zinc-900 placeholder:text-zinc-400 outline-none transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/15"
                        />
                        <button type="button" @click="show = !show" class="absolute right-4 top-1/2 -translate-y-1/2 text-zinc-500 transition-colors hover:text-zinc-700" :aria-label="show ? 'Hide answer' : 'Show answer'">
                            <svg x-show="!show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <svg x-show="show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                            </svg>
                        </button>
                    </div>
                    @error('security_answer') <p class="mt-1 text-center text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Submit --}}
                <button
                    type="submit"
                    x-bind:disabled="submitting"
                    class="mt-2 flex w-full items-center justify-center gap-2 rounded-[12px] bg-indigo-600 px-4 py-2.5 text-base font-semibold text-white shadow-lg shadow-indigo-600/25 transition-colors hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/50 disabled:opacity-75 disabled:cursor-not-allowed"
                >
                    <svg x-show="submitting" class="h-5 w-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" style="display: none;">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span x-text="submitting ? 'Verifying...' : 'Submit Answer'">Submit Answer</span>
                </button>
            </form>

            {{-- Back to login --}}
            <div class="mt-6 flex justify-center text-sm">
                <a href="{{ route('admin.login') }}" class="font-medium text-zinc-600 transition-colors hover:text-indigo-700">Cancel and return to login</a>
            </div>

        </div>
    </div>
</x-layouts.auth.centered>
