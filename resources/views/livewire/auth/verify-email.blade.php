<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth.centered')] class extends Component {
    /**
     * Send an email verification notification to the user.
     */
    public function sendVerification(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);

            return;
        }

        Auth::user()->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="flex flex-col sm:flex-1">
    {{-- Centered form --}}
    <div class="mx-auto flex w-full max-w-sm flex-col py-3 sm:flex-1 sm:justify-center sm:py-6">

        <h1 class="flex items-center justify-center gap-2 text-2xl font-bold tracking-tight text-zinc-900">
            <span>Check your inbox</span>
            <svg class="h-7 w-7 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 9v.906a2.25 2.25 0 0 1-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 0 0 1.183 1.981l6.478 3.488m8.839 2.51-4.66-2.51m0 0-1.023-.55a2.25 2.25 0 0 0-2.134 0l-1.022.55m0 0-4.661 2.51m16.5 1.615a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V8.844a2.25 2.25 0 0 1 1.183-1.981l7.5-4.039a2.25 2.25 0 0 1 2.134 0l7.5 4.039a2.25 2.25 0 0 1 1.183 1.981V19.5Z" />
            </svg>
        </h1>
        <p class="mt-2 text-center text-sm text-zinc-600">
            We sent a verification link to
            <span class="font-medium text-zinc-700">{{ Auth::user()?->email }}</span>.
            Click the link inside to activate your account.
        </p>

        {{-- Success banner when link sent --}}
        @if (session('status') == 'verification-link-sent')
            <div class="mt-6 flex items-center justify-center gap-2.5 rounded-[12px] border border-emerald-200 bg-emerald-50 px-4 py-3">
                <svg class="h-5 w-5 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152A11.959 11.959 0 0 1 12 2.714Z" />
                </svg>
                <p class="text-center text-base text-emerald-800">A new verification link has been sent to your email address.</p>
            </div>
        @endif

        {{-- Actions --}}
        <div class="mt-4 flex flex-col gap-3">
            <button
                wire:click="sendVerification"
                type="button"
                class="flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                <span>Resend verification email</span>
            </button>

            <button
                wire:click="logout"
                type="button"
                class="text-center text-base font-medium text-zinc-600 underline-offset-4 transition-colors hover:text-zinc-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
            >
                Log out
            </button>
        </div>

        {{-- Security note --}}
        <div class="mt-5 flex items-center justify-center gap-1.5 text-sm text-zinc-600 sm:mt-8">
            <svg class="h-4 w-4 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152A11.959 11.959 0 0 1 12 2.714Z" />
            </svg>
            <span>Check your spam folder if the email doesn't arrive within a minute.</span>
        </div>

    </div>
</div>
