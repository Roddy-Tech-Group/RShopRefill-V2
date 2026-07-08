{{--
    Guest account claim page, reached via the signed link in the claim email.
    The guest sets a password + 4-digit transaction PIN; on success they are
    signed in and land on their orders (which include everything they bought
    as a guest, since those orders already belong to this account).
--}}
<x-layouts.app.header>

<div class="mx-auto w-full max-w-md px-4 py-12 sm:py-16">
    <div class="rounded-[12px] border border-zinc-200 bg-white p-6 shadow-sm sm:p-8 dark:border-[#24364f] dark:bg-[#1d3252]">
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Claim your account</h1>
        <p class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
            Set a password and a transaction PIN for <span class="font-semibold text-zinc-900 dark:text-white">{{ $user->email }}</span>.
            Your orders are already waiting inside.
        </p>

        <form method="POST" action="{{ $actionUrl }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="password" class="text-sm font-semibold text-zinc-900 dark:text-white">Password</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                    class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-[#24364f] dark:bg-[#0c1a36] dark:text-white">
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password_confirmation" class="text-sm font-semibold text-zinc-900 dark:text-white">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                    class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-[#24364f] dark:bg-[#0c1a36] dark:text-white">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="pin" class="text-sm font-semibold text-zinc-900 dark:text-white">Transaction PIN</label>
                    <input id="pin" name="pin" type="password" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="4 digits" autocomplete="off"
                        class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-sm tracking-widest text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-[#24364f] dark:bg-[#0c1a36] dark:text-white">
                    @error('pin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="pin_confirmation" class="text-sm font-semibold text-zinc-900 dark:text-white">Confirm PIN</label>
                    <input id="pin_confirmation" name="pin_confirmation" type="password" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="4 digits" autocomplete="off"
                        class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-sm tracking-widest text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-[#24364f] dark:bg-[#0c1a36] dark:text-white">
                </div>
            </div>
            <p class="text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">The PIN confirms wallet payments and other sensitive actions. Do not use obvious sequences like 1234.</p>

            <button type="submit"
                class="flex w-full items-center justify-center rounded-[12px] bg-blue-600 px-4 py-3 text-base font-bold text-white shadow-md transition-colors hover:bg-blue-700">
                Create my account
            </button>
        </form>
    </div>
</div>

</x-layouts.app.header>
